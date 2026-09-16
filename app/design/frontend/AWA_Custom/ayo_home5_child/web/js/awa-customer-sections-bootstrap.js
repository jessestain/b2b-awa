/**
 * Home: inicializa section-config + customer-data após interação no header ou 2,5s.
 * window.__awaCustomerSectionsReady — Promise para o header aguardar antes do runtime.
 */
define(function () {
    'use strict';

    function safeSameOriginUrl(url) {
        if (!url || typeof url !== 'string') {
            return '';
        }
        if (url.charAt(0) === '/' && url.charAt(1) !== '/') {
            return url;
        }
        try {
            var parsed = new URL(url, document.baseURI);
            if (parsed.origin === window.location.origin) {
                return parsed.href;
            }
        } catch (e) {
            return '';
        }
        return '';
    }

    function sectionValue(customerData, name) {
        var source;

        if (!customerData || typeof customerData.get !== 'function') {
            return {};
        }
        source = customerData.get(name);
        return typeof source === 'function' ? source() : source;
    }

    function isPrivateCustomer(customerData) {
        var customer = sectionValue(customerData, 'customer') || {};
        var b2b = sectionValue(customerData, 'b2b_panel') || {};

        return !!(customer.firstname || customer.fullname || customer.websiteId || b2b.is_b2b || b2b.company_name);
    }

    function hydrateHomeRecentOrders(customerData) {
        var root = document.querySelector('[data-awa-home-recent-hydrate="1"]');
        var list;
        var empty;
        var emptyGuest;
        var emptyLogged;
        var link;
        var source;
        var customerSource;
        var b2bSource;

        if (!root || root.getAttribute('data-awa-home-recent-hydrated') === '1') {
            return;
        }

        list = root.querySelector('[data-awa-home-recent-list]');
        empty = root.querySelector('[data-awa-home-recent-empty]');
        emptyGuest = root.querySelector('[data-awa-home-recent-empty-guest]');
        emptyLogged = root.querySelector('[data-awa-home-recent-empty-logged]');
        link = root.querySelector('[data-awa-home-recent-orders-link]');
        if (!list || !empty || typeof customerData.get !== 'function') {
            return;
        }

        function showEmpty(loggedIn) {
            var pending = root.querySelector('[data-awa-home-recent-pending]');
            if (pending) {
                pending.hidden = true;
            }
            list.hidden = true;
            empty.hidden = false;
            if (emptyGuest) {
                emptyGuest.hidden = !!loggedIn;
            }
            if (emptyLogged) {
                emptyLogged.hidden = !loggedIn;
            }
            if (link) {
                link.hidden = true;
            }
            root.setAttribute('data-awa-home-recent-state', loggedIn ? 'logged_empty' : 'guest');
        }

        function render() {
            var data = sectionValue(customerData, 'last-ordered-items') || {};
            var items = Array.isArray(data.items) ? data.items : [];
            var loggedIn = isPrivateCustomer(customerData) || items.length > 0;

            list.textContent = '';
            if (!items.length) {
                showEmpty(loggedIn);
                return;
            }

            items.forEach(function (item) {
                var li = document.createElement('li');
                var name = item && item.name != null ? String(item.name) : '';
                var href = safeSameOriginUrl(item && item.url);

                li.className = 'awa-home-recent-orders__item';
                if (href) {
                    var anchor = document.createElement('a');
                    anchor.className = 'awa-home-recent-orders__item-link';
                    anchor.href = href;
                    anchor.textContent = name;
                    li.appendChild(anchor);
                } else {
                    var span = document.createElement('span');
                    span.className = 'awa-home-recent-orders__item-name';
                    span.textContent = name;
                    li.appendChild(span);
                }
                list.appendChild(li);
            });

            var pending = root.querySelector('[data-awa-home-recent-pending]');
            if (pending) {
                pending.hidden = true;
            }
            list.hidden = false;
            empty.hidden = true;
            if (link) {
                link.hidden = false;
            }
            root.setAttribute('data-awa-home-recent-state', 'has_items');
        }

        source = customerData.get('last-ordered-items');
        customerSource = customerData.get('customer');
        b2bSource = customerData.get('b2b_panel');
        render();
        if (source && typeof source.subscribe === 'function') {
            source.subscribe(render);
        }
        if (customerSource && typeof customerSource.subscribe === 'function') {
            customerSource.subscribe(render);
        }
        if (b2bSource && typeof b2bSource.subscribe === 'function') {
            b2bSource.subscribe(render);
        }
        root.setAttribute('data-awa-home-recent-hydrated', '1');
    }

    function execute(payload) {
        return new Promise(function (resolve) {
            require([
                'Magento_Customer/js/section-config',
                'Magento_Customer/js/customer-data'
            ], function (sectionConfigModule, customerDataModule) {
                sectionConfigModule['Magento_Customer/js/section-config'](payload.section);
                customerDataModule['Magento_Customer/js/customer-data'](payload.customer);
                customerDataModule.getInitCustomerData().done(function () {
                    hydrateHomeRecentOrders(customerDataModule);
                    resolve();
                });
            });
        });
    }

    return function bootstrapCustomerSections(payload, options) {
        options = options || {};

        if (!payload || !payload.section || !payload.customer) {
            return Promise.resolve();
        }

        if (window.__awaCustomerSectionsReady) {
            return window.__awaCustomerSectionsReady;
        }

        if (window.__awaCustomerSectionsBootstrapStarted) {
            return window.__awaCustomerSectionsReady || Promise.resolve();
        }

        window.__awaCustomerSectionsBootstrapStarted = true;

        if (options.immediate) {
            window.__awaCustomerSectionsReady = execute(payload);
            return window.__awaCustomerSectionsReady;
        }

        var headerSelectors = [
            '.minicart-wrapper',
            '.awa-header-account-prompt',
            '#search',
            '#search_mini_form',
            '[data-awa-header-right]'
        ];

        if (document.body.classList.contains('catalog-category-view')
            || document.body.classList.contains('catalogsearch-result-index')) {
            headerSelectors.push(
                'form[data-role=tocart-form]',
                'button.tocart',
                '.action.tocart',
                '[data-role=quickview-button]'
            );
        }

        if (document.body.classList.contains('catalog-product-view')) {
            headerSelectors.push(
                '#product_addtocart_form',
                '.box-tocart',
                '#product-addtocart-button',
                'button.action.tocart',
                '.swatch-option',
                'select.super-attribute-select',
                '[id^="super_attribute"]',
                '#awa-pdp-sticky-add',
                '.awa-pdp-sticky-bar',
                '.fotorama__stage'
            );
        }

        window.__awaCustomerSectionsReady = new Promise(function (resolve) {
            var settled = false;

            var intentEvents = ['pointerdown', 'touchstart', 'keydown'];

            function isMeaningfulIntent(evt) {
                if (!evt) {
                    return false;
                }

                if (evt.type === 'keydown') {
                    return evt.key === 'Enter' || evt.key === ' ' || evt.key === 'Spacebar';
                }

                return true;
            }

            function go() {
                if (settled) {
                    return;
                }
                settled = true;
                intentEvents.forEach(function (evtName) {
                    window.removeEventListener(evtName, onIntent);
                });
                execute(payload).then(resolve);
            }

            function onIntent(evt) {
                if (!isMeaningfulIntent(evt)) {
                    return;
                }

                var target = evt && evt.target;
                if (!target || !target.closest) {
                    return;
                }
                var hit = headerSelectors.some(function (sel) {
                    return target.closest(sel);
                });
                if (hit) {
                    go();
                }
            }

            intentEvents.forEach(function (evtName) {
                window.addEventListener(evtName, onIntent, { passive: true });
            });

            var sectionsDelay = document.cookie.indexOf('private_content_version') !== -1 ? 4500 : 7000;
            window.setTimeout(go, sectionsDelay);
        });

        return window.__awaCustomerSectionsReady;
    };
});
