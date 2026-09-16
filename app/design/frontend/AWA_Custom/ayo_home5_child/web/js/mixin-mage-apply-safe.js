/**
 * AWA — mixin seguro para mage/apply/main.
 *
 * Evita TypeError quando um módulo AMD retorna objeto sem a chave do componente
 * (ex.: loader/loaderAjax antes do widget registrar, ou alias RequireJS ausente).
 */
define([
    'underscore',
    'jquery',
    'mage/apply/scripts'
], function (_, $, processScripts) {
    'use strict';

    var dataAttr = 'data-mage-init',
        nodeSelector = '[' + dataAttr + ']';

    /**
     * @param {HTMLElement} el
     * @param {Object|String} config
     * @param {String} component
     */
    function isCmsHome() {
        return !!(document.body && (
            document.body.classList.contains('cms-index-index') ||
            document.body.classList.contains('cms-home') ||
            document.body.classList.contains('cms-homepage_ayo_home5')
        ));
    }

    function isHeroNode(el) {
        return !!(el && el.closest && el.closest(
            '.wrapper_slider, .awa-hero-swiper, .awa-hero-slider, .awa-hero'
        ));
    }

    function shouldSkipHomeShelfComponent(component, el) {
        var name;
        if (!isCmsHome()) {
            return false;
        }
        name = String(component || '');
        if (/AiAssistant|ai-assistant/i.test(name)) {
            return !window.__awaHomePaintDeferredReady;
        }
        /* r4d: Magento_Ui messages/knockout app, pageCache block render, captcha */
        if (/Magento_Ui\/js\/core\/app|pageCache|Magento_PageCache\/js\/page-cache|Magento_Captcha\/|\bcaptcha\b/i.test(name)) {
            return !window.__awaHomePaintDeferredReady;
        }
        if (!/(owl|swiper|tab-carousel|superdeals|rokanthemes-owl|owl-carousel-init)/i.test(name)) {
            return false;
        }
        if (isHeroNode(el)) {
            return !window.__awaHomePaintDeferredReady;
        }
        return true;
    }

    function safeInit(el, config, component) {
        if (shouldSkipHomeShelfComponent(component, el)) {
            return;
        }
        require([component], function (fn) {
            var $el;
            var bound;
            var widgetName = component;

            if (fn !== null && fn !== undefined && typeof fn === 'object') {
                bound = fn[component];

                if (!bound && widgetName.indexOf('/') !== -1) {
                    widgetName = widgetName.split('/').pop();
                    bound = fn[widgetName];
                }

                if (typeof bound === 'function') {
                    fn = bound.bind(fn);
                } else {
                    fn = null;
                }
            }

            if (_.isFunction(fn)) {
                bound = fn.bind(null, config, el);
                setTimeout(bound);
                return;
            }

            $el = $(el);

            if (widgetName.indexOf('/') !== -1) {
                widgetName = widgetName.split('/').pop();
            }

            if (typeof $el[widgetName] === 'function') {
                bound = $el[widgetName].bind($el, config);
                setTimeout(bound);
            }
        }, function (error) {
            if ('console' in window && typeof window.console.error === 'function') {
                console.error(error);
            }

            return true;
        });
    }

    /**
     * @param {HTMLElement} el
     * @returns {Object}
     */
    function getData(el) {
        var data = el.getAttribute(dataAttr);

        el.removeAttribute(dataAttr);

        return {
            el: el,
            data: JSON.parse(data)
        };
    }

    return function (target) {
        target.apply = function (context) {
            var virtuals = processScripts(!context ? document : context),
                nodes = document.querySelectorAll(nodeSelector);

            _.toArray(nodes)
                .map(getData)
                .concat(virtuals)
                .forEach(function (itemContainer) {
                    var element = itemContainer.el;

                    _.each(itemContainer.data, function (obj, key) {
                        if (obj.mixins) {
                            require(obj.mixins, function () {
                                var i, len, mixinResult;

                                for (i = 0, len = arguments.length; i < len; i++) {
                                    if (typeof arguments[i] !== 'function') {
                                        continue;
                                    }

                                    mixinResult = arguments[i](itemContainer.data[key], element);

                                    if (mixinResult && typeof mixinResult === 'object') {
                                        $.extend(
                                            true,
                                            itemContainer.data[key],
                                            mixinResult
                                        );
                                    }
                                }

                                delete obj.mixins;
                                safeInit(element, obj, key);
                            });
                        } else {
                            safeInit(element, obj, key);
                        }
                    });
                });
        };

        target.applyFor = safeInit;

        return target;
    };
});
