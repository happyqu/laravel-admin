<div class="dc-admin-nav-overlay" data-dc-nav-close hidden></div>

<section id="dc-admin-nav-panel" class="dc-admin-nav-panel" aria-label="全部菜单" aria-hidden="true">
    <header class="dc-admin-nav-panel-header">
        <h2>全部菜单</h2>
        <label class="dc-admin-nav-search-wrap" for="dc-admin-nav-search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input id="dc-admin-nav-search" class="dc-admin-nav-search" type="search"
                   placeholder="搜索菜单、路径或地址" autocomplete="off">
        </label>
        <button type="button" class="dc-admin-nav-close" data-dc-nav-close aria-label="关闭全部菜单">
            <span aria-hidden="true">×</span>
        </button>
    </header>

    <div class="dc-admin-nav-panel-body">
        <nav class="dc-admin-nav-categories" aria-label="菜单分类">
            <div class="dc-admin-nav-category-shortcuts">
                <button type="button" data-dc-nav-category="recent">
                    <span>最近访问</span>
                </button>
                <button type="button" data-dc-nav-category="favorites">
                    <span>我的收藏</span>
                </button>
                <div class="dc-admin-nav-category-divider"></div>
            </div>
            <div class="dc-admin-nav-category-label">业务分类</div>
            <div class="dc-admin-nav-category-list" data-dc-nav-category-list></div>
        </nav>

        <main class="dc-admin-nav-results" data-dc-nav-results aria-live="polite"></main>
    </div>
</section>

<script id="dc-admin-navigation-data" type="application/json">{!! json_encode($navigation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
