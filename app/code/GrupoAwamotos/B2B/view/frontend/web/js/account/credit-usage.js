define([
    'jquery'
], function ($) {
    'use strict';

    return function (_config, element) {
        var $root = $(element);

        $root.find('.credit-usage-bar__fill[data-usage-percent]').each(function () {
            var $fill = $(this);
            var value = parseFloat($fill.data('usage-percent')) || 0;
            var normalized = Math.max(0, Math.min(100, value));

            $fill.css('--b2b-credit-width', normalized + '%');
        });
    };
});
