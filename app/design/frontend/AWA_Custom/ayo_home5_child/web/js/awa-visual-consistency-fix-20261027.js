/* awa-visual-consistency-fix-20261027.js â€” correÃ§Ãµes visuais via CSSOM (mecanismo definitivo do tema)
   Inclui: (C) prompt de conta 13px/16px; (B02/B06) hero 1280 + sem faixa branca na home;
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
    if (isHome()) {
      if (w >= 768) {
        /* B06: remove a faixa de 12px entre nav e banner */
        var main = D.querySelector('main.page-main') || D.querySelector('.page-main');
        if (main) { setI(main, 'padding-top', '0'); }
        /* B02/B07: banner dentro do container 1280 */
        var b = D.querySelector('.content-top-home>.top-home-content--above-fold>.banner-slider.banner-slider2') || D.querySelector('.banner-slider.banner-slider2');
        if (b) {
          setI(b, 'width', '100%');
          setI(b, 'max-width', '1280px');
          setI(b, 'margin-left', 'auto');
          setI(b, 'margin-right', 'auto');
        }
      }
    }
    /* B09: logo mobile */
    if (w < 768) {
      var lg = D.querySelector('.awa-site-header .awa-logo-lockup__mark, .awa-site-header a.logo img, .awa-site-header .logo img');
      if (lg) {
        setI(lg, 'height', '36px');
        setI(lg, 'width', 'auto');
        setI(lg, 'max-height', 'none');
      }
    }
    /* B08: Fale com Ava no padrÃ£o dos demais itens */
    if (w >= 768) {
      var ava = D.querySelector('.awa-nav-quick-links li.awa-inline-ava-desktop a, .awa-nav-quick-links .awa-inline-ava-desktop a, .awa-nav-quick-links li.awa-inline-ava a');
      if (ava) {
        setI(ava, 'font-size', '14px');
        setI(ava, 'font-weight', '600');
        setI(ava, 'padding', '0 12px');
        setI(ava, 'border-radius', '8px');
      }
    }
  }
  var tries = [250, 1200, 2800, 5000, 9000];
  for (var t = 0; t < tries.length; t++) { setTimeout(apply, tries[t]); }
  if (D.readyState === 'loading') { D.addEventListener('DOMContentLoaded', apply); }
  window.addEventListener('load', apply);
  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(apply, 160); });
  apply();
})();
