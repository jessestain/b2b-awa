/* awa-visual-consistency-fix-r15-20261027.js — pendências P01-P07 */
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
  var stateStyleInjected = false;
  function injectStateStyles() {
    if (stateStyleInjected) { return; }
    stateStyleInjected = true;
    try {
      var st = D.createElement('style');
      st.id = 'awa-visual-fix-states';
      st.textContent = ''
        + '.awa-category-carousel__prev:focus-visible,.awa-category-carousel__next:focus-visible,.awa-carousel__arrow:focus-visible{outline:2px solid #b73337!important;outline-offset:2px!important}'
        + '.awa-category-carousel__prev:hover:not(.is-disabled),.awa-category-carousel__next:hover:not(.is-disabled){background:#f1f4f6!important;border-color:#b8c0c6!important}'
        + '.awa-category-carousel__prev.is-disabled,.awa-category-carousel__next.is-disabled{opacity:.45!important;cursor:not-allowed!important;background:#f6f8f8!important}'
        + '.awa-category-carousel__count{white-space:normal!important;overflow:visible!important;text-overflow:clip!important;line-height:1.3!important}'
        + '.awa-category-carousel__item .awa-category-carousel__icon picture,.awa-category-carousel__item .awa-category-carousel__icon img{width:100%!important;height:100%!important;object-fit:contain!important}';
      D.head.appendChild(st);
    } catch (e) {}
  }
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
    injectStateStyles();
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
      if (txt && /pre[cç]os|entrar|cadastre/i.test(txt.textContent || '')) {
        setI(txt, 'display', 'none');
      }
    }
    var recent = D.querySelector('.awa-home-recent-orders');
    if (recent) { setI(recent, 'margin-top', window.innerWidth >= 768 ? '16px' : '12px'); }
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
        /* P01: produtos com preco autorizado na primeira dobra (reuso do Mais Vendidos) */
        var shelves = D.querySelector('.awa-carousel-section');
        if (shelves && !shelves.getAttribute('data-awa-first-fold')) {
          shelves.setAttribute('data-awa-first-fold', '1');
          try { after.parentNode.insertBefore(shelves, strip.nextSibling); } catch (e) {}
        }
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
      /* P06: CTA alinhado a esquerda com a mensagem do slide institucional */
      var ctas = D.querySelectorAll('.wrapper_slider.hidden-xs a.awa-hero-slide__cta');
      for (var ct = 0; ct < ctas.length; ct++) {
        setI(ctas[ct], 'left', '96px');
        setI(ctas[ct], 'right', 'auto');
        setI(ctas[ct], 'transform', 'none');
        setI(ctas[ct], 'bottom', '46px');
      }
    }
    /* paginacao separada (todas as larguras) */
    var paginations = D.querySelectorAll('.wrapper_slider .swiper-pagination, .wrapper_slider [class*="pagination"]');
    for (var pg = 0; pg < paginations.length; pg++) {
      setI(paginations[pg], 'bottom', '6px');
      setI(paginations[pg], 'top', 'auto');
      setI(paginations[pg], 'height', 'auto');
    }
    if (w < 768) {
      var lgs = D.querySelectorAll('.awa-site-header .awa-logo-lockup__mark, .awa-site-header .logo img, .awa-site-header a.logo img');
      for (var l = 0; l < lgs.length; l++) {
        setI(lgs[l], 'height', '36px');
        setI(lgs[l], 'width', 'auto');
        setI(lgs[l], 'max-height', 'none');
        setI(lgs[l], 'max-width', '100%');
      }
      /* mobile: areas independentes para CTA/paginacao (regressao) */
      var mctas = D.querySelectorAll('.wrapper_slider.visible-xs a.awa-hero-slide__cta');
      for (var mc = 0; mc < mctas.length; mc++) {
        setI(mctas[mc], 'bottom', '44px');
        setI(mctas[mc], 'left', '16px');
        setI(mctas[mc], 'right', 'auto');
        setI(mctas[mc], 'transform', 'none');
      }
      var mcaps = D.querySelectorAll('.wrapper_slider.visible-xs .awa-hero-mobile-caption');
      for (var cp = 0; cp < mcaps.length; cp++) {
        setI(mcaps[cp], 'bottom', '42px');
        setI(mcaps[cp], 'top', 'auto');
      }
    }
    if (w >= 768) {
      /* P07: Fale com Ava sem borda exclusiva */
      var avas = D.querySelectorAll('.awa-nav-quick-links li.awa-inline-ava-desktop a, .awa-nav-quick-links .awa-inline-ava-desktop a, .awa-nav-quick-links li.awa-inline-ava a');
      for (var a = 0; a < avas.length; a++) {
        setI(avas[a], 'font-size', '14px');
        setI(avas[a], 'font-weight', '600');
        setI(avas[a], 'padding', '0 12px');
        setI(avas[a], 'border-radius', '8px');
        setI(avas[a], 'border', '0');
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
      var arrows = D.querySelectorAll('.awa-owl-nav__btn, .awa-carousel__arrow, .awa-category-carousel__prev, .awa-category-carousel__next');
      for (var ar = 0; ar < arrows.length; ar++) {
        setI(arrows[ar], 'border', '1px solid #dde1e4');
        setI(arrows[ar], 'box-shadow', '0 2px 8px rgba(15,23,42,.12)');
        setI(arrows[ar], 'background', '#fff');
        setI(arrows[ar], 'min-width', '44px');
        setI(arrows[ar], 'min-height', '44px');
      }
      /* P02: cards de categoria - largura por breakpoint sem corte */
      var catItems = D.querySelectorAll('.awa-category-carousel__item, .awa-category-card');
      for (var ci = 0; ci < catItems.length; ci++) {
        setI(catItems[ci], 'width', '146px');
        setI(catItems[ci], 'min-width', '140px');
        setI(catItems[ci], 'max-width', '146px');
      }
      /* P03: miniatura preenche a area 104 */
      var icons = D.querySelectorAll('.awa-category-carousel__icon');
      for (var ic = 0; ic < icons.length; ic++) {
        setI(icons[ic], 'width', '104px');
        setI(icons[ic], 'height', '104px');
        setI(icons[ic], 'min-width', '104px');
        setI(icons[ic], 'min-height', '104px');
        setI(icons[ic], 'display', 'flex');
        setI(icons[ic], 'align-items', 'center');
        setI(icons[ic], 'justify-content', 'center');
        setI(icons[ic], 'background', 'linear-gradient(180deg,#fafbfb,#f2f4f5)');
        /* AWA 2026-10-27 (auditoria 20h12-20h16): moldura unica no card - borda do icone removida (contorno duplo). */
        setI(icons[ic], 'border', '0');
        setI(icons[ic], 'border-radius', '8px');
        setI(icons[ic], 'overflow', 'hidden');
      }
      var iconImgs = D.querySelectorAll('.awa-category-carousel__icon img');
      for (var ii = 0; ii < iconImgs.length; ii++) {
        setI(iconImgs[ii], 'width', '96px');
        setI(iconImgs[ii], 'height', '96px');
        setI(iconImgs[ii], 'max-width', '96px');
        setI(iconImgs[ii], 'max-height', '96px');
        setI(iconImgs[ii], 'object-fit', 'contain');
      }
      /* P04: tiles de categoria - superficie unica sem molduras duplicadas */
      var tiles = D.querySelectorAll('.awa-category-carousel__item, .awa-category-card');
      for (var tl = 0; tl < tiles.length; tl++) {
        setI(tiles[tl], 'border', '1px solid #eceef0');
        setI(tiles[tl], 'border-radius', '10px');
        setI(tiles[tl], 'background', '#fff');
        setI(tiles[tl], 'box-shadow', '0 1px 2px rgba(15,23,42,.05)');
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
