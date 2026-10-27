/**
 * AWA Quick View — Swiper thumbnails (sem Owl Carousel).
 */
define([
    'jquery',
    'mage/template',
    'mage/translate'
], function ($, mageTemplate, $t) {
    'use strict';

    var cloudzoomReady = false;

    function refreshCloudZoom() {
        if (!cloudzoomReady) {
            require(['quickview/cloudzoom'], function () {
                cloudzoomReady = true;
                applyCloudZoom();
            });
            return;
        }
        applyCloudZoom();
    }

    function applyCloudZoom() {
        if (typeof $.fn.CloudZoom === 'function') {
            $('.cloud-zoom, .cloud-zoom-gallery').CloudZoom();
        }
    }

    /**
     * @param {jQuery} $scope
     * @param {string|number} productId
     */
    function initQuickviewGallery($scope, productId) {
        var $gallery = $scope.find('#gallery_' + productId);

        if (!$gallery.length) {
            refreshCloudZoom();
            return;
        }

        var $mainLink = $gallery.find('a.cloud-zoom').first();
        var $mainImg = $mainLink.find('img').first();
        var $thumbs = $gallery.find('.quickview-thumbs').first();
        var $track = $thumbs.find('.swiper-wrapper').first();

        refreshCloudZoom();

        if (!$thumbs.length || !$track.length || $thumbs.data('awaQuickviewSwiper')) {
            return;
        }

        var $shell = $gallery.find('.quickview-thumbs-shell').first();
        var prevEl = $shell.find('.quickview-thumbs-prev').get(0) || null;
        var nextEl = $shell.find('.quickview-thumbs-next').get(0) || null;

        /**
         * @param {jQuery} $slide
         */
        function syncMainImage($slide) {
            var $img = $slide.find('img').first();

            if (!$img.length) {
                return;
            }

            $track.children().removeClass('active');
            $slide.addClass('active');
            $mainLink.attr('href', $img.attr('data-href') || $img.attr('src') || '');
            $mainImg.attr('src', $img.attr('data-thumb-image') || $img.attr('src') || '');
            refreshCloudZoom();
        }

        $track.children('li, .swiper-slide').on('click', function (event) {
            event.preventDefault();
            syncMainImage($(this));
        });

        require(['swiper'], function (Swiper) {
            if ($thumbs.data('awaQuickviewSwiper')) {
                return;
            }

                    var swiper = new Swiper($thumbs.get(0), {
                        slidesPerView: 3,
                        spaceBetween: 10,
                        watchOverflow: true,
                        navigation: prevEl && nextEl ? {
                            prevEl: prevEl,
                            nextEl: nextEl,
                            disabledClass: 'is-disabled'
                        } : false,
                        breakpoints: {
                            480: {
                                slidesPerView: 3
                            },
                            768: {
                                slidesPerView: 4
                            },
                            992: {
                                slidesPerView: 4
                            },
                            1200: {
                                slidesPerView: 4
                            }
                        },
                        on: {
                            slideChange: function (instance) {
                                var slide = instance.slides[instance.activeIndex];
            
                                if (slide) {
                                    syncMainImage($(slide));
                                }
                            }
                        }
                    });
            
                    $thumbs.data('awaQuickviewSwiper', swiper);
                    syncMainImage($track.children().first());
        });
    }

    $.widget('mage.productQuickview', {
        loaderStarted: 0,
        options: {
            icon: '',
            texts: {
                loaderText: $t('Please wait...'),
                imgAlt: $t('Loading...')
            },
            template: '<div class="loading-mask" data-role="loader">' +
                '<div class="loader">' +
                '<img alt="<%- data.texts.imgAlt %>" src="<%- data.icon %>">' +
                '<p><%- data.texts.loaderText %></p>' +
                '</div>' + '</div>'
        },

        _create: function () {
            this._bindClick();
        },

        _bindClick: function () {
            var self = this;

            self.createWindow();
            this.element.on('click', function (e) {
                e.preventDefault();
                self.element.removeClass('active');
                $(this).addClass('active');
                self.show();
                // OPT-20261024: preload lazy das dependencias da galeria no clique,
                // paralelo ao AJAX — garante que o plugin exista quando o conteudo
                // chegar e o refreshCloudZoom() rodar (zoom funcional).
                require(['quickview/cloudzoom'], function () {});
                self.ajaxLoad($(this));
            });
        },

        _render: function () {
            var html;

            if (!this.spinnerTemplate) {
                this.spinnerTemplate = mageTemplate(this.options.template);

                html = $(this.spinnerTemplate({
                    data: this.options
                }));

                html.prependTo($('body'));

                this.spinner = html;
            }
        },

        show: function () {
            $('.quickview-link').addClass('loading');
            return false;
        },

        hide: function () {
            $('.quickview-link').removeClass('loading');
            return false;
        },

        ajaxLoad: function (link) {
            var self = this;
            var productId = link.attr('data-id');
            var itemShow;
            var urlLink;

            if (productId && $('#quickview-content-' + productId).length > 0) {
                return self.showWindow($('#quickview-content-' + productId));
            }

            urlLink = link.attr('data-href') || link.attr('href');

            $.ajax({
                url: urlLink,
                data: {},
                success: function (res) {
                    itemShow = $('#quickview-content');

                    if (productId) {
                        if ($('#quickview-content-' + productId).length < 1) {
                            var wrapper = document.createElement('div');

                            $(wrapper).attr('id', 'quickview-content-' + productId);
                            $(wrapper).addClass('wrapper_quickview_item');
                            $(wrapper).html(res);
                            $('#quickview-content').append(wrapper);
                        }

                        itemShow = $('#quickview-content-' + productId);
                        initQuickviewGallery(itemShow, productId);
                    } else {
                        $('#quickview-content').html(res);
                    }

                    refreshCloudZoom();
                    // OPT-20261024: init determinístico do CloudZoom após o render —
                    // a lib ja esta carregada (preload no clique); o refresh acima pode
                    // assincronizar via require, entao aplicamos explicitamente aqui.
                    require(['quickview/cloudzoom'], function () {
                        itemShow.find('a.cloud-zoom, a.cloud-zoom-gallery').CloudZoom();
                    });
                    $('#quickview-content').trigger('contentUpdated');
                    self.showWindow(itemShow);
                }
            });
        },

        showWindow: function (itemShow) {
            this.hide();
            this.lastActiveElement = document.activeElement;
            $('#quick-window .wrapper_quickview_item').hide();
            $('#quick-window').css({
                display: 'block'
            });

            if (itemShow) {
                itemShow.show();
            }

            $('#quick-window').attr('aria-hidden', 'false');
            $('#quick-background').removeClass('hidden');
            $('body').addClass('quickview-open');

            var $focusTarget = $('#quick-window').find('#quickview-close').first();

            if ($focusTarget.length) {
                $focusTarget.trigger('focus');
            } else {
                $('#quick-window').trigger('focus');
            }
        },

        hideWindow: function () {
            $('#quick-window').hide();
            $('#quick-window .wrapper_quickview_item').hide();
            $('#quick-window').attr('aria-hidden', 'true');
            $('#quick-background').addClass('hidden');
            $('#quickview-content').html('');
            $('body').removeClass('quickview-open');

            if (this.lastActiveElement && this.lastActiveElement.focus) {
                try {
                    this.lastActiveElement.focus();
                } catch (e) {
                    // ignore focus restore errors
                }
            }
        },

        createWindow: function () {
            if ($('#quick-background').length > 0) {
                return;
            }

            var qBackground = document.createElement('div');

            $(qBackground).attr('id', 'quick-background');
            $(qBackground).addClass('hidden');
            $('body').append(qBackground);

            var qWindow = document.createElement('div');

            $(qWindow).attr('id', 'quick-window');
            $(qWindow)
                .attr('role', 'dialog')
                .attr('aria-modal', 'true')
                .attr('aria-hidden', 'true')
                .attr('tabindex', '-1');
            $(qWindow).html(
                '<div id="quickview-header">' +
                '<a href="#" id="quickview-close" role="button" aria-label="' + $t('Close') + '">close</a>' +
                '</div>' +
                '<div class="quick-view-content" id="quickview-content"></div>'
            );
            $('body').append(qWindow);

            $('#quickview-close').on('click', function (e) {
                e.preventDefault();
                this.hideWindow();
            }.bind(this));
            $('#quick-background').on('click', this.hideWindow.bind(this));

            $(document).on('keydown.quickview', function (e) {
                if ($('#quick-window').is(':visible') !== true) {
                    return;
                }

                if (e.key === 'Escape' || e.keyCode === 27) {
                    e.preventDefault();
                    this.hideWindow();
                    return;
                }

                if (e.key !== 'Tab' && e.keyCode !== 9) {
                    return;
                }

                var $modal = $('#quick-window');
                var $focusables = $modal
                    .find('a[href], area[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), iframe, object, embed, [tabindex]:not([tabindex="-1"]), [contenteditable]')
                    .filter(':visible');

                if ($focusables.length < 1) {
                    $modal.trigger('focus');
                    e.preventDefault();
                    return;
                }

                var first = $focusables.get(0);
                var last = $focusables.get($focusables.length - 1);

                if (e.shiftKey && document.activeElement === first) {
                    $(last).trigger('focus');
                    e.preventDefault();
                } else if (!e.shiftKey && document.activeElement === last) {
                    $(first).trigger('focus');
                    e.preventDefault();
                }
            }.bind(this));
        }
    });

    return $.mage.productQuickview;
});
