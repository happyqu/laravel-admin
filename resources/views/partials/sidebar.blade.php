@if(config('admin.navigation.enabled'))
    @php
        $navigation = (new \Encore\Admin\Navigation\AdminMenuPresenter())->present(Admin::menu(), Admin::user());
        $cssPath = 'vendor/laravel-admin/global-navigation.css';
        $jsPath = 'vendor/laravel-admin/global-navigation.js';
        $cssVersion = is_file(public_path($cssPath)) ? filemtime(public_path($cssPath)) : '1';
        $jsVersion = is_file(public_path($jsPath)) ? filemtime(public_path($jsPath)) : '1';
    @endphp

    <script>
        (function (window, document, userId) {
            var mode = 'global';
            try {
                mode = window.localStorage.getItem('admin.global-navigation.mode.' + userId) || mode;
            } catch (error) {}
            mode = mode === 'classic' ? 'classic' : 'global';
            document.body.classList.add('dc-admin-nav-mode-' + mode);
            window.dcAdminNavigationInitialMode = mode;
        })(window, document, @json($navigation['userId'] ?? 'guest'));
    </script>

    {{-- Navigation assets are served by this host, independently of ASSET_URL. --}}
    <link rel="stylesheet" href="{{ url('/'.$cssPath) }}?v={{ $cssVersion }}">

    @include('admin::navigation.classic-sidebar', ['navigationClass' => 'dc-admin-nav-classic-sidebar'])

    @include('admin::navigation.product-panel', [
        'navigation' => array_merge($navigation, [
            'config' => [
                'sidebar_width' => (int) config('admin.navigation.sidebar_width', 50),
                'panel_width' => (int) config('admin.navigation.panel_width', 760),
                'tabs_left' => (int) config('admin.navigation.tabs_left', 0),
                'classic_tabs_left' => (int) config('admin.navigation.classic_tabs_left', 42),
                'recent_limit' => (int) config('admin.navigation.recent_limit', 10),
                'search_limit' => (int) config('admin.navigation.search_limit', 50),
            ],
        ]),
    ])
    <script src="{{ url('/'.$jsPath) }}?v={{ $jsVersion }}"></script>
@else
    @include('admin::navigation.classic-sidebar')
@endif
