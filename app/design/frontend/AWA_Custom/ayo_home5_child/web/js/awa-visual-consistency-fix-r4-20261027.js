/* awa-visual-consistency-fix-r3-20261027.js â€” correÃ§Ãµes visuais via CSSOM (mecanismo definitivo do tema)
   Inclui: (C) prompt de conta 13px/16px; (B02/B06) hero 1280 com aspect-ratio + sem faixa branca;
   (B09) logo mobile; (B08) item "Fale com Ava" padronizado. */
(function () {
  'use strict';
  var D = document;
  function setI(el, prop, value) {
    try { el.style.setProperty(prop, value, 'important'); } catch (e) {}
  }
  function isHome() {
    return /cms-index-index|cms-home|cms-homepage_ayo_home5/.test(D.body ? D.body.className : '');
  }
  function apply() {
    if (!D.body) { return; }
    var w = window.innerWidth || 0;
    /* C: prompt de conta uniforme 13px/16px (>=992) */
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
      /* B06: remove a faixa de 12px entre nav e banner */
      var main = D.querySelector('main.page-main') || D.querySelector('.page-main');
      if (main) { setI(main, 'padding-top', '0'); }
      /* B02/B07: banner dentro do container 1280 com aspect-ratio fixo */
      var b = D.querySelector('.content-top-home>.top-home-content--above-fold>.banner-slider.banner-slider2') || D.querySelector('.banner-slider.banner-slider2');
      if (b) {
        setI(b, 'width', '100%');
        setI(b, 'max-width', '1280px');
        setI(b, 'margin-left', 'auto');
        setI(b, 'margin-right', 'auto');
      }
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
    }

    /* LOTE 2: cards PLP/shelves — superficie, borda, setas (B17 B18 B21 B22) */
    if (w >= 768) {
      var thumbs = D.querySelectorAll('li.item-product .product-image-container, .product-item .product-item-photo, .awa-carousel__slide .product-image-container');
      for (var th = 0; th < thumbs.length; th++) {
        setI(thumbs[th], 'background', 'linear-gradient(180deg,#fafbfb,#f2f4f5)');
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
    }
    /* B09: logo mobile */
    if (w < 768) {
      var lgs = D.querySelectorAll('.awa-site-header .awa-logo-lockup__mark, .awa-site-header .logo img, .awa-site-header a.logo img');
      for (var l = 0; l < lgs.length; l++) {
        setI(lgs[l], 'height', '36px');
        setI(lgs[l], 'width', 'auto');
        setI(lgs[l], 'max-height', 'none');
        setI(lgs[l], 'max-width', 'none');
      }
    }
    /* B08: Fale com Ava no padrÃ£o dos demais itens */
    if (w >= 768) {
      var avas = D.querySelectorAll('.awa-nav-quick-links li.awa-inline-ava-desktop a, .awa-nav-quick-links .awa-inline-ava-desktop a, .awa-nav-quick-links li.awa-inline-ava a');
      for (var a = 0; a < avas.length; a++) {
        setI(avas[a], 'font-size', '14px');
        setI(avas[a], 'font-weight', '600');
        setI(avas[a], 'padding', '0 12px');
        setI(avas[a], 'border-radius', '8px');
      }
    }
  }
  var tries = [200, 800, 1800, 3200, 6000, 10000];
  for (var t = 0; t < tries.length; t++) { setTimeout(apply, tries[t]); }
  if (D.readyState === 'loading') { D.addEventListener('DOMContentLoaded', apply); }
  window.addEventListener('load', apply);
  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(apply, 160); });
  apply();
})();
