/**
 * B2B RequireJS Configuration
 *
 * paths: maps module IDs to their .min versions.
 * Required because setup:static-content:deploy only generates .min.js files.
 * Without this, RequireJS tries to load .js (404), mixin-mage-apply-safe's
 * local errback catches the error, and data-mage-init widgets never initialize.
 */
var config = {
    paths: {
        'GrupoAwamotos_B2B/js/account/approval-actions': 'GrupoAwamotos_B2B/js/account/approval-actions-v2.min',
        'GrupoAwamotos_B2B/js/account/company-manager': 'GrupoAwamotos_B2B/js/account/company-manager-v2.min',
        'GrupoAwamotos_B2B/js/account/nav-mobile': 'GrupoAwamotos_B2B/js/account/nav-mobile.min',
        'GrupoAwamotos_B2B/js/account/onboarding': 'GrupoAwamotos_B2B/js/account/onboarding.min',
        'GrupoAwamotos_B2B/js/account/performance-charts': 'GrupoAwamotos_B2B/js/account/performance-charts.min',
        'GrupoAwamotos_B2B/js/account/reorder': 'GrupoAwamotos_B2B/js/account/reorder.min',
        'GrupoAwamotos_B2B/js/action/place-order-mixin': 'GrupoAwamotos_B2B/js/action/place-order-mixin.min',
        'GrupoAwamotos_B2B/js/auth-form': 'GrupoAwamotos_B2B/js/auth-form.min',
        'GrupoAwamotos_B2B/js/b2b-header-link': 'GrupoAwamotos_B2B/js/b2b-header-link.min',
        'GrupoAwamotos_B2B/js/b2b-panel-hydrate': 'GrupoAwamotos_B2B/js/b2b-panel-hydrate.min',
        'GrupoAwamotos_B2B/js/cart/min-order-progress': 'GrupoAwamotos_B2B/js/cart/min-order-progress.min',
        'GrupoAwamotos_B2B/js/checkout/cep-lookup-mixin': 'GrupoAwamotos_B2B/js/checkout/cep-lookup-mixin.min',
        'GrupoAwamotos_B2B/js/checkout/cpf-cnpj-mixin': 'GrupoAwamotos_B2B/js/checkout/cpf-cnpj-mixin.min',
        'GrupoAwamotos_B2B/js/checkout/erp-gate-banner': 'GrupoAwamotos_B2B/js/checkout/erp-gate-banner.min',
        'GrupoAwamotos_B2B/js/checkout/min-order-sidebar': 'GrupoAwamotos_B2B/js/checkout/min-order-sidebar.min',
        'GrupoAwamotos_B2B/js/checkout/sidebar-zones': 'GrupoAwamotos_B2B/js/checkout/sidebar-zones.min',
        'GrupoAwamotos_B2B/js/contact-tracker': 'GrupoAwamotos_B2B/js/contact-tracker.min',
        'GrupoAwamotos_B2B/js/customer/register-b2b-suggestion': 'GrupoAwamotos_B2B/js/customer/register-b2b-suggestion.min',
        'GrupoAwamotos_B2B/js/dashboard-async': 'GrupoAwamotos_B2B/js/dashboard-async.min',
        'GrupoAwamotos_B2B/js/dashboard-charts': 'GrupoAwamotos_B2B/js/dashboard-charts.min',
        'GrupoAwamotos_B2B/js/discount-badge': 'GrupoAwamotos_B2B/js/discount-badge.min',
        'GrupoAwamotos_B2B/js/finance/itf-barcode': 'GrupoAwamotos_B2B/js/finance/itf-barcode.min',
        'GrupoAwamotos_B2B/js/finance/print-modal': 'GrupoAwamotos_B2B/js/finance/print-modal.min',
        'GrupoAwamotos_B2B/js/finance/render-boleto-barcode': 'GrupoAwamotos_B2B/js/finance/render-boleto-barcode.min',
        'GrupoAwamotos_B2B/js/header-status-panel': 'GrupoAwamotos_B2B/js/header-status-panel.min',
        'GrupoAwamotos_B2B/js/inputMask': 'GrupoAwamotos_B2B/js/inputMask.min',
        'GrupoAwamotos_B2B/js/login-to-cart': 'GrupoAwamotos_B2B/js/login-to-cart.min',
        'GrupoAwamotos_B2B/js/model/checkout/b2b-config': 'GrupoAwamotos_B2B/js/model/checkout/b2b-config.min',
        'GrupoAwamotos_B2B/js/model/checkout/company-autofill': 'GrupoAwamotos_B2B/js/model/checkout/company-autofill.min',
        'GrupoAwamotos_B2B/js/model/checkout/order-notes-storage': 'GrupoAwamotos_B2B/js/model/checkout/order-notes-storage.min',
        'GrupoAwamotos_B2B/js/model/checkout/po-number-storage': 'GrupoAwamotos_B2B/js/model/checkout/po-number-storage.min',
        'GrupoAwamotos_B2B/js/model/payment/b2b-payment-data-assigner': 'GrupoAwamotos_B2B/js/model/payment/b2b-payment-data-assigner.min',
        'GrupoAwamotos_B2B/js/quickorder': 'GrupoAwamotos_B2B/js/quickorder.min',
        'GrupoAwamotos_B2B/js/quote/cart-button': 'GrupoAwamotos_B2B/js/quote/cart-button.min',
        'GrupoAwamotos_B2B/js/quote/view': 'GrupoAwamotos_B2B/js/quote/view.min',
        'GrupoAwamotos_B2B/js/register-form': 'GrupoAwamotos_B2B/js/register-form.min',
        'GrupoAwamotos_B2B/js/shoppinglist-modal': 'GrupoAwamotos_B2B/js/shoppinglist-modal.min',
        'GrupoAwamotos_B2B/js/shoppinglist/index': 'GrupoAwamotos_B2B/js/shoppinglist/index.min',
        'GrupoAwamotos_B2B/js/shoppinglist/view': 'GrupoAwamotos_B2B/js/shoppinglist/view-v2.min',
        'GrupoAwamotos_B2B/js/view/checkout/b2b-terms': 'GrupoAwamotos_B2B/js/view/checkout/b2b-terms.min',
        'GrupoAwamotos_B2B/js/view/checkout/order-notes': 'GrupoAwamotos_B2B/js/view/checkout/order-notes.min',
        'GrupoAwamotos_B2B/js/view/checkout/po-number': 'GrupoAwamotos_B2B/js/view/checkout/po-number.min',
        'GrupoAwamotos_B2B/js/view/payment/b2b-credit': 'GrupoAwamotos_B2B/js/view/payment/b2b-credit.min',
        'GrupoAwamotos_B2B/js/view/payment/method-renderer/b2b-credit': 'GrupoAwamotos_B2B/js/view/payment/method-renderer/b2b-credit.min'
    },
    map: {
        '*': {
            inputMask: 'GrupoAwamotos_B2B/js/inputMask',
            b2bHeaderStatusPanel: 'GrupoAwamotos_B2B/js/header-status-panel',
            shepherd: 'GrupoAwamotos_B2B/js/vendor/shepherd.min',
            'Rokanthemes_AjaxSuite/template/authentication-popup.html':
                'GrupoAwamotos_B2B/template/authentication-popup.html'
        }
    },
    config: {
        mixins: {
            // P0-1: Inject PO Number into payment data
            // P2-4.2: Inject Order Notes into payment data
            'Magento_Checkout/js/action/place-order': {
                'GrupoAwamotos_B2B/js/action/place-order-mixin': true
            },
            'Magento_Checkout/js/action/set-payment-information': {
                'GrupoAwamotos_B2B/js/model/payment/b2b-payment-data-assigner': true
            },
            'Magento_Checkout/js/action/set-payment-information-extended': {
                'GrupoAwamotos_B2B/js/model/payment/b2b-payment-data-assigner': true
            },
            // P2-4.1: Auto-fill empresa/IE na billing address
            'Magento_Checkout/js/action/set-billing-address': {
                'GrupoAwamotos_B2B/js/model/checkout/company-autofill': true
            }
        }
    }
};

