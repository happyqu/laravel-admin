<nav class="app-header navbar navbar-expand navbar-static-top">
    <div class="container-fluid">

        <!-- Left navbar links -->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Toggle navigation">
                    <i class="fa fa-bars" aria-hidden="true"></i>
                </a>
            </li>

            {!! Admin::getNavbar()->render('left') !!}
        </ul>

        @if(!empty($iframe_tabs_bar))
            {!! $iframe_tabs_bar !!}
        @endif

        <!-- Right navbar links -->
        <ul class="navbar-nav ms-auto navbar-custom-menu">
            {!! Admin::getNavbar()->render() !!}

            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="{{ Admin::user()->avatar }}" class="user-image rounded-circle shadow" alt="{{ Admin::user()->name }}" width="32" height="32">
                    <span class="d-none d-md-inline ms-1">{{ Admin::user()->name }}</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="px-3 py-3 text-center border-bottom">
                        <img src="{{ Admin::user()->avatar }}" class="rounded-circle shadow mb-2" alt="{{ Admin::user()->name }}" width="64" height="64">
                        <div class="fw-semibold">{{ Admin::user()->name }}</div>
                        <div class="small text-body-secondary">Member since {{ Admin::user()->created_at }}</div>
                    </li>
                    <li>
                        <a href="{{ admin_url('auth/setting') }}" class="dropdown-item">
                            <i class="fa fa-gear me-2" aria-hidden="true"></i>{{ trans('admin.setting') }}
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a href="{{ admin_url('auth/logout') }}" class="dropdown-item">
                            <i class="fa fa-sign-out me-2" aria-hidden="true"></i>{{ trans('admin.logout') }}
                        </a>
                    </li>
                </ul>
            </li>
        </ul>

    </div>
</nav>
