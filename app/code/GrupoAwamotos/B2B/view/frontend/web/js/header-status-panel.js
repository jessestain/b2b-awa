/**
 * B2B Header Status Panel - JavaScript Component
 * AWA Motos E-commerce B2B
 *
 * Features:
 * - Dropdown toggle with accessibility
 * - Keyboard navigation
 * - Click outside to close
 * - Mobile bottom sheet behavior
 */
define([
    'jquery',
    'mage/translate',
    'jquery-ui-modules/widget'
], function ($, $t) {
    'use strict';

    $.widget('grupoawamotos.headerStatusPanel', {
        options: {
            triggerSelector: '.b2b-status-trigger',
            dropdownSelector: '.b2b-status-dropdown',
            focusableSelector: 'a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])',
            closeOnOutsideClick: true,
            closeOnEscape: true,
            animationDuration: 250,
            fixedLayerBreakpoint: 768,
            desktopMaxWidth: 420,
            tabletMaxWidth: 360,
            viewportMargin: 12,
            layerZIndex: 100320
        },

        /**
         * Widget constructor
         * @private
         */
        _create: function () {
            this.trigger = this.element.find(this.options.triggerSelector);
            this.dropdown = this.element.find(this.options.dropdownSelector);
            this.isOpen = false;
            this.focusableElements = [];
            this._shadowOverlayState = null;

            this._bindEvents();
            this._initAccessibility();
            this._applyDynamicStyles();
        },

        /**
         * Apply CSS custom properties from safe data attributes.
         * Keeps presentation config out of inline style attributes.
         *
         * @private
         */
        _applyDynamicStyles: function () {
            this.element.find('[data-badge-color]').each(function () {
                this.style.setProperty('--badge-color', this.getAttribute('data-badge-color') || '');
            });

            this.element.find('[data-avatar-color]').each(function () {
                this.style.setProperty('--avatar-color', this.getAttribute('data-avatar-color') || '');
            });

            this.element.find('[data-tier-color]').each(function () {
                this.style.setProperty('--tier-color', this.getAttribute('data-tier-color') || '');
            });

            this.element.find('[data-credit-width]').each(function () {
                var width = parseInt(this.getAttribute('data-credit-width'), 10);
                var normalized = !isNaN(width) ? Math.max(0, Math.min(100, width)) : 0;

                this.style.setProperty('--b2b-credit-width', normalized + '%');
            });
        },

        /**
         * Bind event handlers
         * @private
         */
        _bindEvents: function () {
            let self = this;

            // Trigger click
            this.trigger.on('click.b2bPanel', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggle();
            });

            // Keyboard navigation on trigger
            this.trigger.on('keydown.b2bPanel', function (e) {
                self._handleTriggerKeydown(e);
            });

            // Keyboard navigation in dropdown
            this.dropdown.on('keydown.b2bPanel', function (e) {
                self._handleDropdownKeydown(e);
            });

            // Click outside to close
            if (this.options.closeOnOutsideClick) {
                $(document).on('click.b2bPanel', function (e) {
                    if (self.isOpen && !self.element[0].contains(e.target)) {
                        self.close();
                    }
                });
            }

            // Escape key to close
            if (this.options.closeOnEscape) {
                $(document).on('keydown.b2bPanel', function (e) {
                    if (e.key === 'Escape' && self.isOpen) {
                        self.close();
                        self.trigger.focus();
                    }
                });
            }

            // Handle window resize for mobile
            $(window).on('resize.b2bPanel', $.proxy(this._handleResize, this));

            // Fecha ao rolar — evita painel flutuando sobre o hero/carrosséis
            $(window).on('scroll.b2bPanel', function () {
                if (self.isOpen) {
                    self.close();
                }
            });

            // Exclusividade: foco/digitação na busca fecha o painel B2B
            $(document).on('focusin.b2bPanel input.b2bPanel', '#search, .form.minisearch input[name="q"]', function () {
                if (self.isOpen) {
                    self.close();
                }
            });
        },

        /**
         * Initialize accessibility attributes
         * @private
         */
        _initAccessibility: function () {
            this.trigger.attr({
                'aria-expanded': 'false',
                'aria-controls': this.dropdown.attr('id')
            });

            this.dropdown.attr({
                'aria-hidden': 'true',
                'role': 'region',
                'aria-label': $t('B2B Account Panel')
            });
        },

        /**
         * Handle keydown on trigger
         * @private
         */
        _handleTriggerKeydown: function (e) {
            switch (e.key) {
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    this.toggle();
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    this.open();
                    this._focusFirstElement();
                    break;
            }
        },

        /**
         * Handle keydown in dropdown
         * @private
         */
        _handleDropdownKeydown: function (e) {
            let focusable = this.dropdown.find(this.options.focusableSelector).filter(':visible');
            let currentIndex = focusable.index(document.activeElement);

            switch (e.key) {
                case 'Tab':
                    // Trap focus within dropdown
                    if (e.shiftKey && currentIndex === 0) {
                        e.preventDefault();
                        focusable.last().focus();
                    } else if (!e.shiftKey && currentIndex === focusable.length - 1) {
                        e.preventDefault();
                        focusable.first().focus();
                    }
                    break;

                case 'ArrowDown':
                    e.preventDefault();
                    if (currentIndex < focusable.length - 1) {
                        focusable.eq(currentIndex + 1).focus();
                    }
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    if (currentIndex > 0) {
                        focusable.eq(currentIndex - 1).focus();
                    } else {
                        this.trigger.focus();
                        this.close();
                    }
                    break;

                case 'Home':
                    e.preventDefault();
                    focusable.first().focus();
                    break;

                case 'End':
                    e.preventDefault();
                    focusable.last().focus();
                    break;
            }
        },

        /**
         * Toggle dropdown state
         */
        toggle: function () {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        },

        /**
         * Close search autocomplete so it cannot stack over the B2B panel.
         * Mirrors AWA header exclusivity (minicart/search/menu) for B2B.
         * @private
         */
        _closeSearchAutocomplete: function () {
            var form = document.querySelector('.form.minisearch, form#search_mini_form');
            var input = document.querySelector('#search, .form.minisearch input[name="q"]');
            var panels = document.querySelectorAll(
                '#search_autocomplete, .mst-searchautocomplete__autocomplete, ' +
                '.searchsuite-autocomplete, .search-autocomplete'
            );

            if (form) {
                form.setAttribute('data-awa-panel-closed', 'true');
                form.classList.remove('is-open', 'has-results');
            }

            if (input) {
                input.setAttribute('aria-expanded', 'false');
                if (document.activeElement === input) {
                    input.blur();
                }
            }

            panels.forEach(function (panel) {
                panel.classList.remove('is-open', 'has-results', '_active', 'active');
                if (panel.getAttribute('aria-hidden') !== 'true') {
                    panel.setAttribute('aria-hidden', 'true');
                }
                panel.style.setProperty('display', 'none', 'important');
                panel.style.setProperty('visibility', 'hidden', 'important');
                panel.style.setProperty('opacity', '0', 'important');
                panel.style.setProperty('pointer-events', 'none', 'important');
            });

            if (document.body) {
                document.body.classList.remove('searchautocomplete__active');
            }

            document.dispatchEvent(new CustomEvent('awa:close-search-overlay', {
                bubbles: true,
                detail: { source: 'b2b-status-panel' }
            }));
        },

        /**
         * Open dropdown
         */
        open: function () {
            if (this.isOpen) {
                return;
            }

            this._closeSearchAutocomplete();

            this.isOpen = true;
            this._suppressLegacyShadowOverlay();
            this.trigger.attr('aria-expanded', 'true');
            this.dropdown.attr('aria-hidden', 'false');
            this.element.addClass('is-open');
            this._positionDropdown();

            // Announce to screen readers
            this._announceState('aberto');

            // Focus first focusable element after animation
            setTimeout($.proxy(this._focusFirstElement, this), this.options.animationDuration);
        },

        /**
         * Close dropdown
         */
        close: function () {
            if (!this.isOpen) {
                return;
            }

            this.isOpen = false;
            this.trigger.attr('aria-expanded', 'false');
            this.dropdown.attr('aria-hidden', 'true');
            this.element.removeClass('is-open');
            this._resetDropdownPosition();
            this._restoreLegacyShadowOverlay();

            // Announce to screen readers
            this._announceState('fechado');
        },

        /**
         * Focus first focusable element in dropdown
         * @private
         */
        _focusFirstElement: function () {
            let first = this.dropdown.find(this.options.focusableSelector).filter(':visible').first();
            if (first.length) {
                first.focus();
            }
        },

        /**
         * Handle window resize
         * @private
         */
        _handleResize: function () {
            if (this.isOpen) {
                this._positionDropdown();
            }
        },

        /**
         * Resolve desktop fixed-layer top from the rendered header row.
         *
         * Account pages can lay out header children with display:contents, which
         * makes the trigger rect drift below the visual header. In that case the
         * dropdown must anchor to the row that actually paints the header.
         *
         * @private
         */
        _resolveDesktopTop: function (triggerRect, margin) {
            let top = Math.round(triggerRect.bottom - 1);
            let triggerNode = this.trigger[0];
            let anchor = triggerNode
                ? triggerNode.closest('.awa-main-header__inner, .header.awa-main-header, .header-wrapper-sticky')
                : null;
            let body = document.body;
            let isB2bDashboard = body && (
                body.classList.contains('b2b-account-dashboard') ||
                body.classList.contains('b2b-account-index')
            );

            if (!anchor) {
                anchor = document.querySelector(
                    '.awa-site-header .awa-main-header__inner, ' +
                    '.awa-site-header .header.awa-main-header, ' +
                    '.awa-site-header .header-wrapper-sticky'
                );
            }

            if (anchor) {
                let anchorRect = anchor.getBoundingClientRect();

                if (anchorRect && anchorRect.width > 0 && anchorRect.height > 0 && anchorRect.bottom > 0) {
                    let anchorBottom = Math.round(anchorRect.bottom - 1);

                    if (isB2bDashboard || top - anchorBottom > 12) {
                        top = anchorBottom;
                    }
                }
            }

            return Math.max(margin, top);
        },

        /**
         * Position the dropdown outside header overflow/z-index contexts.
         * @private
         */
        _positionDropdown: function () {
            if (!this.trigger.length || !this.dropdown.length) {
                return;
            }

            let dropdown = this.dropdown[0];
            let viewportWidth = document.documentElement.clientWidth || window.innerWidth || 0;
            let viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            let margin = this.options.viewportMargin;

            dropdown.setAttribute('data-awa-fixed-layer', 'true');
            dropdown.style.setProperty('position', 'fixed', 'important');
            dropdown.style.setProperty('z-index', String(this.options.layerZIndex), 'important');
            dropdown.style.setProperty('max-width', 'calc(100vw - 24px)', 'important');

            if (viewportWidth < this.options.fixedLayerBreakpoint) {
                dropdown.style.setProperty('top', 'auto', 'important');
                dropdown.style.setProperty('right', '0', 'important');
                dropdown.style.setProperty('bottom', '0', 'important');
                dropdown.style.setProperty('left', '0', 'important');
                dropdown.style.setProperty('width', '100%', 'important');
                dropdown.style.setProperty('max-height', '82vh', 'important');
                return;
            }

            let rect = this.trigger[0].getBoundingClientRect();
            let maxConfiguredWidth = viewportWidth < 992
                ? this.options.tabletMaxWidth
                : this.options.desktopMaxWidth;
            let width = Math.max(280, Math.min(maxConfiguredWidth, viewportWidth - (margin * 2)));
            let left = Math.min(Math.max(margin, rect.right - width), viewportWidth - width - margin);
            let top = this._resolveDesktopTop(rect, margin);
            let maxHeight = Math.max(240, Math.min(520, viewportHeight - top - margin));

            dropdown.style.setProperty('top', top + 'px', 'important');
            dropdown.style.setProperty('right', 'auto', 'important');
            dropdown.style.setProperty('bottom', 'auto', 'important');
            dropdown.style.setProperty('left', Math.round(left) + 'px', 'important');
            dropdown.style.setProperty('width', Math.round(width) + 'px', 'important');
            dropdown.style.setProperty('max-height', Math.round(maxHeight) + 'px', 'important');
        },

        /**
         * Remove fixed-layer inline positioning after close.
         * @private
         */
        _resetDropdownPosition: function () {
            if (!this.dropdown.length) {
                return;
            }

            let dropdown = this.dropdown[0];
            dropdown.removeAttribute('data-awa-fixed-layer');
            [
                'position',
                'z-index',
                'inset',
                'top',
                'right',
                'bottom',
                'left',
                'width',
                'max-width',
                'max-height'
            ].forEach(function (property) {
                dropdown.style.removeProperty(property);
            });
        },

        /**
         * Keep legacy header overlay disabled while B2B panel is open.
         * @private
         */
        _suppressLegacyShadowOverlay: function () {
            let body = document.body;
            let html = document.documentElement;
            let shadow = document.querySelector('.shadow_bkg_show');
            let properties = ['display', 'opacity', 'visibility', 'pointer-events', 'background-color'];

            if (!shadow) {
                return;
            }

            this._shadowOverlayState = {
                styles: {},
                ariaHidden: shadow.getAttribute('aria-hidden')
            };

            properties.forEach(function (property) {
                this._shadowOverlayState.styles[property] = {
                    value: shadow.style.getPropertyValue(property),
                    priority: shadow.style.getPropertyPriority(property)
                };
            }, this);

            if (body) {
                body.classList.remove('nav-open', 'background_shadow_show');
            }
            if (html) {
                html.classList.remove('nav-open', 'background_shadow_show');
            }

            shadow.style.setProperty('display', 'none', 'important');
            shadow.style.setProperty('opacity', '0', 'important');
            shadow.style.setProperty('visibility', 'hidden', 'important');
            shadow.style.setProperty('pointer-events', 'none', 'important');
            shadow.style.setProperty('background-color', 'transparent', 'important');
            shadow.setAttribute('aria-hidden', 'true');
        },

        /**
         * Restore legacy overlay inline styles captured before opening panel.
         * @private
         */
        _restoreLegacyShadowOverlay: function () {
            let shadow = document.querySelector('.shadow_bkg_show');
            let state = this._shadowOverlayState;

            if (!shadow || !state || !state.styles) {
                return;
            }

            Object.keys(state.styles).forEach(function (property) {
                let styleState = state.styles[property];

                if (!styleState || !styleState.value) {
                    shadow.style.removeProperty(property);
                    return;
                }

                shadow.style.setProperty(property, styleState.value, styleState.priority || '');
            });

            if (state.ariaHidden === null || typeof state.ariaHidden === 'undefined') {
                shadow.removeAttribute('aria-hidden');
            } else {
                shadow.setAttribute('aria-hidden', state.ariaHidden);
            }

            this._shadowOverlayState = null;
        },

        /**
         * Announce state change for screen readers
         * @private
         */
        _announceState: function (state) {
            let announcement = $('<div/>', {
                'class': 'sr-only',
                'aria-live': 'polite',
                'aria-atomic': 'true',
                'text': $t('Painel B2B %1').replace('%1', state)
            });

            $('body').append(announcement);
            setTimeout(function () {
                announcement.remove();
            }, 1000);
        },

        /**
         * Destroy widget
         * @private
         */
        _destroy: function () {
            this._resetDropdownPosition();
            this._restoreLegacyShadowOverlay();
            this.trigger.off('.b2bPanel');
            this.dropdown.off('.b2bPanel');
            $(document).off('.b2bPanel');
            $(window).off('.b2bPanel');
        }
    });

    return $.grupoawamotos.headerStatusPanel;
});
