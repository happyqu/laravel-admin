(function (window, document) {
    'use strict';

    const search = document.querySelector('[data-menu-search]');
    const tree = document.getElementById('classic-sidebar-menu');
    if (!search || !tree) {
        return;
    }

    const input = search.querySelector('[data-menu-search-input]');
    const clear = search.querySelector('[data-menu-search-clear]');
    const empty = tree.parentElement.querySelector('[data-menu-search-empty]');
    const menu = readMenu(tree);

    function readMenu(list) {
        const items = [];
        for (const row of list.children) {
            const link = row.firstElementChild;
            if (!link || !link.matches('a')) {
                continue;
            }

            const branch = row.querySelector(':scope > .treeview-menu');
            items.push({
                row,
                title: `${link.textContent.trim()} ${row.dataset.menuSearchTitle || ''}`.toLowerCase(),
                uri: (row.dataset.menuSearchUri || '').toLowerCase(),
                address: branch ? '' : link.href.toLowerCase(),
                children: branch ? readMenu(branch) : []
            });
        }

        return items;
    }

    function filterMenu(items, keywords, parentPath = '') {
        let hasMatch = false;
        for (const item of items) {
            const path = `${parentPath} ${item.title}`;
            const text = `${path} ${item.uri}`;
            const matchingChildren = filterMenu(item.children, keywords, path);
            const visible = matchingChildren || keywords.every(keyword =>
                text.includes(keyword) || (keyword.includes('/') && item.address.includes(keyword))
            );
            item.row.classList.toggle('is-menu-search-hidden', !visible);
            item.row.classList.toggle('is-menu-search-expanded', keywords.length > 0 && matchingChildren);
            hasMatch ||= visible;
        }

        return hasMatch;
    }

    function updateSearch() {
        const keywords = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
        const hasMatch = filterMenu(menu, keywords);
        tree.classList.toggle('is-menu-search-active', keywords.length > 0);
        clear.hidden = input.value.length === 0;
        empty.hidden = keywords.length === 0 || hasMatch;
    }

    function clearSearch() {
        input.value = '';
        updateSearch();
    }

    clear.addEventListener('click', () => {
        clearSearch();
        input.focus();
    });

    input.addEventListener('input', updateSearch);
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !event.isComposing && event.keyCode !== 229 && input.value) {
            event.preventDefault();
            clearSearch();
        }
    });

    tree.addEventListener('click', event => {
        const category = event.target.closest('.treeview > a');
        if (category && tree.classList.contains('is-menu-search-active')) {
            event.preventDefault();
            event.stopPropagation();
            category.parentElement.classList.toggle('is-menu-search-expanded');
        }
    }, true);

    const desktop = window.matchMedia('(min-width: 768px)');
    function clearIfCollapsed() {
        if (input.value && desktop.matches && document.body.matches('.sidebar-mini.sidebar-collapse')) {
            clearSearch();
        }
    }

    desktop.addEventListener('change', clearIfCollapsed);
    new MutationObserver(clearIfCollapsed).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    updateSearch();
    search.hidden = false;
})(window, document);
