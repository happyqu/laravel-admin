<?php

namespace Encore\Admin\Providers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Middleware;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AdminRoutingServiceProvider extends ServiceProvider
{
    protected $routeMiddleware = [
        'admin.auth'       => Middleware\Authenticate::class,
        'admin.log'        => Middleware\LogOperation::class,
        'admin.permission' => Middleware\Permission::class,
        'admin.bootstrap'  => Middleware\Bootstrap::class,
    ];

    protected $middlewareGroups = [
        'admin' => [
            'admin.auth',
            'admin.log',
            'admin.bootstrap',
            'admin.permission',
        ],
    ];

    public function register()
    {
        $this->registerRouteMiddleware();
        $this->macroRouter();
    }

    public function boot()
    {
        $this->ensureHttps();
    }

    protected function registerRouteMiddleware()
    {
        foreach ($this->routeMiddleware as $key => $middleware) {
            $this->app['router']->aliasMiddleware($key, $middleware);
        }

        foreach ($this->middlewareGroups as $key => $middleware) {
            $this->app['router']->middlewareGroup($key, $middleware);
        }
    }

    protected function macroRouter()
    {
        Router::macro('content', function ($uri, $content, $options = []) {
            return $this->match(['GET', 'HEAD'], $uri, function (Content $layout) use ($content, $options) {
                return $layout
                    ->title(Arr::get($options, 'title', ' '))
                    ->description(Arr::get($options, 'desc', ' '))
                    ->body($content);
            });
        });

        Router::macro('component', function ($uri, $component, $data = [], $options = []) {
            return $this->match(['GET', 'HEAD'], $uri, function (Content $layout) use ($component, $data, $options) {
                return $layout
                    ->title(Arr::get($options, 'title', ' '))
                    ->description(Arr::get($options, 'desc', ' '))
                    ->component($component, $data);
            });
        });
    }

    protected function ensureHttps()
    {
        $prefix = (string) config('admin.route.prefix', '');
        $isAdmin = Str::startsWith(request()->getRequestUri(), '/'.ltrim($prefix, '/'));

        if (config('admin.https') && $isAdmin) {
            url()->forceScheme('https');
            $this->app['request']->server->set('HTTPS', true);
        }
    }
}
