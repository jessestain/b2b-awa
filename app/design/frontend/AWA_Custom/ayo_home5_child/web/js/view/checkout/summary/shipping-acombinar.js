/**
 * Fase 5 (2026-10-26) — Override por map de Magento_Tax summary/shipping.
 * Estende o componente vendor e ja traz isACombinar() no prototipo resolvido,
 * eliminando o race de aplicacao de mixin em runtime ("isACombinar is not defined").
 * O cart/totals/shipping herda deste, entao o map cobre os dois contextos.
 * O requirejs-config mapeia este modulo de volta ao vendor REAL (identity map)
 * para o define abaixo nao cair em auto-referencia (recursao).
 */
define([
    'Magento_Tax/js/view/checkout/summary/shipping'
], function (Shipping) {
    'use strict';

    return Shipping.extend({
        isACombinar: function () {
            var title = '';

            if (typeof this.getShippingMethodTitle === 'function') {
                title = String(this.getShippingMethodTitle() || '');
            } else if (typeof this.getShippingDescription === 'function') {
                title = String(this.getShippingDescription() || '');
            }

            return /combinar/i.test(title);
        }
    });
});
