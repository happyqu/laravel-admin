<?php

namespace Encore\Admin\Security;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;

/** A session-bound form ticket records the server-rendered class and source route. */
class FormSubmission
{
    public static function issue(Form $form): string
    {
        $tickets = array_filter(session('admin.form_submissions', []), static fn ($ticket) => $ticket['expires'] >= time());
        $tickets = array_slice($tickets, -127, null, true);
        $token = bin2hex(random_bytes(24));
        $source = request()->attributes->get('_admin_component_source', [request()->url(), request()->method()]);
        $tickets[$token] = [
            'class' => get_class($form), 'source' => $source[0],
            'method' => $source[1],
            'user' => (string) (Admin::user()?->getAuthIdentifier() ?? ''),
            'guard' => config('admin.auth.guard', 'admin'), 'expires' => time() + 7200,
            'scope' => [config('admin.route.prefix'), config('admin.database.connection')],
            'context' => $form->submissionContext(),
        ];
        session()->put('admin.form_submissions', $tickets);
        return $token;
    }

    public static function verify(Request $request, string $class): array
    {
        $token = $request->input('_form_token_');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{48}$/D', $token), 419, 'Invalid form ticket.');
        $ticket = session('admin.form_submissions.'.$token);
        abort_unless(is_array($ticket) && $ticket['expires'] >= time() && $ticket['class'] === $class, 419, 'Expired form ticket.');
        $user = Admin::user();
        abort_unless($user && $ticket['user'] === (string) $user->getAuthIdentifier()
            && $ticket['guard'] === config('admin.auth.guard', 'admin')
            && $ticket['scope'] === [config('admin.route.prefix'), config('admin.database.connection')], 403);
        static::authorizeSource($ticket['source'], $ticket['method']);
        return $ticket['context'];
    }

    public static function authorizeSource(string $url, string $method, bool $reusePermissions = false): void
    {
        // Preserve the front controller so pathInfo excludes a deployment subdirectory.
        $server = array_intersect_key(request()->server->all(), array_flip([
            'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF',
        ]));
        $request = Request::create($url, $method, [], [], [], $server);
        try {
            $route = app('router')->getRoutes()->match($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $route = static::matchDynamicSource($request);
        }
        $request->setRouteResolver(static fn () => $route);
        $user = Admin::user();
        $request->setUserResolver(static fn () => $user);
        static::authorizePolicies($request, $route, $user);
        if ($user->isRole('administrator')) {
            return;
        }
        $hasPermissionMiddleware = false;
        foreach (\Encore\Admin\Middleware\Permission::routePermissionArguments($route) as $args) {
            $method = array_shift($args);
            $allowed = match ($method) {
                'free' => true,
                'check' => collect($args)->every(static fn ($permission) => $user->can($permission)),
                'allow' => $user->inRoles($args),
                'deny' => !$user->inRoles($args),
                default => false,
            };
            abort_unless($allowed, 403);
            $hasPermissionMiddleware = true;
        }
        if (!$hasPermissionMiddleware && config('admin.check_route_permission') !== false) {
            abort_unless(static::sourcePermissions($user, $reusePermissions)->contains(static fn ($permission) => $permission->shouldPassThrough($request)), 403);
        }
    }

    /**
     * Whether any configured route serves this url and method.
     *
     * Used to tell "this page has no resource route" apart from "this user may not use it":
     * an absent route must not be reported as an authorization failure. Only the routes already
     * registered for the current request are inspected.
     */
    public static function hasSourceRoute(string $url, string $method): bool
    {
        // Preserve the front controller so pathInfo excludes a deployment subdirectory.
        $server = array_intersect_key(request()->server->all(), array_flip([
            'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF',
        ]));

        try {
            app('router')->getRoutes()->match(Request::create($url, $method, [], [], [], $server));

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [404, 405], true)) {
                return false;
            }

            throw $exception;
        }
    }

    /** Reuse only render-time permission collections; execution always reads them again. */
    private static function sourcePermissions($user, bool $reusePermissions)
    {
        if (!$reusePermissions) {
            return $user->allPermissions();
        }

        $attributes = app('request')->attributes;
        $permissions = $attributes->get('_admin_render_permissions');
        if (!$permissions instanceof \WeakMap) {
            $permissions = new \WeakMap();
            $attributes->set('_admin_render_permissions', $permissions);
        }

        return $permissions[$user] ??= $user->allPermissions();
    }

    /** Recheck Laravel policy middleware using the source route and its bound models. */
    private static function authorizePolicies(Request $request, $route, $user): void
    {
        $router = app('router');
        $policies = [];
        foreach ($router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()) as $middleware) {
            if (!is_string($middleware)) continue;
            [$class, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');
            if ($class === 'can' || is_a($class, \Illuminate\Auth\Middleware\Authorize::class, true)) {
                $policies[] = explode(',', $parameters);
            }
        }
        if (!$policies) return;

        $original = app('request');
        $originalRoute = $router->current();
        $originalRouterRequest = $router->getCurrentRequest();
        $routeClass = \Illuminate\Routing\Route::class;
        $hadRouteBinding = app()->bound($routeClass);
        $originalRouteBinding = $hadRouteBinding ? app($routeClass) : null;
        // Router has no public context setter; update its context without dispatching the source action.
        $setRouterContext = \Closure::bind(function ($route, $request) {
            $this->current = $route;
            $this->currentRequest = $request;
        }, $router, \Illuminate\Routing\Router::class);
        try {
            app()->instance('request', $request);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
            $setRouterContext($route, $request);
            app()->instance($routeClass, $route);
            $router->substituteBindings($route);
            $router->substituteImplicitBindings($route);
            $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser($user);
            $authorize = new \Illuminate\Auth\Middleware\Authorize($gate);
            foreach ($policies as $parameters) {
                $authorize->handle($request, static fn () => null, ...$parameters);
            }
        } finally {
            $setRouterContext($originalRoute, $originalRouterRequest);
            if ($hadRouteBinding) {
                app()->instance($routeClass, $originalRouteBinding);
            } else {
                app()->forgetInstance($routeClass);
            }
            app()->instance('request', $original);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
        }
    }

    /** Resolve request-dependent admin routes using the server-recorded source. */
    private static function matchDynamicSource(Request $source)
    {
        $router = app('router');
        $routes = $router->getRoutes();
        $file = admin_path(config('admin.route.file', 'routes.php'));
        abort_unless($routes instanceof \Illuminate\Routing\RouteCollection && is_file($file), 403);
        $original = app('request');
        try {
            app()->instance('request', $source);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
            $router->setRoutes(new \Illuminate\Routing\RouteCollection());
            // Isolate local variables from the host's configured route file.
            (static function ($file) { require $file; })($file);
            try {
                return $router->getRoutes()->match($source);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                abort(403);
            }
        } finally {
            $router->setRoutes($routes);
            app()->instance('request', $original);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
        }
    }
}
