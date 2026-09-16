(function (w, d) {
    'use strict';

    var cfgNode = d.getElementById('awa-mirasvit-ac-bootstrap-config');
    if (!cfgNode || !cfgNode.textContent) {
        return;
    }

    var cfg;
    try {
        cfg = JSON.parse(cfgNode.textContent);
    } catch (e) {
        return;
    }

    var selector = cfg.selector || 'input#search, input#mobile_search, .minisearch input[type="text"]';
    var started = false;
    /**
     * Digitação real ocorrida ANTES do bootstrap terminar.
     * Valor pré-preenchido (ex.: catalogsearch/?q=) NÃO marca isto — evita suggest duplicado.
     */
    var typedBeforeBootstrap = false;

    d.addEventListener('input', function (event) {
        var target = event && event.target;

        if (started || !target || typeof target.closest !== 'function') {
            return;
        }

        if (target.closest('#search, #mobile_search, .minisearch, #search_mini_form, .block-search')) {
            typedBeforeBootstrap = true;
        }
    }, true);

    /**
     * PD2 fix: bootstrap assíncrono. Só re-dispara input se o usuário digitou
     * enquanto os módulos ainda carregavam. Não reexecutar query da URL na PLP.
     */
    function replayPendingQuery($searchInput) {
        var value = ($searchInput.val() || '').trim();
        var panelActive = !!d.querySelector(
            '.mst-searchautocomplete__autocomplete._active, .mst-searchautocomplete__autocomplete.is-open'
        );

        if (value.length < 2 || !typedBeforeBootstrap || panelActive) {
            return;
        }


        window.setTimeout(function () {
            var el = $searchInput.get(0);

            if (!el) {
                return;
            }

            if (typeof w.Event === 'function') {
                el.dispatchEvent(new w.Event('input', { bubbles: true }));
                el.dispatchEvent(new w.Event('keyup', { bubbles: true }));
            } else {
                $searchInput.trigger('input').trigger('keyup');
            }
        }, 0);
    }

    function ensureTemplates(done) {
        if (d.getElementById('searchAutocompletePlaceholder')) {
            done();
            return;
        }

        var templatesUrl = cfg.templatesUrl || '';
        if (!templatesUrl) {
            done();
            return;
        }

        fetch(templatesUrl, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('mirasvit templates fetch failed');
                }
                return response.text();
            })
            .then(function (html) {
                var host = d.createElement('div');
                host.hidden = true;
                host.id = 'awa-mirasvit-templates-host';
                host.innerHTML = html;
                (d.body || d.documentElement).appendChild(host);
                done();
            })
            .catch(function () {
                done();
            });
    }

    function bootstrapInPageAutocomplete() {
        if (started) {
            return;
        }
        started = true;

        w.require([
            'jquery',
            'Mirasvit_SearchAutocomplete/js/in-page'
        ], function ($, InPage) {
            $(d).ready(function () {
                $('#search_mini_form').prop('minSearchLength', 10000);

                var $input = $(selector);
                $input.each(function (index, searchInput) {
                    var $searchInput = $(searchInput);
                    var hasPanel = $searchInput.nextAll('#search_autocomplete, .search-autocomplete, .searchsuite-autocomplete').length > 0;

                    if (!hasPanel || $searchInput.data('awaMirasvitInPageInit')) {
                        return;
                    }

                    $searchInput.data('awaMirasvitInPageInit', 1);
                    new InPage($searchInput, cfg.config);
                    replayPendingQuery($searchInput);
                });
            });
        });
    }

    function bootstrapAutocomplete() {
        if (started) {
            return;
        }
        started = true;

        ensureTemplates(function () {
            var loadModules = function () {
                w.require([
                    'jquery',
                    'Mirasvit_SearchAutocomplete/js/autocomplete.popular-r9',
                    'Mirasvit_SearchAutocomplete/js/typeahead'
                ], function ($, autocomplete, typeahead) {
                    $(d).ready(function () {
                        $('#search_mini_form').prop('minSearchLength', 10000);

                        var $input = $(selector);
                        $input.each(function (index, searchInput) {
                            var $searchInput = $(searchInput);
                            var hasPanel = $searchInput.nextAll('#search_autocomplete, .search-autocomplete, .searchsuite-autocomplete').length > 0;

                            if (!hasPanel || $searchInput.data('awaMirasvitAutocompleteInit')) {
                                return;
                            }

                            $searchInput.data('awaMirasvitAutocompleteInit', 1);

                            try {
                                if ($searchInput.data('mageQuickSearch')) {
                                    $searchInput.quickSearch('destroy');
                                }
                                $searchInput.removeAttr('data-mage-init');
                            } catch (eQs) {}


                            if (cfg.isTypeaheadEnabled) {
                                new typeahead($searchInput).init(cfg.config);
                            }
                            new autocomplete($searchInput).init(cfg.config);
                            if (d.activeElement === searchInput) {
                                $searchInput.trigger('focus');
                            }
                            replayPendingQuery($searchInput);
                        });
                    });
                });
            };

            if (typeof w.awaRunWhenRequire === 'function') {
                w.awaRunWhenRequire(loadModules, { key: 'mirasvit-autocomplete' });
            } else {
                loadModules();
            }
        });
    }

    function bootstrap() {
        if (cfg.isInPageLayout) {
            bootstrapInPageAutocomplete();
        } else {
            bootstrapAutocomplete();
        }
    }

    if (cfg.isHomePage || cfg.deferBootstrap) {
        function onSearchIntent(event) {
            var target = event && event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }

            if (!target.closest('#search, #mobile_search, .minisearch, #search_mini_form, .block-search')) {
                return;
            }

            bootstrap();
        }

        ['focusin', 'pointerdown', 'touchstart'].forEach(function (evtName) {
            d.addEventListener(evtName, onSearchIntent, { passive: true, capture: true });
        });

        d.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
                bootstrap();
                return;
            }

            if (event.key === '/' && !(event.target && (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA'))) {
                bootstrap();
            }
        }, { capture: true });

        var alreadyFocused = d.activeElement;
        if (alreadyFocused && typeof alreadyFocused.closest === 'function' &&
            alreadyFocused.closest('#search, #mobile_search, .minisearch, #search_mini_form, .block-search')) {
            bootstrap();
        }

        /* Intent-only: evita require/jQuery no load (home + carrinho/checkout) */
    } else {
        bootstrap();
    }
}(window, document));
