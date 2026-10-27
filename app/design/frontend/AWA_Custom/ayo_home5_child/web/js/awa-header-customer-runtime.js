define(['Magento_Customer/js/customer-data'], function (customerData) {
    'use strict';

    let HEADER_MINICART_BUTTON_SELECTOR = '[data-awa-header-minicart-shell="true"] .action.showcart, .awa-header-minicart[data-awa-header-cart="true"] .action.showcart';
    let HEADER_MINICART_COUNTER_SELECTOR = '[data-awa-header-minicart-shell="true"] .minicart-wrapper .counter.qty, .awa-header-minicart[data-awa-header-cart="true"] .minicart-wrapper .counter.qty';
    let HEADER_MINICART_QTY_SELECTOR = '[data-awa-header-minicart-shell="true"] .minicart-wrapper .counter.qty .counter-number, [data-awa-header-minicart-shell="true"] .minicart-wrapper .counter.qty, .awa-header-minicart[data-awa-header-cart="true"] .minicart-wrapper .counter.qty .counter-number, .awa-header-minicart[data-awa-header-cart="true"] .minicart-wrapper .counter.qty';

    return function initHeaderCustomerRuntime() {
        if (window.__awaHeaderCustomerRuntimeInit) {
            return;
        }

        window.__awaHeaderCustomerRuntimeInit = true;
        let hoverOpenTimer = null;
        let hoverCloseTimer = null;
        let lastCartQty = null;
        let cartLiveRegion = null;

        function isLoggedIn(data) {
            if (!data || typeof data !== 'object') {
                return false;
            }

            return !!(
                data.firstname
                || data.fullname
                || data.email
                || data.id
                || data.entity_id
                || data.websiteId !== undefined
            );
        }








    // Auditoria 2026-10-23 (Escape no minicart): o bootstrap do minicart nao
    // carrega fora da home; este runtime roda em todas as paginas. Fecha o
    // dropdown e devolve o foco ao gatilho quando o foco esta dentro do dialogo.
    function initMinicartEscapeReturn() {
        if (window.__awaMinicartEsc) { return; }
        window.__awaMinicartEsc = true;
        window.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' && event.keyCode !== 27) { return; }
            var dialogEl = document.querySelector('.block-minicart, #minicart-content-wrapper');
            if (!dialogEl) { return; }
            var rect = dialogEl.getBoundingClientRect();
            if (rect.height === 0 || rect.width === 0) { return; }
            var wrapper = dialogEl.closest('.minicart-wrapper, [data-block="minicart"]') || dialogEl.parentElement;
            var active = document.activeElement;
            if (!active || !wrapper || !wrapper.contains(active)) { return; }
            event.preventDefault();
            var dialog = wrapper.querySelector('[data-role="dropdownDialog"]');
            if (dialog && window.jQuery) {
                var $d = window.jQuery(dialog);
                if ($d.data && $d.data('mageDropdownDialog')) {
                    try { $d.dropdownDialog('close'); } catch (e) { /* ja fechado */ }
                }
            }
            var trigger = wrapper.querySelector('.showcart');
            if (trigger) {
                var refocus = function () {
                    try { trigger.focus({ preventScroll: true }); } catch (e) { trigger.focus(); }
                };
                window.setTimeout(refocus, 150);
                window.setTimeout(refocus, 500);
            }
        }, true);
    }
    initMinicartEscapeReturn();


    // Pedido do dono 2026-10-23: "aproveita máximo espaço dos botões" — o CSS do
    // site usa @layer (importants em layer vencem os sem layer), então a regra CSS
    // era inócua. Aplicamos inline !important nos cards de produto (inline
    // important vence qualquer folha): botão 100% + zera recuos dos wrappers.
    function initCardsFullWidthButtons() {
        if (window.__awaCardsFullWidth) { return; }
        window.__awaCardsFullWidth = true;
        var apply = function () {
            var cards = document.querySelectorAll('.products-grid .item-product, .products-grid .product-item, .awa-shelf .item-product, .content-top-home .item-product, .item-product.awa-carousel-card-slot');
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var btn = card.querySelector('.action.tocart, button.tocart, .btn-add-to-cart');
                if (!btn) { continue; }
                var wrappers = [card.querySelector('.product-info-cart'), card.querySelector('.actions-primary'), btn.closest('form')];
                for (var w = 0; w < wrappers.length; w++) {
                    var el = wrappers[w];
                    if (!el) { continue; }
                    el.style.setProperty('width', '100%', 'important');
                    el.style.setProperty('max-width', 'none', 'important');
                    el.style.setProperty('padding-inline', '0', 'important');
                    el.style.setProperty('margin-inline', '0', 'important');
                    el.style.setProperty('box-sizing', 'border-box', 'important');
                }
                btn.style.setProperty('width', '100%', 'important');
                btn.style.setProperty('max-width', 'none', 'important');
                btn.style.setProperty('justify-content', 'center', 'important');
                btn.style.setProperty('box-sizing', 'border-box', 'important');
            }
        };
        apply();
        // grids de catálogo carregam por AJAX: reaplica quando houver mutações (throttle).
        var t = null;
        var mo = new MutationObserver(function () {
            if (t) { return; }
            t = window.setTimeout(function () { t = null; apply(); }, 250);
        });
        var targets = document.querySelectorAll('.products-grid, .columns, .column.main, .content-top-home');
        for (var k = 0; k < targets.length; k++) { mo.observe(targets[k], { childList: true, subtree: true }); }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', apply); }
    }
    initCardsFullWidthButtons();

        function showPromoBarForGuest() {
            var promo = document.getElementById('awa-b2b-promo-bar')
                || document.querySelector('.awa-b2b-promo-bar');
            if (!promo) { return; }
            if (promo.getAttribute('data-awa-promo-hidden') === '1') { return; }
            if (document.documentElement.classList.contains('awa-b2b-promo-dismissed')) { return; }
            promo.classList.add('awa-b2b-promo-visible');
            promo.style.setProperty('display', 'flex', 'important');
            promo.style.removeProperty('visibility');
            promo.style.removeProperty('height');
            promo.style.removeProperty('max-height');
            promo.style.removeProperty('overflow');
            promo.removeAttribute('aria-hidden');
            promo.removeAttribute('inert');
            document.documentElement.classList.add('awa-auth-resolved-guest');
        }

        function hidePromoBarForLoggedIn() {
            let promo = document.getElementById('awa-b2b-promo-bar')
                || document.querySelector('.awa-b2b-promo-bar');

            document.body.classList.add('awa-customer-known');
            document.body.classList.add('customer-logged-in');

            if (!promo) {
                return;
            }

            // AUDIT30-2026-07-16: cart critical forçava display:flex; marca + hide inline
            promo.setAttribute('data-awa-promo-hidden', '1');
            promo.style.setProperty('display', 'none', 'important');
            promo.style.setProperty('visibility', 'hidden', 'important');
            promo.style.setProperty('height', '0', 'important');
            promo.style.setProperty('max-height', '0', 'important');
            promo.style.setProperty('overflow', 'hidden', 'important');
            promo.setAttribute('aria-hidden', 'true');
            promo.setAttribute('inert', '');

            // lockPromoRow last tick 3200ms / MutationObserver disconnect 3500ms.
            // customer-data often arrives later; inline 160px on .awa-site-header then sticks.
            var shell = promo.closest('#header.header-container')
                || document.getElementById('header');
            if (shell) {
                shell.style.removeProperty('display');
                if (shell.getAttribute('aria-hidden') === 'true') {
                    shell.removeAttribute('aria-hidden');
                }
                shell.removeAttribute('inert');
                shell.classList.add('awa-b2b-promo-shell--collapsed');
                shell.style.setProperty('height', '0px', 'important');
                shell.style.setProperty('min-height', '0px', 'important');
                shell.style.setProperty('max-height', '0px', 'important');
                var content = shell.querySelector(':scope > .header-content');
                if (content) {
                    content.style.setProperty('height', '0px', 'important');
                    content.style.setProperty('min-height', '0px', 'important');
                    content.style.setProperty('max-height', '0px', 'important');
                }
            }
            document.documentElement.classList.add('awa-b2b-promo-dismissed');
            var site = document.querySelector('.page-wrapper .awa-site-header');
            var b2bKnown = !!document.querySelector('.page-wrapper .awa-site-header .b2b-status-panel');
            var isDesktop = !(window.matchMedia) || window.matchMedia('(min-width: 992px)').matches;
            if (site && isDesktop) {
                if (b2bKnown) {
                    site.style.setProperty('height', 'auto', 'important');
                    site.style.setProperty('min-height', '0px', 'important');
                    site.style.setProperty('max-height', 'none', 'important');
                    site.style.setProperty('--awa-header-promo-h', '0px', 'important');
                    site.style.setProperty('--awa-header-stack-h', 'auto', 'important');
                } else {
                    site.style.setProperty('height', '116px', 'important');
                    site.style.setProperty('min-height', '116px', 'important');
                    site.style.setProperty('max-height', '116px', 'important');
                    site.style.setProperty('--awa-header-promo-h', '0px', 'important');
                    site.style.setProperty('--awa-header-stack-h', '116px', 'important');
                }
            }

            if (typeof window.__awaQueueHeaderLock === 'function') {
                window.__awaQueueHeaderLock();
            }

        }

        function updateRightCol(data) {
            let accountNav = document.querySelector('[data-awa-account-nav]');
            let rightCol = document.querySelector('[data-awa-header-right]');
            let customerLoggedIn = isLoggedIn(data);

            if (customerLoggedIn) {
                hidePromoBarForLoggedIn();
                if (accountNav) {
                    accountNav.style.removeProperty('display');
                    accountNav.removeAttribute('hidden');
                    accountNav.classList.remove('awa-force-hidden');
                    accountNav.setAttribute('data-awa-auth-state', 'customer');
                    accountNav.setAttribute('aria-hidden', 'false');
                }
                if (rightCol) {
                    rightCol.classList.add('awa-header-right--logged');
                }
                return;
            }

            if (accountNav) {
                accountNav.style.setProperty('display', 'none', 'important');
                accountNav.classList.add('awa-force-hidden');
                accountNav.setAttribute('hidden', 'hidden');
                accountNav.setAttribute('data-awa-auth-state', 'guest');
                accountNav.setAttribute('aria-hidden', 'true');
            }
            if (rightCol) {
                rightCol.classList.remove('awa-header-right--logged');
            }
        }

        function resolveCustomerLabel(data) {
            if (!data || typeof data !== 'object') {
                return '';
            }

            return String(
                data.firstname
                || data.fullname
                || data.name
                || data.email
                || ''
            ).trim();
        }

        function hasB2bStatusPanel() {
            return !!document.querySelector('.b2b-status-panel');
        }

        function hidePromptForB2bPanel(prompt) {
            if (!prompt) {
                return;
            }

            prompt.style.setProperty('display', 'none', 'important');
            prompt.style.setProperty('visibility', 'hidden', 'important');
            prompt.style.setProperty('pointer-events', 'none', 'important');
            prompt.setAttribute('aria-hidden', 'true');
        }

        function syncAccountPromptFallback(data) {
            let prompt = document.querySelector('.awa-header-account-prompt');
            let guest;
            let customer;
            let customerLine1;
            let logged;
            let label;
            let shortLabel;

            if (!prompt) {
                return;
            }

            if (hasB2bStatusPanel()) {
                hidePromptForB2bPanel(prompt);
                return;
            }

            logged = isLoggedIn(data);
            label = resolveCustomerLabel(data);
            shortLabel = label.length > 20 ? (label.slice(0, 20) + '...') : label;
            guest = prompt.querySelector('.awa-header-account-prompt__guest');
            customer = prompt.querySelector('.awa-header-account-prompt__customer');
            customerLine1 = prompt.querySelector('.awa-header-account-prompt__customer .awa-header-account-prompt__line1');

            prompt.setAttribute('data-awa-auth-state', logged ? 'customer' : 'guest');
            if (!logged) { showPromoBarForGuest(); }
            prompt.setAttribute('data-awa-auth-settled', '1');
            prompt.classList.toggle('awa-header-account-prompt--revealed', logged);
            prompt.classList.toggle('awa-header-account-prompt--long-name', label.length > 16);

            if (window.innerWidth >= 992) {
                if (logged) {
                    /*
                     * Some legacy desktop compact rules hide the account prompt globally.
                     * Force visibility for authenticated users so Dashboard/Logout remain reachable.
                     */
                    prompt.removeAttribute('hidden');
                    prompt.setAttribute('aria-hidden', 'false');
                    prompt.style.removeProperty('display');
                    prompt.style.removeProperty('visibility');
                    prompt.style.removeProperty('pointer-events');
                } else {
                    prompt.style.removeProperty('display');
                    prompt.style.removeProperty('visibility');
                    prompt.style.removeProperty('pointer-events');
                }
            } else {
                prompt.style.removeProperty('display');
                prompt.style.removeProperty('visibility');
                prompt.style.removeProperty('pointer-events');
            }

            if (guest) {
                if (logged) {
                    guest.hidden = true;
                    guest.setAttribute('aria-hidden', 'true');
                    guest.style.removeProperty('display');
                } else {
                    guest.hidden = false;
                    guest.setAttribute('aria-hidden', 'false');
                    guest.style.removeProperty('display');
                }
            }

            if (customer) {
                if (logged) {
                    customer.hidden = false;
                    customer.setAttribute('aria-hidden', 'false');
                    customer.style.removeProperty('display');
                } else {
                    customer.hidden = true;
                    customer.setAttribute('aria-hidden', 'true');
                    customer.style.removeProperty('display');
                }
            }

            if (logged && customerLine1) {
                customerLine1.textContent = shortLabel ? ('Olá, ' + shortLabel + '!') : 'Olá!';
            }
        }

        function updateGreeting(data) {
            let label = resolveCustomerLabel(data);
            let nameNode = document.querySelector('.customer-welcome .customer-name');
            let switchNode = document.querySelector('.customer-welcome .action.switch');

            if (!nameNode && !switchNode) {
                return;
            }

            // 20 chars keeps desktop header from breaking while preserving recognition.
            let truncated = label.length > 20 ? (label.slice(0, 20) + '...') : label;
            let isLong = label.length > 16;
            let rightCol = document.querySelector('[data-awa-header-right]');

            if (rightCol) {
                rightCol.classList.toggle('awa-account-name-long', isLong);
            }

            if (switchNode && label) {
                // Avoid persistent browser tooltips in the header.
                switchNode.removeAttribute('title');
                switchNode.setAttribute('aria-label', 'Conta de ' + label);
            }

            if (nameNode && label) {
                nameNode.textContent = truncated;
                nameNode.removeAttribute('title');
            }
        }

        function enhanceSearchExperience() {
            let input = document.querySelector('#search');
            let form = document.querySelector('#search_mini_form');

            if (!input || !form || form.dataset.awaEnhancedSearch === '1') {
                return;
            }

            form.dataset.awaEnhancedSearch = '1';

            function isSkuLike(value) {
                let v = String(value || '').trim();
                // Common SKU-like patterns: uppercase letters/numbers with dashes/underscores.
                return /^[A-Z0-9][A-Z0-9_-]{2,31}$/i.test(v) && /[0-9]/.test(v);
            }

            function syncSkuState() {
                let active = isSkuLike(input.value);
                form.classList.toggle('awa-search-sku-mode', active);
                form.classList.toggle('awa-search-priority-sku', active);
                input.setAttribute('aria-label', active
                    ? 'Buscar por código SKU'
                    : 'Buscar produtos, marcas ou categorias');
            }

            input.addEventListener('input', syncSkuState);
            input.addEventListener('blur', syncSkuState);
            form.addEventListener('submit', function () {
                syncSkuState();
            });
            syncSkuState();
        }

        function setupSearchShortcuts() {
            if (window.__awaHeaderSearchShortcutInit) {
                return;
            }
            window.__awaHeaderSearchShortcutInit = true;

            document.addEventListener('keydown', function (event) {
                let input = document.querySelector('#search');
                if (!input) {
                    return;
                }

                let target = event.target;
                let tag = target && target.tagName ? target.tagName.toLowerCase() : '';
                let typingInField = tag === 'input' || tag === 'textarea' || target.isContentEditable;

                // "/" to focus search when user is not typing in another field.
                if (event.key === '/' && !typingInField && !event.ctrlKey && !event.metaKey && !event.altKey) {
                    event.preventDefault();
                    input.focus();
                    input.select();
                    return;
                }

                // Ctrl/Cmd + K opens search focus.
                if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
                    event.preventDefault();
                    input.focus();
                    input.select();
                }
            });
        }

        function setupMinicartFeedback() {
            if (window.__awaHeaderMinicartFeedbackInit) {
                return;
            }

            // Carrinho/checkout: minicart oculto ou redundante — observer no contador travava a página.
            if (document.body && (
                document.body.classList.contains('checkout-cart-index') ||
                document.body.classList.contains('checkout-index-index') ||
                document.body.classList.contains('rokanthemes-onepagecheckout') ||
                document.body.classList.contains('onepagecheckout-index-index')
            )) {
                return;
            }

            window.__awaHeaderMinicartFeedbackInit = true;

            function getQty() {
                let qtyNode = document.querySelector(HEADER_MINICART_QTY_SELECTOR);
                if (!qtyNode) {
                    return 0;
                }
                let value = parseInt(String(qtyNode.textContent || '').replace(/\D+/g, ''), 10);
                return Number.isFinite(value) ? value : 0;
            }

            function formatCartBadgeLabel(qty) {
                return qty > 99 ? '99+' : String(qty);
            }

            function syncCartBadgeLabel(qty) {
                let counter = document.querySelector(HEADER_MINICART_COUNTER_SELECTOR);
                if (!counter) {
                    return;
                }

                let label = formatCartBadgeLabel(qty);
                let nextAriaLabel = qty > 99
                    ? 'Carrinho com mais de 99 itens'
                    : ('Carrinho com ' + qty + (qty === 1 ? ' item' : ' itens'));

                // Guard obrigatório: setAttribute incondicional dispara o MutationObserver
                // (attributes:true) deste mesmo counter, criando loop infinito.
                if (counter.getAttribute('aria-label') !== nextAriaLabel) {
                    counter.setAttribute('aria-label', nextAriaLabel);
                }

                counter.querySelectorAll('.total-mini-cart-item, .counter-number').forEach(function (node) {
                    if (String(node.textContent || '').trim() !== label) {
                        node.textContent = label;
                    }
                });
            }

            function pulseIfChanged() {
                let qty = getQty();
                let button = document.querySelector(HEADER_MINICART_BUTTON_SELECTOR);
                if (!button) {
                    return;
                }

                syncCartBadgeLabel(qty);

                if (lastCartQty === null) {
                    lastCartQty = qty;
                    return;
                }

                if (qty !== lastCartQty) {
                    button.classList.remove('awa-cart-bump');
                    // reflow to restart animation
                    void button.offsetWidth; // eslint-disable-line no-void
                    button.classList.add('awa-cart-bump');
                    if (cartLiveRegion) {
                        cartLiveRegion.textContent = qty > lastCartQty
                            ? 'Item adicionado ao carrinho. Total: ' + qty
                            : 'Carrinho atualizado. Total: ' + qty;
                    }
                    window.setTimeout(function () {
                        button.classList.remove('awa-cart-bump');
                    }, 500);
                }

                lastCartQty = qty;
            }

            function bindCounterObserver() {
                if (typeof MutationObserver !== 'function') {
                    return false;
                }
                let counter = document.querySelector(HEADER_MINICART_COUNTER_SELECTOR);
                if (!counter) {
                    return false;
                }

                let observer = new MutationObserver(function () {
                    // Avoid work when tab is backgrounded.
                    if (document.hidden) {
                        return;
                    }
                    pulseIfChanged();
                });
                observer.observe(counter, {
                    subtree: true,
                    childList: true,
                    characterData: true,
                    attributes: true
                });
                return true;
            }

            // Prefer observer; fallback to lightweight polling if minicart node is not ready yet.
            if (!bindCounterObserver()) {
                window.setInterval(function () {
                    if (!document.hidden) {
                        pulseIfChanged();
                    }
                }, 1200);
            }
            pulseIfChanged();
        }

        function applyCartLiveRegionSrOnly(region) {
            if (!region) {
                return;
            }
            /* Classe utilitária + inline: .awa-sr-live sozinho não tem regra CSS
             * (só .awa-header-account-prompt__live.awa-sr-live). Sem isso o anúncio
             * "Item adicionado ao carrinho" vaza visualmente no body. */
            region.classList.add('awa-sr-only', 'awa-sr-live');
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
            region.style.cssText = 'position:fixed!important;top:0!important;left:0!important;'
                + 'width:1px!important;height:1px!important;min-width:1px!important;'
                + 'min-height:1px!important;max-width:1px!important;max-height:1px!important;'
                + 'padding:0!important;margin:0!important;overflow:hidden!important;'
                + 'clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;'
                + 'white-space:nowrap!important;border:0!important;font-size:0!important;'
                + 'line-height:0!important;contain:strict!important;';
        }

        function ensureCartLiveRegion() {
            if (cartLiveRegion) {
                applyCartLiveRegionSrOnly(cartLiveRegion);
                return;
            }
            let existing = document.getElementById('awa-cart-live-region');
            if (existing) {
                applyCartLiveRegionSrOnly(existing);
                cartLiveRegion = existing;
                return;
            }
            let region = document.createElement('div');
            region.id = 'awa-cart-live-region';
            applyCartLiveRegionSrOnly(region);
            document.body.appendChild(region);
            cartLiveRegion = region;
        }

        function setAccountExpanded(expanded) {
            let switchNode = document.querySelector('.customer-welcome .action.switch');
            if (!switchNode) {
                return;
            }
            switchNode.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        function getAccountTrigger() {
            return document.querySelector('.customer-welcome .action.switch');
        }

        function getAccountMenu() {
            return document.querySelector('.customer-welcome .customer-menu');
        }

        function isAccountMenuOpen() {
            let wrap = document.querySelector('.customer-welcome');
            let menu = getAccountMenu();
            if (!menu) {
                return false;
            }

            return (wrap && wrap.classList.contains('is-open'))
                || menu.classList.contains('active')
                || menu.style.display === 'block';
        }

        function getAccountMenuFocusable(menu) {
            if (!menu) {
                return [];
            }

            return Array.prototype.slice.call(
                menu.querySelectorAll(
                    'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
                )
            ).filter(function (el) {
                return el.offsetParent !== null;
            });
        }

        function focusFirstAccountMenuItem() {
            let menu = getAccountMenu();
            let items = getAccountMenuFocusable(menu);

            if (!items.length) {
                return;
            }

            window.requestAnimationFrame(function () {
                items[0].focus();
            });
        }

        function openAccountMenu(shouldFocusFirstItem) {
            let wrap = document.querySelector('.customer-welcome');
            let switchNode = getAccountTrigger();
            let menu = getAccountMenu();

            if (wrap) {
                wrap.classList.add('is-open');
            }

            if (menu) {
                menu.classList.add('active');
                menu.style.setProperty('display', 'block');
                menu.setAttribute('aria-hidden', 'false');
            }

            if (switchNode) {
                switchNode.classList.add('active');
            }

            setAccountExpanded(true);

            if (shouldFocusFirstItem) {
                focusFirstAccountMenuItem();
            }
        }

        function closeAccountMenu(keepFocusOnTrigger) {
            let wrap = document.querySelector('.customer-welcome');
            let switchNode = getAccountTrigger();
            let menu = getAccountMenu();

            if (wrap) {
                wrap.classList.remove('is-open');
            }

            if (menu) {
                menu.classList.remove('active');
                menu.style.removeProperty('display');
                menu.setAttribute('aria-hidden', 'true');
            }

            if (switchNode) {
                switchNode.classList.remove('active');
                if (keepFocusOnTrigger) {
                    switchNode.focus();
                }
            }

            setAccountExpanded(false);
        }

        function syncAccountAriaState() {
            let wrap = document.querySelector('.customer-welcome');
            let menu = getAccountMenu();
            if (!menu) {
                return;
            }

            let open = isAccountMenuOpen();
            setAccountExpanded(open);
            menu.setAttribute('aria-hidden', open ? 'false' : 'true');
            if (wrap) {
                wrap.classList.toggle('is-open', open);
            }
        }

        function setupAccountMenuA11y() {
            if (window.__awaHeaderAccountA11yInit) {
                return;
            }
            window.__awaHeaderAccountA11yInit = true;

            document.addEventListener('click', function (event) {
                let trigger = getAccountTrigger();
                let menu = getAccountMenu();

                if (!trigger || !menu) {
                    return;
                }

                let target = event.target;
                let insideTrigger = trigger.contains(target);
                let insideMenu = menu.contains(target);

                if (insideTrigger) {
                    event.preventDefault();
                    if (isAccountMenuOpen()) {
                        closeAccountMenu(true);
                    } else {
                        openAccountMenu(false);
                    }
                    return;
                }

                if (!insideMenu) {
                    closeAccountMenu(false);
                }
            }, true);

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }
                closeAccountMenu(true);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Tab') {
                    return;
                }

                let menu = getAccountMenu();
                if (!menu || !isAccountMenuOpen()) {
                    return;
                }

                let items = getAccountMenuFocusable(menu);
                if (!items.length) {
                    return;
                }

                let first = items[0];
                let last = items[items.length - 1];
                let active = document.activeElement;

                if (!event.shiftKey && active === last) {
                    event.preventDefault();
                    first.focus();
                } else if (event.shiftKey && active === first) {
                    event.preventDefault();
                    last.focus();
                }
            });

            document.addEventListener('keydown', function (event) {
                let menu = getAccountMenu();
                if (!menu || !isAccountMenuOpen()) {
                    return;
                }

                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
                    return;
                }

                let items = getAccountMenuFocusable(menu);
                if (!items.length) {
                    return;
                }

                event.preventDefault();
                let active = document.activeElement;
                let index = items.indexOf(active);

                if (index === -1) {
                    items[0].focus();
                    return;
                }

                if (event.key === 'ArrowDown') {
                    items[(index + 1) % items.length].focus();
                    return;
                }

                items[(index - 1 + items.length) % items.length].focus();
            });

            document.addEventListener('keydown', function (event) {
                let menu = getAccountMenu();
                if (!menu || !isAccountMenuOpen()) {
                    return;
                }

                let items = getAccountMenuFocusable(menu);
                if (!items.length) {
                    return;
                }

                if (event.key === 'Home') {
                    event.preventDefault();
                    items[0].focus();
                    return;
                }

                if (event.key === 'End') {
                    event.preventDefault();
                    items[items.length - 1].focus();
                }
            });

            window.addEventListener('resize', function () {
                if (window.innerWidth < 992) {
                    closeAccountMenu(false);
                }
            });

            window.addEventListener('scroll', function () {
                if (isAccountMenuOpen()) {
                    closeAccountMenu(false);
                }
            }, { passive: true });

            // Desktop hover support with delay, controlled by same JS state.
            document.addEventListener('mouseover', function (event) {
                if (window.innerWidth < 992) {
                    return;
                }
                let wrap = document.querySelector('.customer-welcome');
                if (!wrap || !wrap.contains(event.target)) {
                    return;
                }
                window.clearTimeout(hoverCloseTimer);
                hoverOpenTimer = window.setTimeout(function () {
                    openAccountMenu(false);
                }, 100);
            });

            document.addEventListener('mouseout', function (event) {
                if (window.innerWidth < 992) {
                    return;
                }
                let wrap = document.querySelector('.customer-welcome');
                if (!wrap || !wrap.contains(event.target)) {
                    return;
                }
                let related = event.relatedTarget;
                if (related && wrap.contains(related)) {
                    return;
                }
                window.clearTimeout(hoverOpenTimer);
                hoverCloseTimer = window.setTimeout(function () {
                    closeAccountMenu(false);
                }, 130);
            });

            // Canonical state is controlled by this runtime; avoid observer churn/flicker.
        }

        function normalizeAccountMenuInitialState() {
            let menu = getAccountMenu();
            if (!menu) {
                return;
            }
            menu.setAttribute('aria-hidden', 'true');
            menu.style.removeProperty('display');
            closeAccountMenu(false);
        }

        function improveAccountA11y() {
            let trigger = getAccountTrigger();
            let menu = getAccountMenu();
            if (!trigger || !menu) {
                return;
            }

            if (!menu.id) {
                menu.id = 'awa-account-dropdown-menu';
            }
            trigger.setAttribute('aria-controls', menu.id);
            trigger.setAttribute('aria-haspopup', 'menu');
            menu.setAttribute('role', 'menu');
        }

        function ensureQuickActionsContainer(menu) {
            let existing = menu.querySelector('.awa-account-quick-actions');
            if (existing) {
                return existing;
            }

            let wrap = document.createElement('div');
            wrap.className = 'awa-account-quick-actions';
            menu.insertBefore(wrap, menu.firstChild);
            return wrap;
        }

        function createQuickAction(href, label, mod) {
            let link = document.createElement('a');
            link.className = 'awa-account-quick-actions__item ' + mod;
            link.href = href;
            link.textContent = label;
            if (window.location && window.location.pathname) {
                let currentPath = window.location.pathname.replace(/\/+$/, '');
                let targetPath = String(href).replace(/\/+$/, '');
                if (currentPath === targetPath) {
                    link.setAttribute('aria-current', 'page');
                }
            }
            return link;
        }

        function updateAccountQuickActions(data) {
            let menu = getAccountMenu();
            if (!menu) {
                return;
            }

            let logged = isLoggedIn(data);
            let container = ensureQuickActionsContainer(menu);
            container.innerHTML = '';

            if (!logged) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'grid';
            container.appendChild(createQuickAction('/b2b/account/dashboard', 'Dashboard', 'is-dashboard'));
            container.appendChild(createQuickAction('/checkout/cart', 'Carrinho', 'is-cart'));
        }

        function ensureMobileQuickActions(data) {
            let rightCol = document.querySelector('[data-awa-header-right]');
            if (!rightCol) {
                return;
            }
            let logged = isLoggedIn(data);
            let host = document.querySelector('.awa-header-mobile-quick-actions');
            if (!host) {
                host = document.createElement('div');
                host.className = 'awa-header-mobile-quick-actions';
                rightCol.appendChild(host);
            }
            host.innerHTML = '';
            if (!logged) {
                host.style.display = 'none';
                return;
            }
            host.style.display = 'none';  // nenhum item a exibir
        }

        function updateMcpDashboardLink(data) {
            let link = document.getElementById('awa-mcp-dashboard-link');
            if (!link) {
                return;
            }

            // Keep dashboard helper link out of header; dashboard remains in dropdown menu.
            link.hidden = true;
            link.setAttribute('aria-hidden', 'true');
            link.style.setProperty('display', 'none', 'important');
            link.classList.remove('is-visible');
        }

        function syncCustomerUi(data) {
            var accountPrompt = document.querySelector('.awa-header-account-prompt');

            updateRightCol(data);
            syncAccountPromptFallback(data);
            updateMcpDashboardLink(data);
            if (!accountPrompt) {
                updateGreeting(data);
            }
            updateAccountQuickActions(data);
            ensureMobileQuickActions(data);
            syncAccountAriaState();
        }

        let customer = customerData.get('customer');
        setupAccountMenuA11y();
        normalizeAccountMenuInitialState();
        improveAccountA11y();
        setupSearchShortcuts();
        enhanceSearchExperience();
        ensureCartLiveRegion();
        setupMinicartFeedback();
        // FPC guest HTML + customer section async: cookie/localStorage podem
        // indicar logado antes do customer-data resolver — esconde promo já.
        try {
            let cookieMatch = document.cookie.match(/(?:^|;\s*)customer_logged_in=1(?:;|$)/);
            let cacheRaw = window.localStorage ? window.localStorage.getItem('mage-cache-storage') : null;
            let cacheObj = cacheRaw ? JSON.parse(cacheRaw) : null;
            let cachedCustomer = cacheObj && cacheObj.customer ? cacheObj.customer : null;
            let cacheLogged = isLoggedIn(cachedCustomer);
            if (cookieMatch || cacheLogged || hasB2bStatusPanel()) {
                hidePromoBarForLoggedIn();
            }
        } catch (e) {
            /* ignore storage parse errors */
        }
        syncCustomerUi(customer());
        customer.subscribe(syncCustomerUi);
    };
});
