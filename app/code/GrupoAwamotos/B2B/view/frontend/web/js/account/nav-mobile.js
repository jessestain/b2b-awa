/**
 * Mobile nav: agrupa links por seção (Painel / Compras / Empresa) com accordion.
 */
define([], function () {
    'use strict';

    var MOBILE_MQ = '(max-width: 767px)';

    /**
     * @param {HTMLElement} sectionLi
     * @param {HTMLElement[]} itemLis
     */
    function wrapSection(sectionLi, itemLis)
    {
        var labelEl = sectionLi.querySelector('.account-nav-section__label');
        if (!labelEl || itemLis.length === 0) {
            return;
        }

        var hasCurrent = itemLis.some(function (li) {
            return li.classList.contains('current');
        });

        var panelId = 'awa-nav-section-' + String(sectionLi.dataset.awaNavSectionId || Date.now()) + '-' + Math.random().toString(36).slice(2, 7);

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'account-nav-section__toggle';
        toggle.id = panelId + '-toggle';
        toggle.textContent = labelEl.textContent.trim();
        toggle.setAttribute('aria-expanded', hasCurrent ? 'true' : 'false');
        toggle.setAttribute('aria-controls', panelId);

        var panel = document.createElement('ul');
        panel.className = 'account-nav-section__items';
        panel.id = panelId;
        panel.setAttribute('role', 'group');
        panel.setAttribute('aria-labelledby', toggle.id);

        itemLis.forEach(function (li) {
            panel.appendChild(li);
        });

        sectionLi.classList.add('account-nav-section--accordion');
        if (hasCurrent) {
            sectionLi.classList.add('is-open');
        }

        sectionLi.replaceChildren(toggle, panel);

        toggle.addEventListener('click', function () {
            var open = sectionLi.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    /**
     * @param {HTMLElement} navBlock
     */
    function initBlockToggle(navBlock)
    {
        var title = navBlock.querySelector(':scope > .block-title, :scope > .title');
        var content = navBlock.querySelector(':scope > .content');

        if (!title || !content || navBlock.dataset.awaNavBlockToggle === '1') {
            return;
        }

        navBlock.dataset.awaNavBlockToggle = '1';
        navBlock.classList.add('awa-nav--mobile-collapsible');

        /* CDP 375/vh812: expanded=true (por .current) deixava navH=365 e
           dashboard em y=664. O accordion da seção já destaca o item atual;
           o bloco "Minha Conta" começa fechado para o painel entrar na dobra. */
        var expanded = false;
        navBlock.classList.toggle('awa-nav--expanded', expanded);
        title.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        title.setAttribute('role', 'button');
        title.setAttribute('tabindex', '0');

        function setExpanded(isOpen)
        {
            navBlock.classList.toggle('awa-nav--expanded', isOpen);
            navBlock.classList.toggle('awa-nav--user-open', isOpen);
            title.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        function onTitleActivate(event)
        {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            if (event.type === 'keydown') {
                event.preventDefault();
            }
            setExpanded(!navBlock.classList.contains('awa-nav--expanded'));
        }

        title.addEventListener('click', onTitleActivate);
        title.addEventListener('keydown', onTitleActivate);
    }

    function init()
    {
        if (!window.matchMedia(MOBILE_MQ).matches) {
            return;
        }

        var navBlock = document.querySelector('.block.account-nav, .block-collapsible-nav');
        if (!navBlock) {
            return;
        }

        initBlockToggle(navBlock);

        var list = navBlock.querySelector('.account-nav-items, .nav.items');
        if (!list || list.dataset.awaNavSections === '1') {
            return;
        }

        list.dataset.awaNavSections = '1';

        var children = Array.from(list.children);
        var index = 0;

        while (index < children.length) {
            var node = children[index];

            if (!node.classList.contains('account-nav-section')) {
                index += 1;
                continue;
            }

            var items = [];
            index += 1;

            while (index < children.length && !children[index].classList.contains('account-nav-section')) {
                items.push(children[index]);
                index += 1;
            }

            wrapSection(node, items);
        }

    }

    return init;
});
