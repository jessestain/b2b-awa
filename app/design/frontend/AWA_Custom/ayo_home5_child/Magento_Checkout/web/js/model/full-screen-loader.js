/**
 * AWA — override do full-screen-loader (Magento_Checkout).
 *
 * Garantia anti-máscara-presa: se uma exceção em um callback .fail/.done de uma
 * action impedir o .always(stopLoader) de rodar, o loader ficaria visível para
 * sempre. Este override só força processStop no evento ajaxStop (nenhum AJAX
 * pendente no documento) — operações em andamento NUNCA têm o spinner escondido.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    var activeLoaders = 0;

    $(document).on('ajaxStop', function () {
        if (activeLoaders > 0) {
            activeLoaders = 0;
            $('body').trigger('processStop');
        }
    });

    return {
        /**
         * @returns {void}
         */
        startLoader: function () {
            activeLoaders++;
            $('body').trigger('processStart');
        },

        /**
         * @returns {void}
         */
        stopLoader: function () {
            if (activeLoaders > 0) {
                activeLoaders--;
            }

            $('body').trigger('processStop');
        }
    };
});