define([
    'uiRegistry'
], function (registry) {
    'use strict';

    return function () {
        registry.get(
            'customer_listing.customer_listing.customer_columns.b2b_cnpj',
            function (column) {
                if (!column || typeof column.visible !== 'function') {
                    return;
                }
                column.visible(false);
                if (!column.__awaHideDuplicateCnpj) {
                    column.__awaHideDuplicateCnpj = true;
                    column.visible.subscribe(function (isVisible) {
                        if (isVisible) {
                            column.visible(false);
                        }
                    });
                }
            }
        );
    };
});
