@php
    $menuSearchEnabled = config('admin.enable_menu_search');
@endphp

@if($menuSearchEnabled)
    @php
        $cssPath = 'vendor/laravel-admin/classic-menu-search.css';
        $jsPath = 'vendor/laravel-admin/classic-menu-search.js';
        $cssVersion = is_file(public_path($cssPath)) ? filemtime(public_path($cssPath)) : '1';
        $jsVersion = is_file(public_path($jsPath)) ? filemtime(public_path($jsPath)) : '1';
    @endphp
    <link rel="stylesheet" href="{{ admin_asset($cssPath) }}?v={{ $cssVersion }}">
@endif

<aside class="main-sidebar{{ empty($navigationClass) ? '' : ' '.$navigationClass }}">
    <section class="sidebar">
        <div class="user-panel">
            <div class="pull-left image">
                <img src="{{ Admin::user()->avatar }}" class="img-circle" alt="User Image">
            </div>
            <div class="pull-left info">
                <p>{{ Admin::user()->name }}</p>
                <a href="#"><i class="fa fa-circle text-success"></i> {{ trans('admin.online') }}</a>
            </div>
        </div>

        @if($menuSearchEnabled)
            <div class="classic-menu-search" role="search" data-menu-search hidden>
                <i class="fa fa-search" aria-hidden="true"></i>
                <input type="search" data-menu-search-input autocomplete="off" aria-controls="classic-sidebar-menu"
                       aria-label="{{ trans('admin.menu_search.title') }}" placeholder="{{ trans('admin.menu_search.title') }}">
                <button type="button" data-menu-search-clear aria-label="{{ trans('admin.menu_search.clear') }}" hidden>
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>
        @endif

        <ul class="sidebar-menu" id="classic-sidebar-menu">
            <li class="header">{{ trans('admin.menu') }}</li>
            @each('admin::partials.menu', Admin::menu(), 'item')
        </ul>
        @if($menuSearchEnabled)
            <p class="classic-menu-search-empty" data-menu-search-empty role="status" hidden>{{ trans('admin.menu_search.empty') }}</p>
        @endif
    </section>
</aside>

@if($menuSearchEnabled)
    <script src="{{ admin_asset($jsPath) }}?v={{ $jsVersion }}" defer></script>
@endif
