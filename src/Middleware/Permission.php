<?php

namespace Encore\Admin\Middleware;

use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Permission
{
    /**
     * @var string
     */
    protected $middlewarePrefix = 'admin.permission:';

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @param array                    $args
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, ...$args)
    {
        if (config('admin.check_route_permission') === false) {
            return $next($request);
        }

        if (!Admin::user() || $this->shouldPassThrough($request)) {
            return $next($request);
        }

        if (!empty($args)) {
            $this->checkPermissions($args);

            return $next($request);
        }

        if ($this->checkRoutePermission($request)) {
            return $next($request);
        }

        if (!Admin::user()->allPermissions()->first(function ($permission) use ($request) {
            return $permission->shouldPassThrough($request);
        })) {
            Checker::error();
        }

        return $next($request);
    }

    /**
     * Check every explicit permission condition, including controller middleware,
     * before falling back to HTTP path permissions.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function checkRoutePermission(Request $request)
    {
        $found = false;
        foreach ($request->route()->gatherMiddleware() as $middleware) {
            if (!is_string($middleware) || !Str::startsWith($middleware, $this->middlewarePrefix)) {
                continue;
            }

            $this->checkPermissions(explode(',', substr($middleware, strlen($this->middlewarePrefix))));
            $found = true;
        }

        return $found;
    }

    protected function checkPermissions(array $args): void
    {
        $method = array_shift($args);

        if (!in_array($method, ['check', 'allow', 'deny', 'free'], true)) {
            throw new \InvalidArgumentException("Invalid permission method [$method].");
        }

        call_user_func_array([Checker::class, $method], [$args]);
    }

    /**
     * Determine if the request has a URI that should pass through verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function shouldPassThrough($request)
    {
        // 下面这些路由不验证权限
        $excepts = array_merge(config('admin.auth.excepts', []), [
            'auth/login',
            'auth/logout',
            '_handle_action_',
            '_load_action_',
            '_handle_form_',
            '_handle_selectable_',
            '_handle_renderable_',
            '_cascader_',
        ]);

        return collect($excepts)
            ->map('admin_base_path')
            ->contains(function ($except) use ($request) {
                if ($except !== '/') {
                    $except = trim($except, '/');
                }

                return $request->is($except);
            });
    }
}
