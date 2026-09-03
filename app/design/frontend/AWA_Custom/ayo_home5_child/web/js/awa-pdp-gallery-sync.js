/**
 * AWA PDP — sincroniza altura da galeria Fotorama (stage + thumb-nav).
 * Contém a imagem no stage (object-fit) e reserva espaço para o nav,
 * evitando overflow da img sobre as thumbnails.
 */
(function (window, document) {
    'use strict';

    if (window.__awaPdpGallerySyncInit) {
        return;
    }
    window.__awaPdpGallerySyncInit = true;

    /* shell15 (2026-08-02): shell12 raised cap to 900 → stage=colW (615) and
       undid hopt (stage 440 / ph ~546). CDP wasteInfo ~211px + user "aproveita
       espacamentos". Restore compact caps; object-fit:contain handles letterbox. */
    /* AWA visual-audit 2026-08-26: 360px + nav 88 fazia .product.media=444
       ∩ FAB e empurrava o CTA sobre a tabbar (CDP 390). 300 + 88 = 388. */
    var MAX_STAGE_DESKTOP_PX = 528;
    var MAX_STAGE_MOBILE_PX = 300;
    var MAX_NAV_PX = 88;

    function getMediaColumn() {
        return document.querySelector('.catalog-product-view .product.media');
    }

    function getActiveStageFrame(stage) {
        return stage.querySelector('.fotorama__stage__frame.fotorama__active') ||
            stage.querySelector('.fotorama__stage__frame');
    }

    function getMaxStageHeight(wrap, media, frame) {
        var colW = (media && media.clientWidth) || (wrap && wrap.clientWidth) || 600;
        var maxCap = window.matchMedia('(max-width: 767px)').matches
            ? MAX_STAGE_MOBILE_PX
            : MAX_STAGE_DESKTOP_PX;
        var targetH = colW;
        var img = getFrameImage(frame);
        if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
            targetH = Math.round(colW * (img.naturalHeight / img.naturalWidth));
        }
        return Math.max(240, Math.min(targetH, maxCap));
    }

    function syncShaftAndFrames(stage, heightPx) {
        if (!stage || heightPx <= 0) {
            return;
        }

        stage.querySelectorAll('.fotorama__stage__shaft, .fotorama__stage__frame').forEach(function (el) {
            el.style.setProperty('height', heightPx + 'px', 'important');
            el.style.setProperty('max-height', heightPx + 'px', 'important');
            el.style.setProperty('overflow', 'hidden', 'important');
        });
    }

    function getFrameImage(frame) {
        if (!frame) {
            return null;
        }

        return frame.querySelector('img.fotorama__img--full') ||
            frame.querySelector('img.fotorama__img');
    }

    var chromaCache = Object.create(null);

    function punchStudioWhiteToAlpha(img) {
        if (!img || !img.naturalWidth || !img.naturalHeight) {
            return null;
        }

        var sourceSrc = img.getAttribute('data-awa-fs-src') || img.currentSrc || img.src;
        if (!sourceSrc || sourceSrc.indexOf('data:') === 0) {
            return sourceSrc && sourceSrc.indexOf('data:') === 0 ? sourceSrc : null;
        }
        if (chromaCache[sourceSrc]) {
            return chromaCache[sourceSrc];
        }

        try {
            var nw = img.naturalWidth;
            var nh = img.naturalHeight;
            var canvas = document.createElement('canvas');
            canvas.width = nw;
            canvas.height = nh;
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            if (!ctx) {
                return null;
            }
            ctx.drawImage(img, 0, 0, nw, nh);
            var imageData = ctx.getImageData(0, 0, nw, nh);
            var d = imageData.data;
            var i, r, g, b, a, whiteness, soft;
            for (i = 0; i < d.length; i += 4) {
                r = d[i];
                g = d[i + 1];
                b = d[i + 2];
                a = d[i + 3];
                if (a < 8) {
                    continue;
                }
                if (r >= 248 && g >= 248 && b >= 248) {
                    d[i + 3] = 0;
                } else if (r >= 232 && g >= 232 && b >= 232) {
                    whiteness = (r + g + b) / 3;
                    soft = (255 - whiteness) / 23;
                    d[i + 3] = Math.max(0, Math.min(a, Math.round(a * soft)));
                }
            }
            ctx.putImageData(imageData, 0, 0);
            var punched = canvas.toDataURL('image/png');
            chromaCache[sourceSrc] = punched;
            return punched;
        } catch (e) {
            return null;
        }
    }

    function applyFullscreenChroma(img) {
        if (!img || img.getAttribute('data-awa-fs-chroma') === '1') {
            return false;
        }

        var original = img.getAttribute('data-awa-fs-src') || img.currentSrc || img.src;
        if (!original || original.indexOf('data:') === 0) {
            return false;
        }

        img.setAttribute('data-awa-fs-src', original);
        var punched = punchStudioWhiteToAlpha(img);
        if (!punched) {
            return false;
        }

        img.src = punched;
        img.setAttribute('data-awa-fs-chroma', '1');
        return true;
    }

    function restoreFullscreenChroma(root) {
        if (!root) {
            return;
        }
        root.querySelectorAll('img[data-awa-fs-src], img[data-awa-fs-chroma]').forEach(function (img) {
            var original = img.getAttribute('data-awa-fs-src');
            if (original && original.indexOf('data:') !== 0) {
                img.src = original;
            }
            img.removeAttribute('data-awa-fs-chroma');
            img.removeAttribute('data-awa-fs-src');
        });
    }

    var FS_LAYOUT_PROPS = [
        'display', 'align-items', 'justify-content', 'inset', 'width', 'height',
        'max-width', 'max-height', 'min-height', 'overflow', 'background',
        'background-color', 'position', 'left', 'top', 'right', 'bottom',
        'margin', 'transform', 'z-index', 'visibility', 'opacity', 'pointer-events'
    ];

    var FS_IMG_PROPS = [
        'max-height', 'max-width', 'height', 'width', 'object-fit',
        'top', 'right', 'bottom', 'left', 'margin', 'transform', 'position'
    ];

    var FS_CTRL_PROPS = [
        'position', 'z-index', 'width', 'height', 'margin', 'top', 'right',
        'left', 'bottom', 'transform', 'display', 'pointer-events',
        'visibility', 'opacity'
    ];

    function clearInlineProps(el, props) {
        if (!el || !el.style) {
            return;
        }
        props.forEach(function (prop) {
            el.style.removeProperty(prop);
        });
    }

    /* shell19: syncFullscreenGallery paints #020617 + 100vh + transparent
       stage/chroma. Without this, cancelFullScreen leaves a black PDP shell. */
    function restoreFullscreenLayout(root) {
        var item = root || document.querySelector('.fotorama-item');
        if (!item) {
            return;
        }

        clearInlineProps(item, FS_LAYOUT_PROPS);

        item.querySelectorAll(
            '.fotorama__wrap, .fotorama__stage, .fotorama__stage__shaft, .fotorama__stage__frame'
        ).forEach(function (el) {
            clearInlineProps(el, FS_LAYOUT_PROPS);
        });

        item.querySelectorAll('img.fotorama__img, img.fotorama__img--full').forEach(function (img) {
            clearInlineProps(img, FS_IMG_PROPS);
        });

        item.querySelectorAll(
            '.fotorama__fullscreen-icon, .fotorama__zoom-in, .fotorama__zoom-out, .fotorama__arr'
        ).forEach(function (el) {
            clearInlineProps(el, FS_CTRL_PROPS);
            el.removeAttribute('data-awa-fs-arr');
        });
    }

    function exitFullscreenCleanup(root) {
        var item = root ||
            document.querySelector('.fotorama-item.fotorama--fullscreen') ||
            document.querySelector('.catalog-product-view .fotorama-item') ||
            document.querySelector('.fotorama-item');

        restoreFullscreenChroma(item);
        restoreFullscreenLayout(item);
        restoreFullscreenNav();
    }

    function containImageInFrame(frame, frameHeight, opts) {
        var img = getFrameImage(frame);
        if (!img || frameHeight <= 0) {
            return 0;
        }

        var naturalH = img.naturalHeight || img.offsetHeight;
        var naturalW = img.naturalWidth || img.offsetWidth;
        if (naturalH <= 0 || naturalW <= 0) {
            return frameHeight;
        }

        var frameW = frame.offsetWidth || frame.parentElement && frame.parentElement.offsetWidth || 0;
        if (frameW <= 0) {
            return frameHeight;
        }

        var scale = Math.min(frameW / naturalW, frameHeight / naturalH, 1);
        var fittedH = Math.max(1, Math.round(naturalH * scale));
        var fittedW = Math.max(1, Math.round(naturalW * scale));
        var center = !opts || opts.center !== false;

        img.style.setProperty('max-height', fittedH + 'px', 'important');
        img.style.setProperty('max-width', fittedW + 'px', 'important');
        img.style.setProperty('height', fittedH + 'px', 'important');
        img.style.setProperty('width', fittedW + 'px', 'important');
        img.style.setProperty('object-fit', 'contain', 'important');

        if (center) {
            img.style.setProperty('top', '0', 'important');
            img.style.setProperty('right', '0', 'important');
            img.style.setProperty('bottom', '0', 'important');
            img.style.setProperty('left', '0', 'important');
            img.style.setProperty('margin', 'auto', 'important');
            img.style.setProperty('transform', 'none', 'important');
            img.style.setProperty('position', 'absolute', 'important');
        }

        return fittedH;
    }

    function clearPlaceholderInlineHeight(ph) {
        if (!ph) {
            return;
        }

        ph.style.removeProperty('height');
        ph.style.removeProperty('max-height');
        ph.style.removeProperty('overflow');
    }

    function isGalleryReady(ph, stage, frame) {
        if (ph && ph.classList.contains('_block-content-loading')) {
            return false;
        }

        if (!stage || !frame) {
            return false;
        }

        var img = frame.querySelector('img.fotorama__img');
        return !!(img && (img.naturalHeight > 0 || img.offsetHeight > 40));
    }

    function syncGalleryHeight() {
        if (document.body.classList.contains('fotorama__fullscreen')) {
            scheduleFullscreenSync();
            return;
        }

        /* shell18/19: undo fullscreen leaks (nav hide + #020617 shell + chroma)
           before measuring normal PDP layout. */
        exitFullscreenCleanup();

        var media = getMediaColumn();
        if (!media) {
            return;
        }

        var wrap = media.querySelector('.fotorama__wrap');
        var stage = media.querySelector('.fotorama__stage');
        var nav = media.querySelector('.fotorama__nav-wrap');
        var ph = media.querySelector('.gallery-placeholder');
        var item = media.querySelector('.fotorama-item');
        var frame = stage ? getActiveStageFrame(stage) : null;

        if (ph && ph.classList.contains('_block-content-loading')) {
            clearPlaceholderInlineHeight(ph);
            return;
        }

        if (!wrap || !stage || !isGalleryReady(ph, stage, frame)) {
            return;
        }

        var maxStageH = getMaxStageHeight(wrap, media, frame);
        var fittedH = containImageInFrame(frame, maxStageH) || maxStageH;

        stage.style.setProperty('min-height', '0', 'important');
        stage.style.setProperty('max-height', fittedH + 'px', 'important');
        stage.style.setProperty('height', fittedH + 'px', 'important');
        stage.style.setProperty('overflow', 'hidden', 'important');
        syncShaftAndFrames(stage, fittedH);

        var navHRaw = nav ? Math.max(nav.offsetHeight, 0) : 0;
        if (nav) {
            nav.style.setProperty('display', 'block', 'important');
            nav.style.setProperty('visibility', 'visible', 'important');
            nav.style.setProperty('opacity', '1', 'important');
            nav.style.setProperty('pointer-events', 'auto', 'important');
            nav.style.setProperty('position', 'relative', 'important');
            nav.style.removeProperty('left');
            nav.style.removeProperty('top');
            nav.style.setProperty('max-height', MAX_NAV_PX + 'px', 'important');
            nav.style.setProperty('overflow', 'hidden', 'important');
            /* shell14: kill residual margin so thumbs stay inside wrap */
            nav.style.setProperty('margin-top', '0', 'important');
            nav.style.setProperty('margin-bottom', '0', 'important');
            nav.style.setProperty('padding-top', '0', 'important');
            nav.style.setProperty('padding-bottom', '0', 'important');
        }
        /* shell18: always reserve thumb strip — early sync saw navHRaw=0 and
           locked wrap to stage-only, clipping thumbs forever. */
        var navH = nav ? MAX_NAV_PX : 0;
        /* shell14: include vertical margin — offsetHeight ignores it (CDP overflow 7px). */
        var navMt = 0;
        var navMb = 0;
        if (nav) {
            var navCs = window.getComputedStyle(nav);
            navMt = parseFloat(navCs.marginTop) || 0;
            navMb = parseFloat(navCs.marginBottom) || 0;
        }
        var contentHeight = fittedH + navMt + navH + navMb;

        [wrap, item].forEach(function (el) {
            if (!el) {
                return;
            }
            el.style.setProperty('height', contentHeight + 'px', 'important');
            el.style.setProperty('max-height', contentHeight + 'px', 'important');
            el.style.setProperty('overflow', 'hidden', 'important');
        });

        if (ph) {
            var phStyle = window.getComputedStyle(ph);
            var padY = (parseFloat(phStyle.paddingTop) || 0) + (parseFloat(phStyle.paddingBottom) || 0);
            var borderY = (parseFloat(phStyle.borderTopWidth) || 0) + (parseFloat(phStyle.borderBottomWidth) || 0);
            var phH = contentHeight + padY + borderY;
            ph.style.setProperty('height', phH + 'px', 'important');
            ph.style.setProperty('max-height', 'none', 'important');
            ph.style.setProperty('overflow', 'hidden', 'important');
        }

        media.style.setProperty('height', 'auto', 'important');
    }

    function hideFullscreenNav(fsItem) {
        if (!fsItem) {
            return;
        }

        fsItem.querySelectorAll('.fotorama__nav-wrap, .fotorama__nav').forEach(function (el) {
            el.style.setProperty('display', 'none', 'important');
            el.style.setProperty('width', '0', 'important');
            el.style.setProperty('height', '0', 'important');
            el.style.setProperty('max-width', '0', 'important');
            el.style.setProperty('max-height', '0', 'important');
            el.style.setProperty('overflow', 'hidden', 'important');
            el.style.setProperty('visibility', 'hidden', 'important');
            el.style.setProperty('opacity', '0', 'important');
            el.style.setProperty('pointer-events', 'none', 'important');
            el.style.setProperty('position', 'absolute', 'important');
            el.style.setProperty('left', '-9999px', 'important');
            el.style.setProperty('top', '-9999px', 'important');
        });
    }

    function observeFullscreenNavHide(fsItem) {
        var navWrap = fsItem ? fsItem.querySelector('.fotorama__nav-wrap') : null;
        if (!navWrap || navWrap.__awaFsNavObserved || !window.MutationObserver) {
            return;
        }

        navWrap.__awaFsNavObserved = true;
        new MutationObserver(function () {
            if (document.body.classList.contains('fotorama__fullscreen')) {
                hideFullscreenNav(fsItem);
            }
        }).observe(navWrap, { attributes: true, attributeFilter: ['style', 'class'] });
    }

    var fsSyncLock = false;

    function syncFullscreenShaftAndFrames(stage, widthPx, heightPx) {
        if (!stage || widthPx <= 0 || heightPx <= 0) {
            return;
        }

        stage.querySelectorAll('.fotorama__stage__shaft, .fotorama__stage__frame').forEach(function (el) {
            el.style.setProperty('width', widthPx + 'px', 'important');
            el.style.setProperty('max-width', widthPx + 'px', 'important');
            el.style.setProperty('height', heightPx + 'px', 'important');
            el.style.setProperty('max-height', heightPx + 'px', 'important');
            el.style.setProperty('overflow', 'hidden', 'important');
        });
    }

    function observeFullscreenLayout(fsItem) {
        if (!fsItem || fsItem.__awaFsLayoutObserved || !window.MutationObserver) {
            return;
        }

        var stage = fsItem.querySelector('.fotorama__stage');
        if (!stage) {
            return;
        }

        fsItem.__awaFsLayoutObserved = true;
        var debounceTimer = 0;
        new MutationObserver(function () {
            if (!document.body.classList.contains('fotorama__fullscreen') || fsSyncLock) {
                return;
            }

            var wrap = fsItem.querySelector('.fotorama__wrap');
            var active = getActiveStageFrame(stage);
            if (!wrap || !active) {
                return;
            }

            // Only re-sync when Fotorama overwrites our explicit content size.
            var wrapW = wrap.offsetWidth || 0;
            var frameW = active.offsetWidth || 0;
            var expected = parseInt(wrap.style.width, 10) || 0;
            if (expected > 0 && Math.abs(wrapW - expected) <= 2 && Math.abs(frameW - expected) <= 2) {
                return;
            }

            if (debounceTimer) {
                window.clearTimeout(debounceTimer);
            }
            debounceTimer = window.setTimeout(function () {
                debounceTimer = 0;
                if (!fsSyncLock && document.body.classList.contains('fotorama__fullscreen')) {
                    syncFullscreenGallery();
                }
            }, 50);
        }).observe(stage, {
            attributes: true,
            attributeFilter: ['style', 'class'],
            subtree: true
        });
    }

    function getFotoramaApi(fsItem) {
        var $ = window.jQuery || window.$;
        if (!$ || !fsItem) {
            return null;
        }
        try {
            return $(fsItem).data('fotorama') || null;
        } catch (e) {
            return null;
        }
    }

    function pinFullscreenControl(el, spot) {
        if (!el) {
            return;
        }
        el.style.setProperty('position', 'fixed', 'important');
        el.style.setProperty('z-index', '100250', 'important');
        el.style.setProperty('width', '44px', 'important');
        el.style.setProperty('height', '44px', 'important');
        el.style.setProperty('margin', '0', 'important');
        if (spot === 'close') {
            el.style.setProperty('top', '12px', 'important');
            el.style.setProperty('right', '12px', 'important');
            el.style.setProperty('left', 'auto', 'important');
            el.style.setProperty('bottom', 'auto', 'important');
            el.style.setProperty('transform', 'none', 'important');
            return;
        }
        el.style.setProperty('top', '50%', 'important');
        el.style.setProperty('bottom', 'auto', 'important');
        el.style.setProperty('transform', 'translateY(-50%)', 'important');
        if (spot === 'prev') {
            el.style.setProperty('left', '12px', 'important');
            el.style.setProperty('right', 'auto', 'important');
        } else if (spot === 'next') {
            el.style.setProperty('right', '12px', 'important');
            el.style.setProperty('left', 'auto', 'important');
        }
    }

    function syncFullscreenArrows(fsItem) {
        var api = getFotoramaApi(fsItem);
        var prev = fsItem.querySelector('.fotorama__arr--prev');
        var next = fsItem.querySelector('.fotorama__arr--next');
        var size = api && typeof api.size === 'number' ? api.size : 0;
        var index = api && typeof api.activeIndex === 'number' ? api.activeIndex : 0;
        var showNav = size > 1;
        var showPrev = showNav && index > 0;
        var showNext = showNav && index < size - 1;

        [prev, next].forEach(function (arr, i) {
            if (!arr) {
                return;
            }
            var on = i === 0 ? showPrev : showNext;
            arr.setAttribute('data-awa-fs-arr', on ? 'on' : 'off');
            arr.style.setProperty('display', on ? 'flex' : 'none', 'important');
            arr.style.setProperty('pointer-events', on ? 'auto' : 'none', 'important');
            arr.style.setProperty('visibility', on ? 'visible' : 'hidden', 'important');
            arr.style.setProperty('opacity', on ? '1' : '0', 'important');
            pinFullscreenControl(arr, i === 0 ? 'prev' : 'next');
        });

        return { size: size, index: index, showPrev: showPrev, showNext: showNext };
    }

    function syncFullscreenGallery() {
        if (fsSyncLock) {
            return false;
        }

        var fsItem = document.querySelector('.fotorama-item.fotorama--fullscreen');
        if (!fsItem) {
            return false;
        }

        var wrap = fsItem.querySelector('.fotorama__wrap');
        var stage = fsItem.querySelector('.fotorama__stage');
        var frame = stage ? getActiveStageFrame(stage) : null;
        if (!wrap || !stage || !frame) {
            return false;
        }

        var img = getFrameImage(frame);
        var vh = window.innerHeight;
        var vw = window.innerWidth;
        var pad = 16;
        var contentH = Math.max(200, vh - pad * 2);
        var contentW = Math.max(200, vw - pad * 2);

        if (img) {
            applyFullscreenChroma(img);
        }

        hideFullscreenNav(fsItem);
        observeFullscreenNavHide(fsItem);
        observeFullscreenLayout(fsItem);

        fsSyncLock = true;

        var fsIcon = fsItem.querySelector('.fotorama__fullscreen-icon');
        pinFullscreenControl(fsIcon, 'close');

        var zoomIn = fsItem.querySelector('.fotorama__zoom-in');
        var zoomOut = fsItem.querySelector('.fotorama__zoom-out');
        if (zoomIn) {
            zoomIn.style.setProperty('position', 'fixed', 'important');
            zoomIn.style.setProperty('top', '12px', 'important');
            zoomIn.style.setProperty('left', '12px', 'important');
            zoomIn.style.setProperty('right', 'auto', 'important');
            zoomIn.style.setProperty('z-index', '100250', 'important');
        }
        if (zoomOut) {
            zoomOut.style.setProperty('position', 'fixed', 'important');
            zoomOut.style.setProperty('top', '64px', 'important');
            zoomOut.style.setProperty('left', '12px', 'important');
            zoomOut.style.setProperty('right', 'auto', 'important');
            zoomOut.style.setProperty('z-index', '100250', 'important');
        }

        fsItem.style.setProperty('display', 'flex', 'important');
        fsItem.style.setProperty('align-items', 'center', 'important');
        fsItem.style.setProperty('justify-content', 'center', 'important');
        fsItem.style.setProperty('inset', '0', 'important');
        fsItem.style.setProperty('width', '100vw', 'important');
        fsItem.style.setProperty('height', '100vh', 'important');
        fsItem.style.setProperty('max-height', '100dvh', 'important');
        fsItem.style.setProperty('overflow', 'hidden', 'important');
        fsItem.style.setProperty('background', '#020617', 'important');

        [wrap, stage].forEach(function (el) {
            el.style.setProperty('width', contentW + 'px', 'important');
            el.style.setProperty('max-width', contentW + 'px', 'important');
            el.style.setProperty('height', contentH + 'px', 'important');
            el.style.setProperty('max-height', contentH + 'px', 'important');
            el.style.setProperty('overflow', 'hidden', 'important');
            el.style.setProperty('left', 'auto', 'important');
            el.style.setProperty('top', 'auto', 'important');
            el.style.setProperty('margin', '0 auto', 'important');
            el.style.setProperty('position', 'relative', 'important');
            el.style.setProperty('background', 'transparent', 'important');
            el.style.setProperty('background-color', 'transparent', 'important');
        });

        var shaft = stage.querySelector('.fotorama__stage__shaft');
        if (shaft) {
            shaft.style.setProperty('position', 'absolute', 'important');
            shaft.style.setProperty('inset', '0', 'important');
            shaft.style.setProperty('width', '100%', 'important');
            shaft.style.setProperty('max-width', '100%', 'important');
            shaft.style.setProperty('margin', '0', 'important');
            shaft.style.setProperty('transform', 'none', 'important');
            shaft.style.setProperty('background', 'transparent', 'important');
        }

        syncFullscreenShaftAndFrames(stage, contentW, contentH);
        frame.style.setProperty('left', '0', 'important');
        frame.style.setProperty('top', '0', 'important');
        frame.style.setProperty('width', contentW + 'px', 'important');
        frame.style.setProperty('max-width', contentW + 'px', 'important');
        frame.style.setProperty('height', contentH + 'px', 'important');
        frame.style.setProperty('max-height', contentH + 'px', 'important');
        frame.style.setProperty('position', 'absolute', 'important');
        frame.style.setProperty('inset', '0', 'important');
        frame.style.setProperty('background', 'transparent', 'important');

        if (img) {
            // Re-query after chroma may replace src / decode.
            img = getFrameImage(frame) || img;
            containImageInFrame(frame, contentH, {
                center: true
            });
        }

        syncFullscreenArrows(fsItem);

        fsSyncLock = false;

        return true;
    }

    function restoreFullscreenNav() {
        var fsItem = document.querySelector('.fotorama-item.fotorama--fullscreen') ||
            document.querySelector('.fotorama-item');
        if (!fsItem) {
            return;
        }

        fsItem.querySelectorAll('.fotorama__nav-wrap, .fotorama__nav').forEach(function (el) {
            [
                'display', 'width', 'height', 'max-width', 'max-height', 'overflow',
                'visibility', 'opacity', 'pointer-events', 'position', 'left', 'top'
            ].forEach(function (prop) {
                el.style.removeProperty(prop);
            });
        });
    }

    function scheduleFullscreenSync() {
        var start = Date.now();
        var lastTick = 0;

        function tick() {
            if (!document.querySelector('.fotorama-item.fotorama--fullscreen')) {
                return;
            }
            var now = Date.now();
            // Cap rAF spam: sync at most every ~100ms during the settle window.
            if (now - lastTick >= 100) {
                lastTick = now;
                syncFullscreenGallery();
            }
            if (now - start < 4000) {
                window.requestAnimationFrame(tick);
            }
        }

        tick();
        [80, 250, 600, 1200, 2500, 4000].forEach(function (delayMs) {
            window.setTimeout(function () {
                if (document.querySelector('.fotorama-item.fotorama--fullscreen')) {
                    syncFullscreenGallery();
                }
            }, delayMs);
        });
    }

    function observeFullscreenBodyClass() {
        if (!window.MutationObserver || document.body.__awaFsGalleryObserved) {
            return;
        }
        document.body.__awaFsGalleryObserved = true;
        var wasFs = document.body.classList.contains('fotorama__fullscreen');
        new MutationObserver(function () {
            var isFs = document.body.classList.contains('fotorama__fullscreen');
            /* 51.52: ignore unrelated body class churn (minicart-open etc.).
             * Acting on every class mutation while fullscreen re-entered MO storms. */
            if (isFs === wasFs) {
                return;
            }
            if (isFs) {
                scheduleFullscreenSync();
            } else if (wasFs) {
                /* shell19: cancelFullScreen may skip fotorama:fullscreenexit */
                exitFullscreenCleanup();
                scheduleSync();
            }
            wasFs = isFs;
        }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    var scheduled = false;
    function scheduleSync() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            syncGalleryHeight();
        });
    }

    function observePlaceholderLoadingState() {
        var ph = document.querySelector('.catalog-product-view .gallery-placeholder');
        if (!ph || ph.__awaPhLoadingObserved || !window.MutationObserver) {
            return;
        }

        ph.__awaPhLoadingObserved = true;
        new MutationObserver(function () {
            if (!ph.classList.contains('_block-content-loading')) {
                scheduleSync();
            } else {
                clearPlaceholderInlineHeight(ph);
            }
        }).observe(ph, { attributes: true, attributeFilter: ['class', 'style'] });
    }

    document.addEventListener('fotorama:ready', scheduleSync);
    document.addEventListener('fotorama:show', function () {
        scheduleSync();
        if (document.querySelector('.fotorama-item.fotorama--fullscreen')) {
            scheduleFullscreenSync();
        }
    });
    document.addEventListener('fotorama:load', function () {
        scheduleSync();
        if (document.querySelector('.fotorama-item.fotorama--fullscreen')) {
            scheduleFullscreenSync();
        }
    });
    document.addEventListener('fotorama:fullscreenenter', scheduleFullscreenSync);
    document.addEventListener('fotorama:fullscreenexit', function () {
        exitFullscreenCleanup();
        scheduleSync();
    });
    window.addEventListener('resize', function () {
        scheduleSync();
        if (document.querySelector('.fotorama-item.fotorama--fullscreen')) {
            scheduleFullscreenSync();
        }
    }, { passive: true });
    document.addEventListener('DOMContentLoaded', function () {
        observeFullscreenBodyClass();
        observePlaceholderLoadingState();
        window.setTimeout(scheduleSync, 400);
        window.setTimeout(scheduleSync, 1500);
    }, { once: true });

    if (document.readyState !== 'loading') {
        observeFullscreenBodyClass();
        observePlaceholderLoadingState();
        scheduleSync();
    }

    /**
     * Race guard: Magento may drop _block-content-loading when .fotorama-item
     * exists but before .fotorama__img has naturalWidth. Keep loading class
     * (and thus LCP CSS) until a real gallery image is ready.
     */
    (function awaGalleryRaceGuard() {
        function hasReadyFotoramaImg() {
            var imgs = document.querySelectorAll(
                '.catalog-product-view .fotorama-item img.fotorama__img,' +
                '.catalog-product-view .fotorama-item img.fotorama__img--full'
            );
            var i;
            for (i = 0; i < imgs.length; i++) {
                if (imgs[i].naturalWidth > 0) {
                    return true;
                }
            }
            return false;
        }

        function guard() {
            var ph = document.querySelector('.catalog-product-view .gallery-placeholder');
            if (!ph) {
                return;
            }
            var ready = hasReadyFotoramaImg();
            var hasItem = !!document.querySelector('.catalog-product-view .fotorama-item');
            if (hasItem && !ready) {
                if (!ph.classList.contains('_block-content-loading')) {
                    ph.classList.add('_block-content-loading');
                }
            } else if (ready && ph.classList.contains('_block-content-loading')) {
                ph.classList.remove('_block-content-loading');
            }
        }

        document.addEventListener('fotorama:ready', guard);
        document.addEventListener('fotorama:load', guard);
        document.addEventListener('fotorama:showend', guard);
        document.addEventListener('DOMContentLoaded', guard, { once: true });
        if (document.readyState !== 'loading') {
            guard();
        }
        if (window.MutationObserver) {
            var root = document.querySelector('.catalog-product-view .product.media') ||
                document.querySelector('.catalog-product-view .gallery-placeholder');
            if (root) {
                new MutationObserver(guard).observe(root, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['class']
                });
            }
        }
    })();
})(window, document);
