<aside class="app-sidebar{{ empty($navigationClass) ? '' : ' '.$navigationClass }}">
    <div class="sidebar-brand">
        <a href="{{ admin_url('/') }}" class="brand-link">
            <span class="brand-text fw-light">{!! config('admin.logo') ?: config('admin.name') !!}</span>
        </a>
    </div>

    <div class="sidebar-wrapper">
        <div class="user-panel d-flex align-items-center px-3 py-2">
            <div class="image">
                <img src="{{ Admin::user()->avatar }}" class="rounded-circle shadow" alt="{{ Admin::user()->name }}" width="40" height="40">
            </div>
            <div class="info ms-2">
                <a href="#" class="d-block text-decoration-none">{{ Admin::user()->name }}</a>
                <span class="small text-success"><i class="fa fa-circle" aria-hidden="true"></i> {{ trans('admin.online') }}</span>
            </div>
        </div>

        @if(config('admin.enable_menu_search'))
            <form class="sidebar-form px-3 py-2" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" autocomplete="off" class="form-control autocomplete" placeholder="Search...">
                    <button type="submit" name="search" id="search-btn" class="btn btn-outline-secondary">
                        <i class="fa fa-search" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu" role="menu" style="min-width: 210px; max-height: 300px; overflow: auto;">
                        @foreach(Admin::menuLinks() as $link)
                            <li>
                                <a class="dropdown-item" href="{{ admin_url($link['uri']) }}">
                                    <i class="fa {{ $link['icon'] }}" aria-hidden="true"></i>{{ admin_trans($link['title']) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </form>
        @endif

        <nav class="mt-1" aria-label="{{ trans('admin.menu') }}">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
                <li class="nav-header">{{ trans('admin.menu') }}</li>
                @each('admin::partials.menu', Admin::menu(), 'item')
            </ul>
        </nav>
    </div>
</aside>
