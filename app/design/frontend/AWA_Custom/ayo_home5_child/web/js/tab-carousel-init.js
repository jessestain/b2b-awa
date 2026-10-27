/* global define, window, setTimeout, clearTimeout */
define([
    'jquery'
], function ($) {
    'use strict';

    function debounce(fn, wait) {
        let timer;

        return function () {
            let context = this;
            let args = arguments;

            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, wait || 120);
        };
    }

    function runOnNextFrame(callback) {
        if (window && typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(callback);
            return;
        }

        setTimeout(callback, 16);
    }

    function handleKeyboardTabs(key, currentTab, moveToTab, activateAndNotify) {
        if (key === 13 || key === 32) {
            activateAndNotify($(currentTab));
            return true;
        }

        if (key === 37) {
            moveToTab(currentTab, 'prev');
            return true;
        }

        if (key === 39) {
            moveToTab(currentTab, 'next');
            return true;
        }

        if (key === 36) {
            moveToTab(currentTab, 'first');
            return true;
        }

        if (key === 35) {
            moveToTab(currentTab, 'last');
            return true;
        }

        return false;
    }

    function getTargetTabIndex(direction, currentIndex, totalTabs) {
        if (direction === 'first') {
            return 0;
        }

        if (direction === 'last') {
            return totalTabs - 1;
        }

        if (direction === 'next') {
            return (currentIndex + 1) % totalTabs;
        }

        return (currentIndex - 1 + totalTabs) % totalTabs;
    }

    function moveToTab($scope, tabsSelector, currentTab, direction, activateAndNotify) {
        var $allTabs = $scope.find(tabsSelector);
        let currentIndex = $allTabs.index(currentTab);
        let targetIndex;
        var $next;

        if (!$allTabs.length || currentIndex < 0) {
            return;
        }

        targetIndex = getTargetTabIndex(direction, currentIndex, $allTabs.length);
        $next = $allTabs.eq(targetIndex);

        if ($next.length) {
            $next.trigger('focus');
            activateAndNotify($next);
        }
    }

    function applyTabA11y($scope, tabsSelector, contentSelector, $tabs, normalizedA11yId) {
        $tabs.each(function (index) {
            var $tab = $(this);
            var $panel = findTargetPanel($scope, tabsSelector, contentSelector, $tab);
            let panelId = $panel.attr('id');
            let tabId = $tab.attr('id');

            if (!tabId) {
                tabId = normalizedA11yId + '-tab-' + index;
                $tab.attr('id', tabId);
            }

            if (!panelId) {
                panelId = normalizedA11yId + '-panel-' + index;
                $panel.attr('id', panelId);
            }

            $tab.attr('role', 'tab')
                .attr('aria-controls', panelId);

            $panel.attr('role', 'tabpanel')
                .attr('aria-labelledby', tabId);
        });
    }

    function bindTabEvents($scope, tabsSelector, activateAndNotify, onMoveToTab) {
        $scope.off('click.awaTabCarousel keydown.awaTabCarousel', tabsSelector);
        $scope.on('click.awaTabCarousel keydown.awaTabCarousel', tabsSelector, function (event) {
            let key = event.which || event.keyCode;
            let isKeyboard = event.type === 'keydown';

            if (isKeyboard) {
                if (handleKeyboardTabs(key, this, onMoveToTab, activateAndNotify)) {
                    event.preventDefault();
                }
                return;
            }

            event.preventDefault();
            activateAndNotify($(this));
        });
    }

    function findTargetPanel($scope, tabsSelector, contentSelector, $tab) {
        let targetId = $tab.attr('rel');
        var escapedId;
        var $panels = $scope.find(contentSelector);
        var $target = $();

        if (targetId) {
            targetId = $.trim(String(targetId));
            if (targetId.charAt(0) === '#') {
                targetId = targetId.substring(1);
            }
            if (typeof $.escapeSelector === 'function') {
                escapedId = $.escapeSelector(targetId);
                $target = $scope.find('#' + escapedId);
            } else if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
                escapedId = CSS.escape(targetId);
                $target = $scope.find('#' + escapedId);
            } else {
                $target = $scope.find('[id="' + targetId + '"]');
            }
        }

        if (!$target.length) {
            let tabIndex = $scope.find(tabsSelector).index($tab);
            if (tabIndex >= 0 && tabIndex < $panels.length) {
                $target = $panels.eq(tabIndex);
            } else {
                $target = $panels.first();
            }
        }

        return $target;
    }

    function activateTab($scope, tabsSelector, contentSelector, $tab) {
        var $tabs = $scope.find(tabsSelector);
        var $panels = $scope.find(contentSelector);
        var $target = findTargetPanel($scope, tabsSelector, contentSelector, $tab);

        $tabs.removeClass('active')
            .attr('aria-selected', 'false')
            .attr('tabindex', '-1');

        $tab.addClass('active')
            .attr('aria-selected', 'true')
            .attr('tabindex', '0');

        /* B2 (CICLO 4): exibicao dos paineis por CSS (.tab_content escopado),
           sem .hide()/.fadeIn() => 0 leituras curCSS por painel. */
        $panels.removeClass('animate1')
            .attr('aria-hidden', 'true');

        if ($target.length) {
            $target.addClass('animate1')
                .attr('aria-hidden', 'false');
        }

        return $target;
    }

    function initTabs($scope, config, onTabActivated) {
        let tabsSelector = config.tabsSelector || 'ul.tabs li';
        let contentSelector = config.contentSelector || '.tab_content';
        var $tabs = $scope.find(tabsSelector);
        var $active = $tabs.filter('.active').first();
        var $tabList = $tabs.first().parent();
        var $activePanel;
        let baseA11yId = $scope.attr('class') || 'awa-tab-group';
        let normalizedA11yId = baseA11yId.replace(/[^a-zA-Z0-9_-]+/g, '-');

        function activateAndNotify($tab, force) {
            if (!force && $tab.hasClass('active') && $tab.attr('aria-selected') === 'true') {
                return;
            }

            $activePanel = activateTab($scope, tabsSelector, contentSelector, $tab);

            if (typeof onTabActivated === 'function') {
                onTabActivated($activePanel, $tab);
            }
        }

        if (!$tabs.length || !$scope.find(contentSelector).length) {
            return;
        }

        if (!$active.length) {
            $active = $tabs.first();
        }

        $tabList.attr('role', 'tablist')
            .attr('aria-orientation', 'horizontal');

        applyTabA11y($scope, tabsSelector, contentSelector, $tabs, normalizedA11yId);
        bindTabEvents($scope, tabsSelector, activateAndNotify, function (currentTab, direction) {
            moveToTab($scope, tabsSelector, currentTab, direction, activateAndNotify);
        });

        activateAndNotify($active, true);
    }

    /**
     * @param {jQuery} $container
     * @returns {boolean}
     */
    function scanShelfRuntime($container) {
        var shelfRuntime = window.AWA_SHELF_CAROUSEL;

        if (!shelfRuntime || typeof shelfRuntime.scan !== 'function' || !$container || !$container.length) {
            return false;
        }

        shelfRuntime.scan($container[0]);

        try {
            document.dispatchEvent(new CustomEvent('awa:carousel:refresh', { bubbles: true }));
        } catch (ignore) {
            /* noop */
        }

        return true;
    }

    function scheduleShelfRetry($container) {
        if ($container.data('awaTabCarouselShelfRetry')) {
            return;
        }

        $container.data('awaTabCarouselShelfRetry', 1);

        function retry() {
            if (scanShelfRuntime($container)) {
                $container.removeData('awaTabCarouselShelfRetry');
            }
        }

        document.addEventListener('awa-bootstrap-ready', retry, { once: true });
        document.addEventListener('awa:carousel-runtime-ready', retry, { once: true });
        window.setTimeout(retry, 250);
        window.setTimeout(retry, 1200);
    }

    function initCarousel(config) {
        let carouselSelector = config.carouselSelector;
        let manager = {
            ensureIn: function () {}
        };

        if (!carouselSelector) {
            return manager;
        }

        function ensureIn($container) {
            if (!$container || !$container.length) {
                return;
            }

            if (scanShelfRuntime($container)) {
                return;
            }

            scheduleShelfRetry($container);
        }

        manager.ensureIn = ensureIn;

        return manager;
    }

    return function (config, element) {
        var $scope = $(element);
        var $window = $(window);
        let currentConfig = config || {};
        let tabsSelector = currentConfig.tabsSelector || 'ul.tabs li';
        let contentSelector = currentConfig.contentSelector || '.tab_content';
        let resizeNamespace = $scope.data('awaTabCarouselResizeNs');
        let visibilityRetryTimer = null;
        let carouselManager = initCarousel(currentConfig);

        if (!resizeNamespace) {
            resizeNamespace = '.awaTabCarouselResize-' + Math.random().toString(36).slice(2, 9);
            $scope.data('awaTabCarouselResizeNs', resizeNamespace);
        }

        function ensureActiveCarousel() {
            var $activeTab = $scope.find(tabsSelector + '.active').first();
            var $activePanel = findTargetPanel($scope, tabsSelector, contentSelector, $activeTab);

            runOnNextFrame(function () {
                if (!$scope.is(':visible') || ($activePanel.length && !$activePanel.is(':visible'))) {
                    if (!visibilityRetryTimer) {
                        visibilityRetryTimer = setTimeout(function () {
                            visibilityRetryTimer = null;
                            ensureActiveCarousel();
                        }, 120);
                    }
                    return;
                }

                if ($activePanel.length) {
                    carouselManager.ensureIn($activePanel);
                    return;
                }

                carouselManager.ensureIn($scope);
            });
        }

        initTabs($scope, currentConfig, function ($panel) {
            runOnNextFrame(function () {
                if ($panel && $panel.length) {
                    carouselManager.ensureIn($panel);
                    return;
                }

                carouselManager.ensureIn($scope);
            });
        });

        $window.off('resize' + resizeNamespace + ' orientationchange' + resizeNamespace)
            .on('resize' + resizeNamespace + ' orientationchange' + resizeNamespace, debounce(function () {
                ensureActiveCarousel();
            }, 150));

        $scope.off('remove.awaTabCarouselCleanup')
            .on('remove.awaTabCarouselCleanup', function () {
                if (visibilityRetryTimer) {
                    clearTimeout(visibilityRetryTimer);
                    visibilityRetryTimer = null;
                }

                $window.off('resize' + resizeNamespace + ' orientationchange' + resizeNamespace);
            });
    };
});
