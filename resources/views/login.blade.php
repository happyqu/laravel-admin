<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta content="width=device-width, initial-scale=1" name="viewport">
    <title>{{ config('admin.title') }} | {{ trans('admin.login') }}</title>

    @if(!is_null($favicon = Admin::favicon()))
        <link rel="shortcut icon" href="{{ $favicon }}">
    @endif

    <link rel="stylesheet" href="{{ admin_asset('vendor/laravel-admin/adminlte4/css/adminlte.min.css') }}">
    <link rel="stylesheet" href="{{ admin_asset('vendor/laravel-admin/font-awesome/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ admin_asset('vendor/laravel-admin/laravel-admin/pro.css') }}">
</head>
<body class="login-page bg-body-secondary"
      @if(config('admin.login_background_image')) style="background: url({{ config('admin.login_background_image') }}) no-repeat; background-size: cover;" @endif>

<div class="login-box">
    <div class="login-logo">
        <a href="{{ admin_url('/') }}"><b>{!! config('admin.name') !!}</b></a>
    </div>

    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">{{ trans('admin.login') }}</p>

            @if($errors->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ admin_url('auth/login') }}" method="post">
                @csrf

                <div class="input-group mb-3">
                    <input type="text" name="username" value="{{ old('username') }}" class="form-control @error('username') is-invalid @enderror"
                           placeholder="{{ trans('admin.username') }}" autocomplete="username" autofocus>
                    <div class="input-group-text"><span class="fa fa-user" aria-hidden="true"></span></div>
                </div>

                <div class="input-group mb-3">
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           placeholder="{{ trans('admin.password') }}" autocomplete="current-password">
                    <div class="input-group-text"><span class="fa fa-lock" aria-hidden="true"></span></div>
                </div>

                <div class="row align-items-center">
                    <div class="col-8">
                        @if(config('admin.auth.remember'))
                            <div class="form-check">
                                <input type="checkbox" name="remember" value="1" id="remember"
                                       class="form-check-input" {{ (!old('username') || old('remember')) ? 'checked' : '' }}>
                                <label class="form-check-label" for="remember">{{ trans('admin.remember_me') }}</label>
                            </div>
                        @endif
                    </div>
                    <div class="col-4">
                        <button type="submit" class="btn btn-primary w-100">{{ trans('admin.login') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="{{ admin_asset('vendor/laravel-admin/jquery/jquery-3.7.1.min.js') }}"></script>
<script src="{{ admin_asset('vendor/laravel-admin/bootstrap5/js/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
