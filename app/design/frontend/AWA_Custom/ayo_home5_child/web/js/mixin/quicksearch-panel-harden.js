/**
 * AWA — Harden Magento quickSearch: sincroniza classes/ARIA após injetar sugestões.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    return function (QuickSearch) {
        $.widget('mage.quickSearch', QuickSearch, {
            /**
             * @inheritdoc
             */
            _create: function () {
                this._awaPromoteTimer = null;
                this._super();
                this.options.minSearchLength = 2;
                this._awaSyncSubmit();
                this._awaBindSubmitRescue();
            },

            /**
             * @private
             */
            _awaSchedulePromote: function () {
                var widget = this;

                if (this._awaPromoteTimer) {
                    window.clearTimeout(this._awaPromoteTimer);
                }

                this._awaPromoteTimer = window.setTimeout(function () {
                    widget._awaPromotePanel();
                }, 420);
            },

            /**
             * @private
             */
            _awaPromotePanel: function () {
                var panel = this.autoComplete;
                var value = $.trim(this.element.val() || '');
                var minLen = parseInt(this.options.minSearchLength, 10) || 3;

                if (!panel || !panel.length || value.length < minLen || !panel.children().length) {
                    return;
                }

                panel.addClass('is-open active has-results');
                panel.attr('aria-hidden', 'false');
                panel.removeAttr('hidden');
                this.searchForm.addClass('is-open has-results');
                this.element.attr('aria-expanded', 'true');

                if (document.body) {
                    document.body.classList.add('searchautocomplete__active');
                }
            },

            /**
             * @inheritdoc
             */
            _onPropertyChange: function () {
                this._super();
                this._awaSyncSubmit();
                this._awaSchedulePromote();
            },

            /**
             * Keep submit enabled as soon as the query meets min length.
             * Magento form-mini disables the button in _create and during the
             * 300ms suggestionDelay window, which swallows the first click.
             */
            _awaSyncSubmit: function () {
                var length;
                var minLen;

                if (!this.submitBtn) {
                    return;
                }

                length = $.trim(this.element.val() || '').length;
                minLen = parseInt(this.options.minSearchLength, 10) || 2;
                this.submitBtn.disabled = length < minLen;
                this.submitBtn.setAttribute('aria-disabled', length < minLen ? 'true' : 'false');
            },

            /**
             * Magento form-mini sets submitBtn.disabled=true at the start of
             * _onPropertyChange. A click that lands in that window is discarded
             * because disabled buttons do not fire click/submit.
             * Re-enable on form mousedown (parent still receives the event).
             */
            _awaBindSubmitRescue: function () {
                var widget = this;
                var formEl;

                if (!this.searchForm || !this.searchForm.length || this.searchForm.data('awaSubmitRescue')) {
                    return;
                }

                formEl = this.searchForm.get(0);
                if (!formEl) {
                    return;
                }

                this.searchForm.data('awaSubmitRescue', true);
                formEl.addEventListener('mousedown', function (event) {
                    var actions;

                    if (!widget.submitBtn) {
                        return;
                    }

                    actions = widget.searchForm.find('.actions').get(0);
                    if (event.target === widget.submitBtn
                        || widget.submitBtn.contains(event.target)
                        || (actions && actions.contains(event.target))) {
                        widget._awaSyncSubmit();
                    }
                }, true);
            }
        });

        return $.mage.quickSearch;
    };
});
