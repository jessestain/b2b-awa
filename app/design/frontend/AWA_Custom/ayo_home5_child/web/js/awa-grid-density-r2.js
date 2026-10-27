/** Desktop 3/4-column preference owned by the existing catalog toolbar. */
define([], function () {
    'use strict';

    var storageKey = 'awa_catalog_grid_columns_v1';

    function supported(value) {
        return value === '3' || value === '4';
    }

    return function (config, element) {
        if (element.dataset.awaGridDensityReady === '1') {
            return;
        }

        var scope = element.closest('.column.main') || element.closest('.page-main') || element.parentElement;
        var grid = scope && scope.querySelector('.container-products-switch');
        var controls = element.querySelector('.grid-mode-show-type-products');
        if (!grid || !controls) {
            return;
        }

        var columns = '4';
        var desktop = window.matchMedia('(min-width: 1200px)');
        var ownedValue = '';
        var ownedColumns = '';
        var originalValue = grid.style.getPropertyValue('grid-template-columns');
        var originalPriority = grid.style.getPropertyPriority('grid-template-columns');
        try {
            var saved = window.localStorage.getItem(storageKey);
            if (supported(saved)) {
                columns = saved;
            }
        } catch (error) {
            // Storage is optional; selection still works for the current page.
        }

        function applyLayout() {
            if (!element.isConnected) {
                if (desktop.removeEventListener) {
                    desktop.removeEventListener('change', applyLayout);
                } else {
                    desktop.removeListener(applyLayout);
                }
                return;
            }

            if (desktop.matches) {
                if (!ownedValue || ownedColumns !== columns || grid.style.getPropertyValue('grid-template-columns') !== ownedValue) {
                    grid.style.setProperty('grid-template-columns', 'repeat(' + columns + ', minmax(0, 1fr))', 'important');
                    ownedValue = grid.style.getPropertyValue('grid-template-columns');
                    ownedColumns = columns;
                }
            } else if (ownedValue && grid.style.getPropertyValue('grid-template-columns') === ownedValue) {
                if (originalValue) {
                    grid.style.setProperty('grid-template-columns', originalValue, originalPriority);
                } else {
                    grid.style.removeProperty('grid-template-columns');
                }
                ownedValue = '';
            }

            if (grid.getAttribute('data-view-mode') !== columns) {
                grid.setAttribute('data-view-mode', columns);
            }
            controls.querySelectorAll('a[data-grid-mode]').forEach(function (control) {
                var active = control.getAttribute('data-grid-mode') === columns;
                if (control.classList.contains('actived') !== active) {
                    control.classList.toggle('actived', active);
                }
                var pressed = active ? 'true' : 'false';
                if (control.getAttribute('aria-pressed') !== pressed) {
                    control.setAttribute('aria-pressed', pressed);
                }
            });
        }

        controls.addEventListener('click', function (event) {
            var control = event.target.closest('a[data-grid-mode]');
            var value = control && control.getAttribute('data-grid-mode');
            if (!control || !controls.contains(control) || !supported(value)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            columns = value;
            applyLayout();
            try {
                if (window.localStorage.getItem(storageKey) !== columns) {
                    window.localStorage.setItem(storageKey, columns);
                }
            } catch (error) {
                // Do not block layout when browser storage is unavailable.
            }
        });

        controls.addEventListener('keydown', function (event) {
            var control = event.target.closest('a[data-grid-mode]');
            if (event.key === ' ' && control && supported(control.getAttribute('data-grid-mode'))) {
                event.preventDefault();
                control.click();
            }
        });

        if (desktop.addEventListener) {
            desktop.addEventListener('change', applyLayout);
        } else {
            desktop.addListener(applyLayout);
        }
        element.dataset.awaGridDensityReady = '1';
        applyLayout();
    };
});
