<?php

namespace Encore\Admin\Security;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** A session-bound form ticket records the server-rendered class and source route. */
class FormSubmission
{
    public static function issue(Form $form): string
    {
        $tickets = array_filter(session('admin.form_submissions', []), static fn ($ticket) => $ticket['expires'] >= time());
        $tickets = array_slice($tickets, -127, null, true);
        $token = bin2hex(random_bytes(24));
        $tickets[$token] = [
            'class' => get_class($form), 'source' => request()->url(),
            'method' => request()->method(),
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

    public static function authorizeSource(string $url, string $method): void
    {
        $request = Request::create($url, $method);
        try {
            $route = app('router')->getRoutes()->match($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $route = static::matchDynamicSource($request);
        }
        $request->setRouteResolver(static fn () => $route);
        $user = Admin::user();
        if ($user->isRole('administrator')) {
            return;
        }
        foreach ($route->gatherMiddleware() as $middleware) {
            if (!is_string($middleware) || !Str::startsWith($middleware, 'admin.permission:')) {
                continue;
            }
            $args = explode(',', substr($middleware, strlen('admin.permission:')));
            $method = array_shift($args);
            $allowed = match ($method) {
                'free' => true,
                'check' => collect($args)->every(static fn ($permission) => $user->can($permission)),
                'allow' => $user->inRoles($args),
                'deny' => !$user->inRoles($args),
                default => false,
            };
            abort_unless($allowed, 403);
            return;
        }
        if (config('admin.check_route_permission') !== false) {
            abort_unless($user->allPermissions()->contains(static fn ($permission) => $permission->shouldPassThrough($request)), 403);
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
