(function(){"use strict";var distillGridId="awa-header-distill-mobile-grid-20260616c";function applyMobileGrid(){if(document.getElementById(distillGridId)){return;}if(window.innerWidth>767)return;var grid=document.querySelector(".awa-main-header__inner.wp-header");var sc=document.querySelector(".header-wrapper-sticky .awa-header-search-col");if(grid){["grid-template-columns","grid-template-areas","grid-template-rows","gap","padding","height","max-height"].forEach(function(p){grid.style.removeProperty(p);});if(!grid.getAttribute("style"))grid.removeAttribute("style");}if(sc){["grid-column","width","height","min-height","max-height","display"].forEach(function(p){sc.style.removeProperty(p);});if(!sc.getAttribute("style"))sc.removeAttribute("style");var form=sc&&sc.querySelector("form#search_mini_form");var input=sc&&sc.querySelector("input#search");if(form){["height","min-height","max-height","width","max-width","box-sizing","display","grid-template-columns"].forEach(function(p){form.style.removeProperty(p);});if(!form.getAttribute("style"))form.removeAttribute("style");}if(input){input.style.removeProperty("height");input.style.removeProperty("min-height");input.style.removeProperty("width");if(!input.getAttribute("style"))input.removeAttribute("style");}if(sc){sc.style.removeProperty("display");if(!sc.getAttribute("style"))sc.removeAttribute("style");}var brand=document.querySelector(".awa-site-header .awa-header-brand-cell,.awa-site-header .col-md-2.awa-header-brand");if(brand){brand.style.removeProperty("min-width");brand.style.removeProperty("width");if(!brand.getAttribute("style"))brand.removeAttribute("style");}}}function syncAccountVariant(){var prompt=document.querySelector(".awa-site-header .awa-header-account-prompt");if(window.innerWidth<=991&&prompt){prompt.querySelectorAll(".awa-header-account-prompt__icon,.awa-header-account-prompt__text,.awa-header-account-prompt__guest").forEach(function(el){el.style.setProperty("display","none","important");});var ml=prompt.querySelector(".awa-header-account-prompt__mobile-link");if(ml){ml.style.setProperty("display","inline-flex","important");ml.style.setProperty("visibility","visible","important");}}else if(prompt){prompt.querySelectorAll(".awa-header-account-prompt__icon,.awa-header-account-prompt__text,.awa-header-account-prompt__guest").forEach(function(el){el.style.removeProperty("display");el.style.removeProperty("visibility");});var mlClear=prompt.querySelector(".awa-header-account-prompt__mobile-link");if(mlClear){mlClear.style.removeProperty("display");mlClear.style.removeProperty("visibility");if(!mlClear.getAttribute("style"))mlClear.removeAttribute("style");}}}function applyHeaderVisFix(){var prompt=document.querySelector(".awa-site-header .awa-header-account-prompt");if(prompt&&window.innerWidth>=992){prompt.style.removeProperty("max-width");prompt.style.removeProperty("overflow");if(!prompt.getAttribute("style"))prompt.removeAttribute("style");prompt.style.setProperty("border","0","important");prompt.style.setProperty("border-radius","0","important");prompt.style.setProperty("background","transparent","important");prompt.style.setProperty("padding","0","important");prompt.style.setProperty("box-shadow","none","important");var reg=prompt.querySelector(".awa-header-account-prompt__link--register");if(reg){reg.style.setProperty("background","transparent","important");reg.style.setProperty("background-color","transparent","important");reg.style.setProperty("color","var(--awa-text, CanvasText)","important");reg.style.setProperty("border","0","important");reg.style.setProperty("border-radius","0","important");reg.style.setProperty("padding","0 2px","important");}}syncAccountVariant();var fallback=document.querySelector(".awa-header-minicart .awa-header-cart-fallback");var hasShowcart=document.querySelector(".awa-header-minicart .minicart-wrapper .showcart");if(fallback&&hasShowcart){fallback.style.removeProperty("display");fallback.style.removeProperty("visibility");fallback.style.removeProperty("pointer-events");if(!fallback.getAttribute("style"))fallback.removeAttribute("style");}var mainContainer=document.querySelector(".awa-site-header .header-main>.container,.awa-site-header .header_main>.container");if(mainContainer&&window.innerWidth<=767){["padding","padding-inline","padding-left","padding-right"].forEach(function(p){mainContainer.style.removeProperty(p);});if(!mainContainer.getAttribute("style"))mainContainer.removeAttribute("style");}var toggle=document.querySelector(".awa-header-mobile-toggle,.action.nav-toggle,[data-action=\"toggle-nav\"]");if(toggle&&window.innerWidth<=767){["position","top","right","left","transform"].forEach(function(p){toggle.style.removeProperty(p);});if(!toggle.getAttribute("style"))toggle.removeAttribute("style");}applyMobileGrid();}var accountVariantBound=false;function boot(){applyHeaderVisFix();if(accountVariantBound)return;accountVariantBound=true;var compact=window.matchMedia("(max-width: 991px)");if(compact.addEventListener){compact.addEventListener("change",syncAccountVariant);}else if(compact.addListener){compact.addListener(syncAccountVariant);}}document.addEventListener("DOMContentLoaded",boot,{once:true});window.addEventListener("load",boot,{once:true,passive:true});})();
/* Keep the desktop sticky image and visible tagline inside the existing brand slot. */
(function (w, d) {
    'use strict';
    var queued = false, original = null, image = null;
    var properties = {'height':'30px','max-height':'30px','min-height':'0px','width':'auto','min-width':'0px'};
    function update() {
        queued = false;
        var header = d.querySelector('.awa-site-header');
        var current = header && header.querySelector('.awa-logo-lockup__mark');
        if (!current) { return; }
        if (current !== image) { image = current; original = null; }
        if (w.innerWidth >= 992 && header.classList.contains('awa-header-condensed')) {
            if (!original) {
                original = {};
                Object.keys(properties).forEach(function (key) { original[key] = [image.style.getPropertyValue(key), image.style.getPropertyPriority(key)]; });
            }
            Object.keys(properties).forEach(function (key) { image.style.setProperty(key, properties[key], 'important'); });
        } else if (original) {
            Object.keys(properties).forEach(function (key) {
                if (image.style.getPropertyValue(key) === properties[key]) {
                    if (original[key][0]) { image.style.setProperty(key,original[key][0],original[key][1]); }
                    else { image.style.removeProperty(key); }
                }
            });
            original = null;
        }
    }
    function schedule() { if (!queued) { queued = true; w.requestAnimationFrame(update); } }
    function start() {
        var header = d.querySelector('.awa-site-header');
        if (!header) { return; }
        new MutationObserver(schedule).observe(header,{attributes:true,attributeFilter:['class']});
        w.addEventListener('resize',schedule,{passive:true});
        schedule();
    }
    if(d.readyState==='loading'){d.addEventListener('DOMContentLoaded',start,{once:true});}else{start();}
})(window, document);
