<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="renderer" content="webkit">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="admin-loading-text" content="{{ trans('admin.loading') }}" data-submitting="{{ trans('admin.submitting') }}" data-failed="{{ trans('admin.request_failed') }}">
    <title>{{ Admin::title() }} @if($header) | {{ $header }}@endif</title>
    <meta content="width=device-width, initial-scale=1" name="viewport">

    @if(!is_null($favicon = Admin::favicon()))
        <link rel="shortcut icon" href="{{ $favicon }}">
    @endif

    @if(config('admin.color_mode') === 'auto')
        <script>
            document.documentElement.setAttribute('data-bs-theme', window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        </script>
    @endif

    {!! Admin::css() !!}

    <script src="{{ Admin::jQuery() }}"></script>
    {!! Admin::headerJs() !!}
</head>

<body class="{{ join(' ', config('admin.layout', [])) }} bg-body-tertiary"
      data-admin-theme="{{ config('admin.theme', 'default') }}"
      @if(config('admin.color_mode') === 'light') data-bs-theme="light" @endif
      @if(config('admin.color_mode') === 'dark') data-bs-theme="dark" @endif>

@if($alert = config('admin.top_alert'))
    <div class="text-center py-1 small bg-warning-subtle text-danger-emphasis">
        {!! $alert !!}
    </div>
@endif

<div class="app-wrapper">

    @include('admin::partials.header')

    @include('admin::partials.sidebar')

    <main class="app-main" id="admin-content">
        {!! Admin::style() !!}
        <div id="app">
            @yield('content')
        </div>
        {!! Admin::script() !!}
        {!! Admin::html() !!}
    </main>

    @include('admin::partials.footer')

</div>

<button id="totop" class="btn btn-dark btn-sm" title="Go to top" style="display: none;">
    <i class="fa fa-chevron-up"></i>
</button>

<script>
    function LA() {}
    LA.token = "{{ csrf_token() }}";
    LA.user = @json($_user_);
</script>
<meta name="admin-login-url" content="{{ admin_url(config('admin.auth.redirect_to', 'auth/login')) }}" data-expired="{{ trans('admin.authentication_expired') }}" data-expired-page="{{ trans('admin.request_failed') }}">

{!! Admin::js() !!}

</body>
</html>
