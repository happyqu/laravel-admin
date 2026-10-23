(function (window, document, $) {
    'use strict';

    var dataElement = document.getElementById('dc-admin-navigation-data');
    var panel = document.getElementById('dc-admin-nav-panel');

    if (!dataElement || !panel) {
        return;
    }

    var navigation;

    try {
        navigation = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        window.console && window.console.error('Navigation data could not be parsed.', error);
        return;
    }

    document.body.classList.add('dc-admin-navigation-enabled');

    var tree = Array.isArray(navigation.tree) ? navigation.tree : [];
    var items = Array.isArray(navigation.items) ? navigation.items : [];
    var itemsById = {};
    var overlay = document.querySelector('.dc-admin-nav-overlay');
    var results = panel.querySelector('[data-dc-nav-results]');
    var categoryList = panel.querySelector('[data-dc-nav-category-list]');
    var searchInput = panel.querySelector('.dc-admin-nav-search');
    var logoButton = document.querySelector('.main-header .logo');
    var logoOriginalHtml = logoButton ? logoButton.innerHTML : '';
    var openButtons = document.querySelectorAll('[data-dc-nav-open]');
    var closeButtons = document.querySelectorAll('[data-dc-nav-close]');
    var sidebarWidth = Number(readConfig('sidebar_width', 50));
    var panelWidth = Number(readConfig('panel_width', 760));
    var globalTabsLeft = Number(readConfig('tabs_left', 0));
    var classicTabsLeft = Number(readConfig('classic_tabs_left', 42));
    var recentLimit = Number(readConfig('recent_limit', 10));
    var searchLimit = Number(readConfig('search_limit', 50));
    var storagePrefix = 'laravel-admin.navigation.v1.' + (navigation.userId || 'guest') + '.';
    var modeStorageKey = 'admin.global-navigation.mode.' + (navigation.userId || 'guest');
    var navigationMode = window.dcAdminNavigationInitialMode === 'classic' ? 'classic' : 'global';
    var modeSwitchButton = null;
    var tabsLayoutListenersBound = false;
    var favorites = readStoredIds('favorites');
    var recents = readStoredIds('recents');
    var activeCategory = recents.length ? 'recent' : (tree[0] ? 'root:' + tree[0].id : 'favorites');
    var lastFocusedElement = null;

    document.documentElement.style.setProperty('--dc-admin-nav-sidebar-width', sidebarWidth + 'px');
    document.documentElement.style.setProperty('--dc-admin-nav-panel-width', panelWidth + 'px');

    items.forEach(function (item) {
        itemsById[String(item.id)] = item;
    });

    favorites = validIds(favorites);
    recents = validIds(recents).slice(0, recentLimit);
    persistIds('favorites', favorites);
    persistIds('recents', recents);

    buildCategories();
    renderActiveCategory();

    initializeModeSwitcher();
    applyNavigationMode(navigationMode, false);

    if (logoButton) {
        logoButton.addEventListener('click', function (event) {
            if (navigationMode !== 'global') {
                return;
            }

            event.preventDefault();

            if (logoButton.getAttribute('aria-expanded') === 'true') {
                closePanel();
                return;
            }

            openPanel('catalog', logoButton);
        });
    }

    Array.prototype.forEach.call(openButtons, function (button) {
        button.addEventListener('click', function (event) {
            if (button === logoButton || navigationMode !== 'global') {
                return;
            }

            event.preventDefault();
            openPanel(button.getAttribute('data-dc-nav-open') || 'catalog', button);
        });
    });

    Array.prototype.forEach.call(closeButtons, function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            closePanel();
        });
    });

    panel.addEventListener('click', function (event) {
        var categoryButton = closest(event.target, '[data-dc-nav-category]');
        var favoriteButton = closest(event.target, '[data-dc-nav-favorite]');
        var itemElement = closest(event.target, '[data-dc-nav-item]');

        if (categoryButton) {
            activeCategory = categoryButton.getAttribute('data-dc-nav-category');
            searchInput.value = '';
            renderActiveCategory();
            return;
        }

        if (favoriteButton) {
            event.stopPropagation();
            toggleFavorite(favoriteButton.getAttribute('data-dc-nav-favorite'));
            return;
        }

        if (itemElement) {
            openMenuItem(itemsById[itemElement.getAttribute('data-dc-nav-item')]);
        }
    });

    panel.addEventListener('keydown', function (event) {
        var categoryButton = closest(event.target, '[data-dc-nav-category]');
        var itemElement = closest(event.target, '[data-dc-nav-item]');

        if (categoryButton && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            var categoryButtons = Array.prototype.slice.call(panel.querySelectorAll('.dc-admin-nav-categories [data-dc-nav-category]'));
            var categoryIndex = categoryButtons.indexOf(categoryButton);
            var direction = event.key === 'ArrowDown' ? 1 : -1;
            var nextIndex = (categoryIndex + direction + categoryButtons.length) % categoryButtons.length;
            event.preventDefault();
            categoryButtons[nextIndex].focus();
            return;
        }

        if (itemElement && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openMenuItem(itemsById[itemElement.getAttribute('data-dc-nav-item')]);
        }
    });

    searchInput.addEventListener('input', function () {
        renderSearch(searchInput.value);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && panel.classList.contains('is-open')) {
            closePanel();
        }
    });

    function readConfig(key, fallback) {
        var config = navigation.config || {};
        return typeof config[key] === 'undefined' ? fallback : config[key];
    }

    function initializeModeSwitcher() {
        var userFooter = document.querySelector('.main-header .user-menu .user-footer');

        if (!userFooter || !userFooter.parentNode) {
            return;
        }

        var item = document.createElement('li');
        item.className = 'dc-admin-nav-mode-option';
        item.innerHTML = '<a href="#" data-dc-nav-mode-switch role="button"><i class="fa" aria-hidden="true"></i><span></span></a>';
        userFooter.parentNode.insertBefore(item, userFooter);
        modeSwitchButton = item.querySelector('[data-dc-nav-mode-switch]');
        updateModeSwitcher();

        modeSwitchButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            applyNavigationMode(navigationMode === 'global' ? 'classic' : 'global', true);

            if ($) {
                $('.main-header .user-menu').removeClass('open');
            }
        });
    }

    function applyNavigationMode(mode, persist) {
        navigationMode = mode === 'classic' ? 'classic' : 'global';
        document.body.classList.toggle('dc-admin-nav-mode-global', navigationMode === 'global');
        document.body.classList.toggle('dc-admin-nav-mode-classic', navigationMode === 'classic');

        if (persist) {
            try {
                window.localStorage.setItem(modeStorageKey, navigationMode);
            } catch (error) {
                // The mode still switches for this page when storage is disabled.
            }
        }

        if (navigationMode === 'global') {
            configureGlobalLogo();
        } else {
            if (panel.classList.contains('is-open')) {
                closePanel();
            }
            restoreClassicLogo();
        }

        updateModeSwitcher();
        scheduleTabsLayout();
    }

    function configureGlobalLogo() {
        if (!logoButton) {
            return;
        }

        logoButton.innerHTML = '<span class="dc-admin-nav-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>';
        logoButton.setAttribute('data-dc-nav-open', 'catalog');
        logoButton.setAttribute('aria-label', '打开全部菜单');
        logoButton.setAttribute('aria-controls', 'dc-admin-nav-panel');
        logoButton.setAttribute('aria-expanded', 'false');
        logoButton.setAttribute('title', '全部菜单');
    }

    function restoreClassicLogo() {
        if (!logoButton) {
            return;
        }

        logoButton.innerHTML = logoOriginalHtml;
        logoButton.removeAttribute('data-dc-nav-open');
        logoButton.removeAttribute('aria-label');
        logoButton.removeAttribute('aria-controls');
        logoButton.removeAttribute('aria-expanded');
        logoButton.removeAttribute('title');
    }

    function updateModeSwitcher() {
        if (!modeSwitchButton) {
            return;
        }

        var nextIsClassic = navigationMode === 'global';
        var icon = modeSwitchButton.querySelector('.fa');
        var label = modeSwitchButton.querySelector('span');
        icon.className = 'fa ' + (nextIsClassic ? 'fa-list' : 'fa-th-large');
        label.textContent = nextIsClassic ? '切换为经典侧栏' : '切换为全局菜单';
        modeSwitchButton.setAttribute('title', label.textContent);
    }

    function scheduleTabsLayout() {
        window.setTimeout(syncTabsLayout, 0);
        window.setTimeout(syncTabsLayout, 100);

        if (document.readyState === 'loading' && !tabsLayoutListenersBound) {
            tabsLayoutListenersBound = true;
            document.addEventListener('DOMContentLoaded', function () {
                window.setTimeout(syncTabsLayout, 0);
            });
            window.addEventListener('load', syncTabsLayout);
        }
    }

    function syncTabsLayout() {
        var tabsLeft = navigationMode === 'classic' ? classicTabsLeft : globalTabsLeft;
        var contentTabs = document.querySelector('.content-tabs');
        window.tabs_left = tabsLeft;

        if (contentTabs) {
            contentTabs.style.left = tabsLeft + 'px';
            contentTabs.style.width = '100%';
        }

        if ($) {
            $(window).trigger('resize');
        }
    }

    function openPanel(section, trigger) {
        if (navigationMode !== 'global') {
            return;
        }

        lastFocusedElement = trigger || document.activeElement;

        if (section === 'favorites' || section === 'recent') {
            activeCategory = section;
        } else if (activeCategory.indexOf('root:') !== 0 && tree[0]) {
            activeCategory = 'root:' + tree[0].id;
        }

        searchInput.value = '';
        renderActiveCategory();
        overlay.hidden = false;
        panel.setAttribute('aria-hidden', 'false');
        if (logoButton) {
            logoButton.setAttribute('aria-expanded', 'true');
            logoButton.setAttribute('aria-label', '关闭全部菜单');
            logoButton.setAttribute('title', '关闭菜单');
        }
        document.body.classList.add('dc-admin-nav-lock');

        panel.offsetWidth;
        panel.classList.add('is-open');
        overlay.classList.add('is-open');
        searchInput.focus();
    }

    function closePanel() {
        panel.classList.remove('is-open');
        overlay.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        if (logoButton) {
            logoButton.setAttribute('aria-expanded', 'false');
            logoButton.setAttribute('aria-label', '打开全部菜单');
            logoButton.setAttribute('title', '全部菜单');
        }
        document.body.classList.remove('dc-admin-nav-lock');
        window.setTimeout(function () {
            overlay.hidden = true;
        }, 210);

        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    }

    function buildCategories() {
        categoryList.innerHTML = tree.map(function (node) {
            return '<button type="button" title="' + escapeAttribute(node.title) + '" data-dc-nav-category="root:' + escapeAttribute(node.id) + '">' +
                '<span>' + escapeHtml(node.title) + '</span></button>';
        }).join('');

        updateActiveCategoryButton();
    }

    function renderActiveCategory() {
        updateActiveCategoryButton();

        if (activeCategory === 'favorites') {
            renderItemCollection('我的收藏', mapIds(favorites), '还没有收藏菜单');
            return;
        }

        if (activeCategory === 'recent') {
            renderItemCollection('最近访问', mapIds(recents), '还没有最近访问记录');
            return;
        }

        var rootId = activeCategory.replace(/^root:/, '');
        var root = tree.filter(function (node) { return String(node.id) === rootId; })[0];

        if (!root) {
            renderEmpty('当前分类没有可用菜单');
            return;
        }

        renderRoot(root);
    }

    function renderRoot(root) {
        var groups = [];
        var directItems = [];

        if (root.clickable) {
            directItems.push(root);
        }

        (root.children || []).forEach(function (child) {
            if (child.children && child.children.length) {
                var groupItems = flattenClickable(child.children);
                if (child.clickable) {
                    groupItems.unshift(child);
                }
                if (groupItems.length) {
                    groups.push({
                        title: child.title,
                        items: groupItems,
                        contextDepth: child.path.length
                    });
                }
            } else if (child.clickable) {
                directItems.push(child);
            }
        });

        if (directItems.length) {
            groups.unshift({
                title: groups.length ? '其他功能' : '',
                items: directItems,
                contextDepth: root.path.length
            });
        }

        results.innerHTML = groups.length
            ? groups.map(renderGroup).join('')
            : emptyHtml('当前分类没有可用菜单');
    }

    function renderSearch(value) {
        var keywords = String(value || '').trim().toLowerCase().split(/\s+/).filter(Boolean);

        if (!keywords.length) {
            renderActiveCategory();
            return;
        }

        var matches = items.map(function (item, index) {
            var title = String(item.title || '').toLowerCase();
            var path = (item.path || []).join(' / ').toLowerCase();
            var uri = String(item.uri || '').toLowerCase();
            var matched = keywords.every(function (keyword) {
                return title.indexOf(keyword) >= 0 || path.indexOf(keyword) >= 0 || uri.indexOf(keyword) >= 0;
            });
            var first = keywords[0];
            var score = title.indexOf(first) === 0 ? 0 : title.indexOf(first) >= 0 ? 1 : path.indexOf(first) >= 0 ? 2 : 3;
            return matched ? { item: item, score: score, index: index } : null;
        }).filter(Boolean).sort(function (a, b) {
            return a.score - b.score || a.index - b.index;
        }).slice(0, searchLimit).map(function (result) {
            return result.item;
        });

        clearActiveCategoryButton();
        renderItemCollection('搜索结果（' + matches.length + '）', matches, '没有找到匹配的菜单');
    }

    function renderItemCollection(title, collection, emptyMessage) {
        if (!collection.length) {
            results.innerHTML = '<h3 class="dc-admin-nav-results-title">' + escapeHtml(title) + '</h3>' + emptyHtml(emptyMessage);
            return;
        }

        results.innerHTML = '<h3 class="dc-admin-nav-results-title">' + escapeHtml(title) + '</h3>' +
            '<div class="dc-admin-nav-group"><div class="dc-admin-nav-grid">' + collection.map(function (item) {
                return renderItem(item, 0);
            }).join('') + '</div></div>';
    }

    function renderGroup(group) {
        var title = group.title
            ? '<h4 class="dc-admin-nav-group-title" title="' + escapeAttribute(group.title) + '">' + escapeHtml(group.title) + '</h4>'
            : '';

        return '<section class="dc-admin-nav-group">' + title +
            '<div class="dc-admin-nav-grid">' + group.items.map(function (item) {
                return renderItem(item, group.contextDepth);
            }).join('') + '</div></section>';
    }

    function renderItem(item, contextDepth) {
        var id = String(item.id);
        var isFavorite = favorites.indexOf(id) >= 0;
        var path = (item.path || []).slice(contextDepth || 0, -1).join(' / ');

        return '<article class="dc-admin-nav-item" tabindex="0" role="link" title="' + escapeAttribute(item.title) + '" data-dc-nav-item="' + escapeAttribute(id) + '">' +
            '<div class="dc-admin-nav-item-main"><span class="dc-admin-nav-item-title">' + escapeHtml(item.title) + '</span>' +
            (path ? '<small class="dc-admin-nav-item-path" title="' + escapeAttribute(path) + '">' + escapeHtml(path) + '</small>' : '') + '</div>' +
            '<button type="button" class="dc-admin-nav-favorite' + (isFavorite ? ' is-favorite' : '') + '" ' +
            'data-dc-nav-favorite="' + escapeAttribute(id) + '" aria-label="' + (isFavorite ? '取消收藏' : '收藏') + '">' +
            '<i class="fa ' + (isFavorite ? 'fa-star' : 'fa-star-o') + '" aria-hidden="true"></i></button></article>';
    }

    function renderEmpty(message) {
        results.innerHTML = emptyHtml(message);
    }

    function emptyHtml(message) {
        return '<div class="dc-admin-nav-empty"><i class="fa fa-inbox" aria-hidden="true"></i>' + escapeHtml(message) + '</div>';
    }

    function toggleFavorite(id) {
        id = String(id);
        var index = favorites.indexOf(id);

        if (index >= 0) {
            favorites.splice(index, 1);
        } else if (itemsById[id]) {
            favorites.push(id);
        }

        persistIds('favorites', favorites);

        if (searchInput.value.trim()) {
            renderSearch(searchInput.value);
        } else {
            renderActiveCategory();
        }
    }

    function recordRecent(id) {
        id = String(id);
        recents = recents.filter(function (storedId) { return storedId !== id; });
        recents.unshift(id);
        recents = recents.slice(0, recentLimit);
        persistIds('recents', recents);
    }

    function openMenuItem(item) {
        if (!item || !item.clickable || !item.url) {
            return;
        }

        if (item.external) {
            window.open(item.url, '_blank', 'noopener');
            closePanel();
            return;
        }

        recordRecent(item.id);
        closePanel();

        var path = item.url.replace(/^(https?:\/\/[^/]+?)(\/.+)$/, '$2');
        var pageId = path === window.home_uri ? '_admin_dashboard' : path.replace(/\W/g, '_');

        if (typeof window.openTab === 'function') {
            window.openTab(item.url, item.title, item.icon, pageId, true, 'absolute');
            return;
        }

        if (typeof window.addTabs === 'function') {
            window.addTabs({
                id: pageId,
                title: item.title,
                close: true,
                url: item.url,
                urlType: 'absolute',
                icon: '<i class="fa ' + safeIcon(item.icon) + '"></i>'
            });
            return;
        }

        window.location.href = item.url;
    }

    function flattenClickable(nodes) {
        var flattened = [];
        (nodes || []).forEach(function (node) {
            if (node.clickable) {
                flattened.push(node);
            }
            flattened = flattened.concat(flattenClickable(node.children || []));
        });
        return flattened;
    }

    function mapIds(ids) {
        return ids.map(function (id) { return itemsById[id]; }).filter(Boolean);
    }

    function validIds(ids) {
        var seen = {};
        return ids.map(String).filter(function (id) {
            if (!itemsById[id] || seen[id]) {
                return false;
            }
            seen[id] = true;
            return true;
        });
    }

    function readStoredIds(type) {
        try {
            var value = JSON.parse(window.localStorage.getItem(storagePrefix + type) || '[]');
            return Array.isArray(value) ? value : [];
        } catch (error) {
            return [];
        }
    }

    function persistIds(type, ids) {
        try {
            window.localStorage.setItem(storagePrefix + type, JSON.stringify(ids));
        } catch (error) {
            // Browsers with disabled storage still retain full navigation support.
        }
    }

    function updateActiveCategoryButton() {
        clearActiveCategoryButton();
        var button = panel.querySelector('[data-dc-nav-category="' + cssEscape(activeCategory) + '"]');
        if (button) {
            button.classList.add('is-active');
        }
    }

    function clearActiveCategoryButton() {
        Array.prototype.forEach.call(panel.querySelectorAll('[data-dc-nav-category].is-active'), function (button) {
            button.classList.remove('is-active');
        });
    }

    function closest(element, selector) {
        while (element && element !== panel) {
            if (element.matches && element.matches(selector)) {
                return element;
            }
            element = element.parentElement;
        }
        return null;
    }

    function safeIcon(icon) {
        var value = String(icon || 'fa-circle-o');
        return /^[a-zA-Z0-9 _-]+$/.test(value) ? value : 'fa-circle-o';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/(["\\])/g, '\\$1');
    }
})(window, document, window.jQuery);
