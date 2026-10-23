<?php

namespace Encore\Admin\Providers;

use Encore\Admin\Admin;
use Encore\Admin\Concerns\RegistersIframeTabs;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AdminFrontendServiceProvider extends ServiceProvider
{
    use RegistersIframeTabs;

    public function register()
    {
        $this->registerFrontendAssets();
        $this->registerIframeTabs();
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'admin');

        if (file_exists($routes = admin_path(config('admin.route.file', 'routes.php')))) {
            $this->loadRoutesFrom($routes);
        }

        $this->compatibleBlade();

        Blade::directive('box', function ($title) {
            return "<?php \$box = new \Encore\Admin\Widgets\Box({$title}, '";
        });

        Blade::directive('endbox', function ($expression) {
            return "'); echo \$box->render(); ?>";
        });

        $this->bootIframeTabs();
    }

    protected function registerFrontendAssets()
    {
        Admin::booting(function () {
            if (config('admin.navigation.enabled')) {
                config(['admin.iframe_tabs.tabs_left' => (int) config('admin.navigation.tabs_left', 0)]);
            }

            if (config('admin.frontend.custom_css', true)) {
                Admin::css('vendor/laravel-admin/custom.css');
            }

            if (config('admin.frontend.jwt_iframe_css', true) && request()->filled('jwt')) {
                Admin::css('vendor/laravel-admin/iframe.css');
            }
        });
    }

    protected function compatibleBlade()
    {
        Blade::withoutDoubleEncoding();
    }
}
