define([
    'jquery',
    'mage/translate',
    'Magento_Customer/js/customer-data'
], function ($, $t, customerData) {
    'use strict';

    function normalizeLocale(locale)
    {
        var normalized = String(locale || '').replace('_', '-').trim();

        return normalized || 'pt-BR';
    }

    function formatCurrency(amount, currencyCode, locale)
    {
        var value = Math.max(0, parseFloat(amount) || 0);
        var safeCurrency = String(currencyCode || '').trim() || 'BRL';
        var safeLocale = normalizeLocale(locale);

        if (window.Intl && window.Intl.NumberFormat) {
            try {
                return new window.Intl.NumberFormat(safeLocale, {
                    style: 'currency',
                    currency: safeCurrency,
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(value);
            } catch (e) {
                // Fallback abaixo mantém experiência previsível.
            }
        }

        return 'R$ ' + value.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    return function (config, element) {
        var $root = $(element);
        var minAmount = parseFloat(config.minAmount) || 0;
        var locale = normalizeLocale(config.locale);
        var currencyCode = String(config.currencyCode || '').trim() || 'BRL';

        if (!config.enabled || minAmount <= 0) {
            $root.attr('hidden', 'hidden');
            return;
        }

        function render(subtotal)
        {
            var remaining = Math.max(0, minAmount - subtotal);
            var percent = minAmount > 0 ? Math.min(100, Math.round((subtotal / minAmount) * 100)) : 100;
            var message = $t('Faltam %1 para atingir o pedido mínimo de %2.')
                .replace('%1', formatCurrency(remaining, currencyCode, locale))
                .replace('%2', formatCurrency(minAmount, currencyCode, locale));

            if (subtotal <= 0 || remaining <= 0.009) {
                $root.attr('hidden', 'hidden');
                $root.attr('aria-hidden', 'true');
                return;
            }

            $root.removeAttr('hidden');
            $root.removeAttr('aria-hidden');
            $root.toggleClass('awa-b2b-min-order-progress--near', percent >= 80);
            $root.find('[data-role="percent"]').text(percent + '%');
            $root.find('[data-role="fill"]').css('width', percent + '%');
            $root.find('[data-role="message"]').text(message);
        }

        function syncFromCart(cart)
        {
            var subtotal = 0;

            if (cart && cart.subtotalAmount !== undefined && cart.subtotalAmount !== null) {
                subtotal = parseFloat(cart.subtotalAmount) || 0;
            }

            render(subtotal);
        }

        var cartData = customerData.get('cart');

        cartData.subscribe(syncFromCart);
        syncFromCart(cartData());
    };
});
