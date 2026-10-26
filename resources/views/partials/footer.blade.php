<footer class="app-footer">
    <div class="float-end d-none d-sm-inline">
        @if(config('admin.show_environment'))
            <strong>Env</strong>&nbsp;&nbsp; {!! config('app.env') !!}
        @endif

        @if(config('admin.show_environment') && config('admin.show_version'))
            &nbsp;&nbsp;&nbsp;&nbsp;
        @endif

        @if(config('admin.show_version'))
            <strong>Version</strong>&nbsp;&nbsp; {!! \Encore\Admin\Admin::VERSION !!}
        @endif
    </div>

    <strong>Powered by <a href="https://github.com/z-song/laravel-admin" target="_blank" rel="noopener">laravel-admin</a></strong>
</footer>
