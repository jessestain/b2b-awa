define([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    'use strict';

    let PDP_ICON_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">'
        + '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>'
        + '<polyline points="16 17 21 12 16 7"></polyline>'
        + '<line x1="21" y1="12" x2="9" y2="12"></line>'
        + '</svg>';

    let PENDING_ICON_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">'
        + '<circle cx="12" cy="12" r="10"></circle>'
        + '<polyline points="12 6 12 12 16 14"></polyline>'
        + '</svg>';

    function isElementVisible(el)
    {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }

    function createLoginButton(options)
    {
        let btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'b2b-login-to-buy-btn' + (options && options.variantClass ? (' ' + options.variantClass) : '');
        if (options && options.html) {
            btn.innerHTML = options.html; // intentional — contains trusted SVG icon markup
        } else {
            btn.textContent = (options && options.text) ? options.text : 'Entrar para Comprar';
        }
        if (options && options.disabled) {
            btn.disabled = true;
            btn.classList.add('b2b--disabled');
        }
        return btn;
    }

    /**
     * Check if customer is actually logged in using customer-data sections.
     * This is the authoritative source of truth, not the server-side mode from cached HTML.
     */
    function isCustomerDataLoggedIn(customer)
    {
        if (!customer || typeof customer !== 'object') {
            return false;
        }

        return !!(
            customer.firstname
            || customer.fullname
            || customer.email
            || customer.id
            || customer.entity_id
        );
    }

    function getCustomerDataPayload()
    {
        try {
            return customerData.get('customer')();
        } catch (e) {
            return {};
        }
    }

    function isCustomerLoggedIn()
    {
        try {
            return isCustomerDataLoggedIn(getCustomerDataPayload());
        } catch (e) {
            return false;
        }
    }

    /**
     * Restore original add-to-cart buttons that were hidden by this script.
     * Called when we detect the customer is actually logged in.
     */
    function restoreOriginalButtons()
    {
        // Remove body classes
        document.body.classList.remove('b2b-guest-mode', 'b2b-pending-mode', 'b2b-restricted-mode');
        removeTerminalHideCss();

        document.querySelectorAll('.b2b-login-to-buy-mode').forEach(function (container) {
            container.classList.remove('b2b-login-to-buy-mode');
        });

        // Remove all injected B2B buttons
        document.querySelectorAll('[data-b2b-injected]').forEach(function (btn) {
            btn.parentNode.removeChild(btn);
        });

        // Restore all hidden original buttons
        document.querySelectorAll('[data-b2b-original-hidden]').forEach(function (btn) {
            btn.style.removeProperty('display');
            btn.removeAttribute('hidden');
            btn.removeAttribute('aria-hidden');
            btn.removeAttribute('disabled');
            btn.removeAttribute('tabindex');
            btn.removeAttribute('data-b2b-original-hidden');
        });

        // Hide pending banner
        let pendingBanner = document.getElementById('b2b-pending-banner');
        if (pendingBanner) {
            pendingBanner.hidden = true;
        }

        // Hide login modal
        let overlay = document.getElementById('b2b-login-modal');
        if (overlay) {
            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('inert', '');
            overlay.hidden = true;
        }
    }


    /**
     * Terminal CSS injection: login-to-cart.css loads before awa-align-grid-terminal,
     * which forces qty/tocart display:flex!important with body#html-body x4.
     * A late <style> tag beats that cascade (runtime-proven 2026-08-07).
     */
    function injectTerminalHideCss()
    {
        var styleId = 'b2b-l2c-terminal-hide';
        var css;
        if (document.getElementById(styleId)) {
            return;
        }
        css = document.createElement('style');
        css.id = styleId;
        css.setAttribute('data-awa-debug', 'l2c-terminal');
        css.textContent = [
            'html body#html-body#html-body#html-body#html-body.b2b-restricted-mode.catalog-product-view .page-wrapper .product-info-main .product-add-form .box-tocart .field.qty,',
            'html body#html-body#html-body#html-body#html-body.b2b-restricted-mode.catalog-product-view .page-wrapper .product-info-main .product-add-form .box-tocart #product-addtocart-button,',
            'html body#html-body#html-body#html-body#html-body.b2b-restricted-mode.catalog-product-view .page-wrapper .product-info-main .product-add-form .box-tocart .action.tocart,',
            'html body#html-body#html-body#html-body#html-body.b2b-restricted-mode [data-b2b-original-hidden="1"]{',
            'display:none!important;visibility:hidden!important;pointer-events:none!important;',
            'position:absolute!important;width:0!important;height:0!important;max-height:0!important;',
            'overflow:hidden!important;clip:rect(0,0,0,0)!important;margin:0!important;padding:0!important;',
            '}'
        ].join('');
        (document.head || document.documentElement).appendChild(css);
    }

    function removeTerminalHideCss()
    {
        var el = document.getElementById('b2b-l2c-terminal-hide');
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
    }

    // HOTFIX B2B-AUTH (13/10): estado visivel — loading ate a secao decidir.
    function setAuthState(state)
    {
        try {
            document.body.classList.remove(
                "b2b-auth-pending", "b2b-auth-granted", "b2b-auth-denied", "b2b-auth-error"
            );
            document.body.classList.add("b2b-auth-" + state);
        } catch (e) {
            // body indisponivel — segue
        }
    }

    function init(config)
    {
        // Skip on pages with no product add-to-cart buttons (homepage, checkout, etc.)
        if (!document.querySelector('.product-item-actions, .product-add-form, .product-info-cart')) {
            return;
        }

        if (!config) {
            return;
        }

        // Determine mode from server: 'guest', 'pending' or 'approved_restricted'
        let serverMode = config.mode || 'guest';
        let activeMode = serverMode; // May be overridden by customer-data
        let isRestricted = true; // Assume restricted until customer-data confirms otherwise
        let bodyClass = (serverMode === 'guest') ? 'b2b-guest-mode' : 'b2b-pending-mode';

        let overlay = document.getElementById('b2b-login-modal');
        let pendingBanner = document.getElementById('b2b-pending-banner');
        let dialog = overlay ? overlay.querySelector('.b2b-login-modal') : null;
        let closeBtn = overlay ? overlay.querySelector('[data-b2b-login-close]') : null;
        let lastActiveElement = null;
        let lastTriggerButton = null;
        let previousBodyOverflow = null;
        let observerInstance = null;
        let priceSyncStarted = false;

        function hasHiddenPriceMarkers()
        {
            return !!document.querySelector(
                '.b2b-login-to-see-price, [data-awa-gate-state="guest"], .product .price-box .price-label a[href*="login"]'
            );
        }

        function escapeHtml(value)
        {
            return String(value || '').replace(/[&<>"']/g, function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char];
            });
        }

        function syncPendingPriceMarkers(root)
        {
            let scope = root && root.querySelectorAll ? root : document;
            let label = (config && config.pendingPriceText) ? config.pendingPriceText : 'Aguardando aprovação';
            let note = (config && config.pendingPriceNote) ? config.pendingPriceNote : 'Preços liberados após análise';

            if (activeMode !== 'pending') {
                return;
            }

            scope.querySelectorAll('.b2b-login-to-see-price').forEach(function (priceMarker) {
                priceMarker.classList.add('b2b-price-pending');
                priceMarker.setAttribute('data-b2b-pending-price', '1');
                priceMarker.setAttribute('aria-label', label + '. ' + note);
                priceMarker.innerHTML = '<span class="price-label">' + escapeHtml(label) + '</span>'
                    + '<span class="price-note">' + escapeHtml(note) + '</span>';
            });
        }

        function ensurePriceGateVisible(root)
        {
            let scope = root && root.querySelectorAll ? root : document;

            scope.querySelectorAll('.product-info-main .product-info-price .b2b-login-to-see-price, .product-info-main .product-info-price [data-awa-b2b-price-gate="1"]').forEach(function (priceMarker) {
                priceMarker.style.setProperty('display', 'flex', 'important');
                priceMarker.style.setProperty('flex-direction', 'column', 'important');
                priceMarker.style.setProperty('visibility', 'visible', 'important');
                priceMarker.style.setProperty('opacity', '1', 'important');
            });
        }

        function getProductIdFromNode(node)
        {
            let root;
            let fromDataset;
            let productInput;

            if (!node) {
                return null;
            }

            root = node.closest('[data-product-id], .item-product, .product-item, li, .product-info-main, .product-add-form') || node.parentElement;
            fromDataset = root && root.getAttribute ? parseInt(root.getAttribute('data-product-id'), 10) : 0;
            if (fromDataset) {
                return String(fromDataset);
            }

            productInput = root ? root.querySelector('input[name="product"]') : null;
            if (!productInput && document.getElementById('product_addtocart_form')) {
                productInput = document.querySelector('#product_addtocart_form input[name="product"]');
            }

            return productInput && productInput.value ? String(parseInt(productInput.value, 10)) : null;
        }

        function collectPriceTargets()
        {
            let targetsByProductId = {};

            document.querySelectorAll('.b2b-login-to-see-price').forEach(function (priceMarker) {
                let productId = getProductIdFromNode(priceMarker);

                if (!productId) {
                    return;
                }

                if (!targetsByProductId[productId]) {
                    targetsByProductId[productId] = [];
                }

                targetsByProductId[productId].push(priceMarker);
            });

            return targetsByProductId;
        }

        function replacePriceTarget(priceMarker, html)
        {
            if (!priceMarker || !html) {
                return;
            }

            if (priceMarker.parentNode) {
                priceMarker.outerHTML = html;
            }
        }

        function hydrateHiddenPrices()
        {
            let targetsByProductId = collectPriceTargets();
            let productIds = Object.keys(targetsByProductId);

            if (!productIds.length || typeof window.fetch !== 'function') {
                return Promise.resolve(false);
            }

            return window.fetch('/b2b/ajax/customerPrices?product_ids=' + encodeURIComponent(productIds.join(',')), {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (payload) {
                let hydratedAny = false;

                if (!payload || !payload.success || !payload.allowed || !payload.items) {
                    return false;
                }

                Object.keys(payload.items).forEach(function (productId) {
                    let item = payload.items[productId];

                    if (!item || !item.html || !targetsByProductId[productId]) {
                        return;
                    }

                    targetsByProductId[productId].forEach(function (priceMarker) {
                        replacePriceTarget(priceMarker, item.html);
                        hydratedAny = true;
                    });
                });

                return hydratedAny;
            }).catch(function () {
                return false;
            });
        }

        function syncPriceBlocksAfterLogin()
        {
            if (priceSyncStarted || isRestricted || !hasHiddenPriceMarkers()) {
                return;
            }

            priceSyncStarted = true;

            try {
                customerData.invalidate(['customer', 'cart']);
                customerData.reload(['customer', 'cart'], true);
            } catch (error) {
                // ignore customer-data refresh errors and continue with AJAX hydration
            }

            hydrateHiddenPrices().then(function (hydratedAny) {
                if (!hydratedAny) {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 150);
                }
            });
        }

        function isModalOpen()
        {
            return overlay && overlay.classList.contains('active');
        }

        function getFocusableElements()
        {
            if (!dialog) {
                return [];
            }

            let focusables = Array.prototype.slice.call(
                dialog.querySelectorAll(
                    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
                )
            );

            return focusables.filter(function (el) {
                return isElementVisible(el);
            });
        }

        function attachModalToBody()
        {
            // Magento_Ui modal appends to <body> so position:fixed is not clipped
            // by .page-wrapper { overflow: clip }.
            if (overlay && overlay.parentNode && overlay.parentNode !== document.body) {
                document.body.appendChild(overlay);
            }
        }

        function openModal(triggerEl)
        {
            if (!overlay || activeMode !== 'guest' || isModalOpen()) {
                return;
            }
            attachModalToBody();
            lastActiveElement = document.activeElement;
            lastTriggerButton = triggerEl || lastActiveElement;
            if (lastTriggerButton && typeof lastTriggerButton.setAttribute === 'function') {
                lastTriggerButton.setAttribute('aria-expanded', 'true');
            }
            overlay.hidden = false;
            overlay.removeAttribute('inert');
            overlay.classList.add('active');
            overlay.setAttribute('aria-hidden', 'false');

            if (previousBodyOverflow === null) {
                previousBodyOverflow = document.body.style.overflow;
            }
            document.body.style.overflow = 'hidden';

            window.setTimeout(function () {
                let focusables = getFocusableElements();
                if (focusables.length) {
                    focusables[0].focus();
                } else if (dialog) {
                    dialog.focus();
                }
            }, 0);
        }

        function closeModal()
        {
            if (!overlay) {
                return;
            }
            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('inert', '');
            overlay.hidden = true;

            document.body.style.overflow = previousBodyOverflow !== null ? previousBodyOverflow : '';
            previousBodyOverflow = null;

            if (lastTriggerButton && typeof lastTriggerButton.setAttribute === 'function') {
                lastTriggerButton.setAttribute('aria-expanded', 'false');
            }
            lastTriggerButton = null;

            if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
                lastActiveElement.focus();
            }
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                closeModal();
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    closeModal();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (!isModalOpen()) {
                return;
            }

            if (e.key === 'Escape') {
                closeModal();
                return;
            }

            if (e.key !== 'Tab') {
                return;
            }

            let focusables = getFocusableElements();
            if (!focusables.length) {
                e.preventDefault();
                return;
            }

            let first = focusables[0];
            let last = focusables[focusables.length - 1];
            let isShiftPressed = e.shiftKey;
            let currentActiveElement = document.activeElement;

            if (isShiftPressed) {
                if (currentActiveElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (currentActiveElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        function wireSsrGuestButton(btn)
        {
            if (!btn || btn.getAttribute('data-b2b-wired') === '1' || activeMode !== 'guest') {
                return;
            }

            btn.setAttribute('data-b2b-wired', '1');
            btn.setAttribute('aria-haspopup', 'dialog');
            btn.setAttribute('aria-controls', 'b2b-login-modal');
            btn.addEventListener('click', function (e) {
                openModal(e.currentTarget);
            });
        }

        function replaceAddToCartButtons()
        {
            // CRITICAL: If customer-data confirms user is logged in, don't replace buttons
            if (!isRestricted) {
                return;
            }

            let isGuestMode = (activeMode === 'guest');
            let isPendingMode = (activeMode === 'pending');
            let isDisabledMode = !isGuestMode;

            // Add the appropriate body class
            document.body.classList.add(bodyClass);
            document.body.classList.add('b2b-restricted-mode');
            syncPendingPriceMarkers();
            ensurePriceGateVisible();

            let iconSvg = isGuestMode ? PDP_ICON_SVG : PENDING_ICON_SVG;

            // PDP (product detail page)
            let productAddForm = document.querySelector('.product-add-form');
            if (productAddForm) {
                let boxToCart = productAddForm.querySelector('.box-tocart');
                let qtyField = productAddForm.querySelector('.box-tocart .field.qty');
                let instantPurchase = productAddForm.querySelector('#instant-purchase');
                let addToCartBtn = productAddForm.querySelector('button.tocart, button#product-addtocart-button');
                let ssrGuestBtn = productAddForm.querySelector('.b2b-login-to-buy-btn[data-b2b-ssr="guest"]');

                if (ssrGuestBtn && isGuestMode) {
                    if (boxToCart) {
                        boxToCart.classList.add('b2b-login-to-buy-mode');
                    }
                    wireSsrGuestButton(ssrGuestBtn);
                } else if (boxToCart) {
                    boxToCart.classList.add('b2b-login-to-buy-mode');
                }

                // Always re-hide originals (Magento/align-grid may recreate or override styles).
                if (qtyField) {
                    qtyField.setAttribute('data-b2b-original-hidden', '1');
                    qtyField.style.setProperty('display', 'none', 'important');
                    qtyField.setAttribute('hidden', '');
                    qtyField.setAttribute('aria-hidden', 'true');
                }

                if (instantPurchase) {
                    instantPurchase.setAttribute('data-b2b-original-hidden', '1');
                    instantPurchase.style.setProperty('display', 'none', 'important');
                    instantPurchase.setAttribute('hidden', '');
                }

                if (addToCartBtn) {
                    addToCartBtn.setAttribute('data-b2b-original-hidden', '1');
                    addToCartBtn.style.setProperty('display', 'none', 'important');
                    addToCartBtn.setAttribute('hidden', '');
                    addToCartBtn.setAttribute('aria-hidden', 'true');
                    addToCartBtn.setAttribute('disabled', 'disabled');
                    addToCartBtn.setAttribute('tabindex', '-1');
                }

                if (!ssrGuestBtn && addToCartBtn && !productAddForm.querySelector('.b2b-login-to-buy-btn')) {
                    let pdpBtn = createLoginButton({
                        html: iconSvg + ' ' + ((config && config.pdpButtonText) ? config.pdpButtonText : 'Entrar para Comprar'),
                        disabled: isDisabledMode
                    });
                    pdpBtn.setAttribute('data-b2b-injected', '1');

                    if (isGuestMode) {
                        pdpBtn.setAttribute('aria-haspopup', 'dialog');
                        pdpBtn.setAttribute('aria-controls', 'b2b-login-modal');
                        pdpBtn.addEventListener('click', function (e) {
                            openModal(e.currentTarget);
                        });
                    }

                    addToCartBtn.parentNode.insertBefore(pdpBtn, addToCartBtn.nextSibling);
                }
            }

            // Product listings (category, search, widgets)
            document.querySelectorAll('.product-item-actions .actions-primary, .product-info-cart .actions-primary').forEach(function (actionsContainer) {
                let addBtn = actionsContainer.querySelector('button.tocart, form button.tocart');
                if (addBtn && !actionsContainer.querySelector('.b2b-login-to-buy-btn')) {
                    addBtn.setAttribute('data-b2b-original-hidden', '1');
                    addBtn.style.setProperty('display', 'none', 'important');
                    addBtn.setAttribute('hidden', '');
                    addBtn.setAttribute('aria-hidden', 'true');

                    let listingBtn = createLoginButton({
                        text: (config && config.listingButtonText) ? config.listingButtonText : 'Entrar para Comprar',
                        variantClass: 'b2b--listing',
                        disabled: isDisabledMode
                    });
                    listingBtn.setAttribute('data-b2b-injected', '1');

                    if (isGuestMode) {
                        listingBtn.setAttribute('aria-haspopup', 'dialog');
                        listingBtn.setAttribute('aria-controls', 'b2b-login-modal');
                        listingBtn.addEventListener('click', function (e) {
                            openModal(e.currentTarget);
                        });
                    }

                    actionsContainer.appendChild(listingBtn);
                }
            });
        }

        /**
         * Check customer-data and update restriction state.
         * Guest FPC HTML served to a logged-in shopper, or pending HTML served
         * to an approved B2B customer, must restore add-to-cart.
         */
        setAuthState('pending');

        function customerCanShop()
        {
            // HOTFIX B2B-AUTH (13/10): autorizacao EXPLICITA (fail-closed).
            // O servidor e a fonte da verdade; a UI apenas reflete.
            try {
                var panel = customerData.get('b2b_panel')();
                if (panel && typeof panel.can_add_to_cart === 'boolean') {
                    return panel.can_add_to_cart === true;
                }
            } catch (e) {
                // secao indisponivel — trata como nao autorizado
            }

            return false;
        }

        function releaseIfCustomerCanShop()
        {
            if (!customerCanShop()) {
                return false;
            }
            isRestricted = false;
            setAuthState('granted');
            restoreOriginalButtons();
            syncPriceBlocksAfterLogin();
            if (observerInstance) {
                observerInstance.disconnect();
                observerInstance = null;
            }
            return true;
        }

        var panelResolved = false;
        function checkAndUpdateState()
        {
            if (releaseIfCustomerCanShop()) {
                return;
            }
            try {
                var panel = customerData.get('b2b_panel')();
                if (panel && panel.is_b2b === false) {
                    // guest: o gate SSR ja esta correto - estado terminal
                    panelResolved = true;
                    setAuthState( denied);
                } else if (panel && typeof panel.can_add_to_cart === boolean) {
                    panelResolved = true;
                    setAuthState(panel.can_add_to_cart ? 'granted' : 'denied');
                }
            } catch (e) {
                // segue
            }
            // erro: a secao nunca chegou — sem bloqueio silencioso
            window.setTimeout(function () {
                if (!panelResolved) {
                    setAuthState('error');
                    var btn = document.querySelector('.b2b-login-to-buy-btn[data-b2b-injected="1"]');
                    if (btn && btn.textContent.indexOf('tente novamente') === -1) {
                        btn.textContent = 'Tente novamente';
                        btn.addEventListener('click', function () {
                            window.location.reload();
                        });
                    }
                }
            }, 10000);
        }

        function bindCustomerDataSubscribe()
        {
            try {
                customerData.get('customer').subscribe(function () {
                    releaseIfCustomerCanShop();
                });
            } catch (e) {
                // ignore
            }
            try {
                customerData.get('b2b_panel').subscribe(function () {
                    releaseIfCustomerCanShop();
                });
            } catch (e) {
                // ignore
            }
        }

        // Show pending banner if in pending mode
        if (serverMode === 'pending' && pendingBanner) {
            pendingBanner.hidden = false;
        }

        checkAndUpdateState();
        bindCustomerDataSubscribe();

        if (!isRestricted) {
            return;
        }

        // Initial run - replace buttons (may be reverted by customer-data check)
        injectTerminalHideCss();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                injectTerminalHideCss();
                replaceAddToCartButtons();
                window.setTimeout(checkAndUpdateState, 100);
            });
        } else {
            replaceAddToCartButtons();
            window.setTimeout(checkAndUpdateState, 100);
        }

        // Re-run with throttle on DOM changes
        let scheduled = false;
        function scheduleReplace()
        {
            if (scheduled || !isRestricted) {
                return;
            }
            scheduled = true;
            window.setTimeout(function () {
                scheduled = false;
                if (!isRestricted) {
                    return;
                }
                // Desconectar durante nossas próprias mutações DOM para evitar disparo
                // recursivo: cada botão injetado por replaceAddToCartButtons() causaria
                // uma nova mutação que re-acionaria este observer indefinidamente.
                if (observerInstance) {
                    observerInstance.disconnect();
                }
                replaceAddToCartButtons();
                syncPendingPriceMarkers();
                ensurePriceGateVisible();
                // Reconectar para capturar conteúdo carregado via AJAX (abas de produto)
                if (isRestricted && observerInstance) {
                    observerInstance.observe(document.body, {childList: true, subtree: true});
                }
            }, 120);
        }

        observerInstance = new MutationObserver(function (mutations) {
            for (let i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
                    scheduleReplace();
                    break;
                }
            }
        });

        observerInstance.observe(document.body, {childList: true, subtree: true});
    }

    return init;
});
