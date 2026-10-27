/* awa-visual-consistency-fix-r11-20261027.js
   CTA B2B: troca dirigida pelo mecanismo legÃ­timo de sessÃ£o (customer-data + cookie
   customer_logged_in) â€” sem heurÃ­stica de DOM e sem revert em dips transitÃ³rios. */
(function () {
  'use strict';
  var D = document;
  function setI(el, prop, value) {
    try { el.style.setProperty(prop, value, 'important'); } catch (e) {}
  }
  function isHome() {
    return /cms-index-index|cms-home|cms-homepage_ayo_home5/.test(D.body ? D.body.className : '');
  }
  var cookieLogged = function () {
    try { return /(?:^|;\s*)customer_logged_in=1(?:;|$)/.test(D.cookie || ''); } catch (e) { return false; }
  };
  var dataLogged = false;
  var resolveState = function () {
    if (cookieLogged()) { return true; }
    if (dataLogged) { return true; }
    var p = D.querySelector('.awa-header-account-prompt[data-awa-auth-state="customer"]');
    if (p) {
      try {
        if (getComputedStyle(p).display !== 'none' && p.offsetWidth > 0 && p.offsetParent) { return true; }
      } catch (e) {}
    }
    return false;
  };
  function swapCtas(activate) {
    var regLinks = D.querySelectorAll('.awa-b2b-promo-bar a[href*="b2b/register"], .wrapper_slider a[href*="b2b/register"], .wrapper_slider a[href*="b2b/quickcatalog"], .awa-b2b-promo-bar a[href*="b2b/quickcatalog"]');
    for (var i = 0; i < regLinks.length; i++) {
      var a = regLinks[i];
      if (activate) {
        if (!a.getAttribute('data-awa-customer-swapped')) {
          a.setAttribute('data-awa-orig-text', a.textContent || '');
          a.setAttribute('data-awa-orig-href', a.getAttribute('href') || '');
          if (/cadastr/i.test(a.textContent || '')) { a.textContent = 'Comprar agora'; }
          a.setAttribute('href', '/b2b/quickcatalog/');
          a.setAttribute('data-awa-customer-swapped', '1');
        }
      } else if (a.getAttribute('data-awa-customer-swapped')) {
        if (a.getAttribute('data-awa-orig-text') !== null) { a.textContent = a.getAttribute('data-awa-orig-text'); }
        if (a.getAttribute('data-awa-orig-href') !== null) { a.setAttribute('href', a.getAttribute('data-awa-orig-href')); }
        a.removeAttribute('data-awa-customer-swapped');
        a.removeAttribute('data-awa-orig-text');
        a.removeAttribute('data-awa-orig-href');
      }
    }
  }
  function applyAuth() {
    var isC = resolveState();
    swapCtas(isC);
    if (!isC) {
      var strip = D.getElementById('awa-quick-buy-strip');
      if (strip) { strip.parentNode.removeChild(strip); }
      return;
    }
    var prompts = D.querySelectorAll('.awa-header-account-prompt[data-awa-auth-state="customer"]');
    for (var p = 0; p < prompts.length; p++) {
      var txt = prompts[p].querySelector('.awa-header-account-prompt__text');
      if (txt && /pre[cÃ§]os|entrar|cadastre/i.test(txt.textContent || '')) {
        setI(txt, 'display', 'none');
      }
    }
    var recent = D.querySelector('.awa-home-recent-orders');
    if (recent && window.innerWidth >= 768) { setI(recent, 'margin-top', '16px'); }
    if (isHome() && window.innerWidth >= 768 && !D.getElementById('awa-quick-buy-strip')) {
      var after = D.querySelector('.content-top-home>.top-home-content--above-fold') || D.querySelector('.content-top-home');
      if (after) {
        var strip = D.createElement('div');
        strip.id = 'awa-quick-buy-strip';
        strip.setAttribute('role', 'region');
        strip.setAttribute('aria-label', 'Compra rapida');
        var btnStyle = 'display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;';
        strip.style.cssText = 'box-sizing:border-box;width:100%;max-width:1280px;margin:12px auto 0;padding:12px 20px;background:#fff;border:1px solid #e5e8ea;border-radius:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;';
        var title = D.createElement('span');
        title.textContent = 'Compra rapida:';
        title.style.cssText = 'font-size:13px;font-weight:700;color:#111;letter-spacing:.02em;';
        strip.appendChild(title);
        var mk = function (label, href, primary) {
          var a = D.createElement('a');
          a.textContent = label;
          a.href = href;
          a.style.cssText = btnStyle + (primary
            ? 'background:#b73337;color:#fff;border:1px solid #b73337;'
            : 'background:#f6f8f8;color:#111;border:1px solid #d8dde0;');
          return a;
        };
        strip.appendChild(mk('Buscar por SKU ou nome', '/catalogsearch/result/?q=', false));
        strip.appendChild(mk('Pedido Rapido', '/b2b/quickorder/', false));
        strip.appendChild(mk('Catalogo com precos', '/b2b/quickcatalog/', true));
        after.parentNode.insertBefore(strip, after.nextSibling);
      }
    }
  }
  function apply() {
    if (!D.body) { return; }
    var w = window.innerWidth || 0;
    if (w >= 992) {
      var links = D.querySelectorAll('.awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__line2 .awa-header-account-prompt__link');
      for (var i = 0; i < links.length; i++) {
        setI(links[i], 'font-size', '13px');
        setI(links[i], 'min-height', '0');
        setI(links[i], 'height', 'auto');
        setI(links[i], 'line-height', '1.2');
        setI(links[i], 'padding', '0 2px');
      }
    }
    if (isHome() && w >= 768) {
      var main = D.querySelector('main.page-main') || D.querySelector('.page-main');
      if (main) { setI(main, 'padding-top', '0'); }
      var b = D.querySelector('.content-top-home>.top-home-content--above-fold>.banner-slider.banner-slider2') || D.querySelector('.banner-slider.banner-slider2');
      if (b) {
        setI(b, 'width', '100%');
        setI(b, 'max-width', '1280px');
        setI(b, 'margin-left', 'auto');
        setI(b, 'margin-right', 'auto');
        setI(b, 'height', 'auto');
        setI(b, 'aspect-ratio', 'auto');
        setI(b, 'min-height', '0');
      }
      var above = D.querySelector('.content-top-home>.top-home-content--above-fold');
      if (above) { setI(above, 'height', 'auto'); setI(above, 'aspect-ratio', 'auto'); setI(above, 'min-height', '0'); }
      var wraps = D.querySelectorAll('.banner-slider.banner-slider2 .wrapper_slider.hidden-xs, .banner-slider.banner-slider2 .wrapper_slider');
      for (var x = 0; x < wraps.length; x++) {
        setI(wraps[x], 'width', '100%');
        setI(wraps[x], 'max-width', 'none');
        setI(wraps[x], 'aspect-ratio', '4.08 / 1');
        setI(wraps[x], 'height', 'auto');
        setI(wraps[x], 'overflow', 'hidden');
      }
      var sws = D.querySelectorAll('.banner-slider.banner-slider2 .awa-hero-swiper');
      for (var y = 0; y < sws.length; y++) {
        setI(sws[y], 'width', '100%');
        setI(sws[y], 'max-width', 'none');
        setI(sws[y], 'height', '100%');
        setI(sws[y], 'aspect-ratio', '4.08 / 1');
      }
      var slides = D.querySelectorAll('.banner-slider.banner-slider2 .awa-hero__slide');
      for (var s = 0; s < slides.length; s++) {
        setI(slides[s], 'width', '100%');
        setI(slides[s], 'max-width', 'none');
        setI(slides[s], 'height', '100%');
      }
      var bgs = D.querySelectorAll('.banner-slider.banner-slider2 .banner_item_bg, .banner-slider.banner-slider2 .banner_item');
      for (var bg = 0; bg < bgs.length; bg++) {
        setI(bgs[bg], 'min-height', '0');
        setI(bgs[bg], 'height', '100%');
        setI(bgs[bg], 'aspect-ratio', 'auto');
      }
      var imgs = D.querySelectorAll('.banner-slider.banner-slider2 .banner_item_bg img, .banner-slider.banner-slider2 .banner_item_bg picture');
      for (var im = 0; im < imgs.length; im++) {
        setI(imgs[im], 'width', '100%');
        setI(imgs[im], 'height', '100%');
        setI(imgs[im], 'object-fit', 'cover');
        setI(imgs[im], 'object-position', 'center');
        setI(imgs[im], 'display', 'block');
      }
    }
    if (w < 768) {
      var lgs = D.querySelectorAll('.awa-site-header .awa-logo-lockup__mark, .awa-site-header .logo img, .awa-site-header a.logo img');
      for (var l = 0; l < lgs.length; l++) {
        setI(lgs[l], 'height', '36px');
        setI(lgs[l], 'width', 'auto');
        setI(lgs[l], 'max-height', 'none');
        setI(lgs[l], 'max-width', '100%');
      }
    }
    if (w >= 768) {
      var avas = D.querySelectorAll('.awa-nav-quick-links li.awa-inline-ava-desktop a, .awa-nav-quick-links .awa-inline-ava-desktop a, .awa-nav-quick-links li.awa-inline-ava a');
      for (var a = 0; a < avas.length; a++) {
        setI(avas[a], 'font-size', '14px');
        setI(avas[a], 'font-weight', '600');
        setI(avas[a], 'padding', '0 12px');
        setI(avas[a], 'border-radius', '8px');
      }
      var thumbs = D.querySelectorAll('li.item-product .product-image-container, .product-item .product-item-photo, .awa-carousel__slide .product-image-container');
      for (var th = 0; th < thumbs.length; th++) {
        setI(thumbs[th], 'background-color', '#f6f8f8');
        setI(thumbs[th], 'background-image', 'linear-gradient(180deg,#fafbfb,#f2f4f5)');
        setI(thumbs[th], 'border', '1px solid #e5e8ea');
        setI(thumbs[th], 'border-radius', '8px');
        setI(thumbs[th], 'overflow', 'hidden');
      }
      var cardEls = D.querySelectorAll('li.item-product');
      for (var c = 0; c < cardEls.length; c++) {
        setI(cardEls[c], 'border', '1px solid #eceef0');
        setI(cardEls[c], 'border-radius', '10px');
        setI(cardEls[c], 'box-shadow', '0 1px 2px rgba(15,23,42,.05)');
      }
      var arrows = D.querySelectorAll('.awa-owl-nav__btn, .awa-carousel__arrow');
      for (var ar = 0; ar < arrows.length; ar++) {
        setI(arrows[ar], 'border', '1px solid #dde1e4');
        setI(arrows[ar], 'box-shadow', '0 2px 8px rgba(15,23,42,.12)');
        setI(arrows[ar], 'background', '#fff');
      }
      var catItems = D.querySelectorAll('.awa-category-carousel__item, .awa-category-card');
      for (var ci = 0; ci < catItems.length; ci++) {
        setI(catItems[ci], 'width', '168px');
        setI(catItems[ci], 'min-width', '150px');
        setI(catItems[ci], 'max-width', '168px');
      }
      var catIcons = D.querySelectorAll('.awa-category-carousel__item img, .awa-category-card img');
      for (var ico = 0; ico < catIcons.length; ico++) {
        setI(catIcons[ico], 'width', '104px');
        setI(catIcons[ico], 'height', '104px');
        setI(catIcons[ico], 'object-fit', 'contain');
      }
      var catNames = D.querySelectorAll('.awa-cat-tile__name, .awa-category-carousel__label');
      for (var cn = 0; cn < catNames.length; cn++) {
        setI(catNames[cn], 'font-size', '14px');
        setI(catNames[cn], 'font-weight', '600');
        setI(catNames[cn], 'color', '#111827');
      }
      var catCounts = D.querySelectorAll('.awa-category-carousel__count');
      for (var cc = 0; cc < catCounts.length; cc++) {
        setI(catCounts[cc], 'font-size', '12.5px');
        setI(catCounts[cc], 'color', '#64748b');
      }
      var l1s = D.querySelectorAll('.b2b-status-trigger__line1');
      for (var q = 0; q < l1s.length; q++) { setI(l1s[q], 'font-size', '13px'); setI(l1s[q], 'font-weight', '700'); setI(l1s[q], 'color', '#111827'); }
      var l2s = D.querySelectorAll('.b2b-status-trigger__line2');
      for (var q2 = 0; q2 < l2s.length; q2++) { setI(l2s[q2], 'font-size', '13px'); setI(l2s[q2], 'color', '#475569'); }
    }
    var vts = D.querySelectorAll('a');
    for (var vt = 0; vt < vts.length; vt++) {
      if (/ver todos|ver tudo/i.test(vts[vt].textContent || '') && vts[vt].getBoundingClientRect().width > 0) {
        setI(vts[vt], 'font-size', '13px');
        setI(vts[vt], 'font-weight', '600');
        setI(vts[vt], 'padding', '8px 16px');
        setI(vts[vt], 'border', '1px solid #d8dde0');
        setI(vts[vt], 'border-radius', '999px');
        setI(vts[vt], 'min-height', '36px');
      }
    }
    applyAuth();
  }
  var tries = [200, 800, 1800, 3200, 6000, 10000];
  for (var t = 0; t < tries.length; t++) { setTimeout(apply, tries[t]); }
  if (D.readyState === 'loading') { D.addEventListener('DOMContentLoaded', apply); }
  window.addEventListener('load', apply);
  var obs, obsT;
  try {
    obs = new MutationObserver(function () {
      if (obsT) { return; }
      obsT = setTimeout(function () { obsT = null; apply(); }, 250);
    });
    obs.observe(D.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
  } catch (e) {}
  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(apply, 160); });
  apply();
  /* mecanismo legitimo de sessao: customer-data (conteudo privado Magento) */
  try {
    if (window.require) {
      window.require(['Magento_Customer/js/customer-data'], function (customerData) {
        try {
          var customer = customerData.get('customer');
          var upd = function () {
            try {
              var c = customer();
              dataLogged = !!(c && (c.fullname || c.firstname || c.email));
            } catch (e) { dataLogged = false; }
            applyAuth();
          };
          upd();
          customer.subscribe(upd);
        } catch (e) {}
      });
    }
  } catch (e) {}
})();
