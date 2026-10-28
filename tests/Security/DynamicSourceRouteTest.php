<?php

namespace Encore\Admin\Tests\Security;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Security\FormSubmission;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class DynamicSourceRouteTest extends TestCase
{
    private $previous;
    private Application $app;
    private object $user;
    private Request $request;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository(['admin' => [
            'directory' => dirname(__DIR__).'/Fixtures', 'route' => ['file' => 'dynamic-admin-routes.php'],
        ]]));
        $this->request = Request::create('http://localhost/admin/_handle_action_', 'POST');
        $this->app->instance('request', $this->request);
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->user = new class {
            public bool $allowed = true;
            public function isRole($role) { return false; }
            public function can($permission) { return $this->allowed && $permission === 'manage-settings'; }
        };
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn($this->user);
        Admin::swap($admin);
        $this->app['router']->post('admin/_handle_action_', fn () => null);
        $this->app['router']->aliasMiddleware('can', \Illuminate\Auth\Middleware\Authorize::class);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    public function test_dynamic_source_is_authorized_without_executing_it_or_replacing_live_routes(): void
    {
        $routes = $this->app['router']->getRoutes();
        FormSubmission::authorizeSource('http://localhost/admin/settings', 'GET');
        $this->assertSame($routes, $this->app['router']->getRoutes());
        $this->assertCount(1, $routes);
        $this->assertSame($this->request, request());
        $this->assertNull(config('test.source_executed'));
    }

    public function test_dynamic_source_still_checks_current_permissions(): void
    {
        $this->user->allowed = false;
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        FormSubmission::authorizeSource('http://localhost/admin/settings', 'GET');
    }

    public function test_removed_source_is_rejected_and_request_is_restored(): void
    {
        config(['test.source_enabled' => false]);
        try {
            FormSubmission::authorizeSource('http://localhost/admin/settings', 'GET');
            $this->fail('Missing source route was accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame($this->request, request());
            $this->assertCount(1, $this->app['router']->getRoutes());
        }
    }

    public function test_existing_routes_do_not_reload_the_route_file(): void
    {
        config(['test.route_load_error' => true]);
        $this->app['router']->get('admin/settings', fn () => null)->middleware('admin.permission:check,manage-settings');
        FormSubmission::authorizeSource('http://localhost/admin/settings', 'GET');
        $this->assertSame($this->request, request());
    }

    public function test_route_registration_failure_also_restores_request_and_routes(): void
    {
        config(['test.route_load_error' => true]);
        $routes = $this->app['router']->getRoutes();
        try {
            FormSubmission::authorizeSource('http://localhost/admin/settings', 'GET');
            $this->fail('Route registration error was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Route registration failed.', $exception->getMessage());
            $this->assertSame($this->request, request());
            $this->assertSame($routes, $this->app['router']->getRoutes());
        }
    }

    public function test_source_policies_are_rechecked_with_bound_models_and_the_admin_user(): void
    {
        $record = (object) ['id' => 7];
        $this->app['router']->bind('record', fn ($id) => $id === '7' ? $record : null);
        $this->app['router']->get('admin/records/{record}', fn () => null)
            ->middleware(['admin.permission:check,manage-settings', 'can:update,record']);
        $allowed = true;
        $gate = new \Illuminate\Auth\Access\Gate($this->app, static fn () => null);
        $gate->define('update', function ($user, $model) use ($record, &$allowed) {
            $this->assertSame($this->user, $user);
            $this->assertSame($record, $model);
            $this->assertSame('admin/records/7', request()->path());
            return $allowed;
        });
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        FormSubmission::authorizeSource('http://localhost/admin/records/7', 'GET');
        $this->assertSame($this->request, request());
        $allowed = false;
        try {
            FormSubmission::authorizeSource('http://localhost/admin/records/7', 'GET');
            $this->fail('Revoked source policy was ignored.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            $this->assertSame($this->request, request());
        }
    }

    public function test_source_policy_aliases_and_groups_are_resolved(): void
    {
        $this->app['router']->aliasMiddleware('review-policy', \Illuminate\Auth\Middleware\Authorize::class);
        $this->app['router']->middlewareGroup('review-policies', ['review-policy:approve']);
        $this->app['router']->get('admin/restricted', fn () => null)
            ->middleware(['admin.permission:free', 'review-policies']);
        $gate = new \Illuminate\Auth\Access\Gate($this->app, fn () => $this->user);
        $gate->define('approve', static fn ($user) => false);
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        FormSubmission::authorizeSource('http://localhost/admin/restricted', 'GET');
    }

    public function test_all_explicit_source_permissions_are_checked(): void
    {
        $this->app['router']->get('admin/restricted', fn () => null)
            ->middleware(['admin.permission:free', 'admin.permission:check,ungranted']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        FormSubmission::authorizeSource('http://localhost/admin/restricted', 'GET');
    }

    public function test_source_policies_support_implicit_route_model_binding(): void
    {
        $this->app['router']->get('admin/documents/{document}', function (PolicyDocument $document) {})
            ->middleware(['admin.permission:free', 'can:view,document']);
        $gate = new \Illuminate\Auth\Access\Gate($this->app, fn () => $this->user);
        $gate->define('view', function ($user, PolicyDocument $document) {
            $this->assertSame('7', $document->getKey());
            $this->assertSame($document, request()->route('document'));
            return true;
        });
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        FormSubmission::authorizeSource('http://localhost/admin/documents/7', 'GET');
        $this->assertSame($this->request, request());
        $this->assertNull($this->app['router']->current());
        $this->assertNull($this->app['router']->getCurrentRequest());
        $this->assertFalse($this->app->bound(\Illuminate\Routing\Route::class));
    }

    public function test_explicitly_excluded_policy_middleware_is_not_executed(): void
    {
        $this->app['router']->get('admin/restricted', fn () => null)
            ->middleware(['admin.permission:free', 'can:approve'])->withoutMiddleware('can:approve');
        FormSubmission::authorizeSource('http://localhost/admin/restricted', 'GET');
        $this->assertSame($this->request, request());
    }

    public function test_policy_route_context_is_the_source_and_is_restored_on_success_or_denial(): void
    {
        $router = $this->app['router'];
        $router->getRoutes()->match($this->request)->name('dynamic-action');
        $router->dispatch($this->request);
        $originalRoute = $router->current();
        $originalRouterRequest = $router->getCurrentRequest();
        $record = (object) ['id' => 7];
        $router->bind('record', function ($id) use ($record, $router) {
            $this->assertSame('source-record', $router->currentRouteName());
            $this->assertSame('7', $id);
            return $record;
        });
        $router->get('admin/records/{record}', fn () => null)->name('source-record')
            ->middleware(['admin.permission:free', 'can:view,record']);
        $allowed = true;
        $gate = new \Illuminate\Auth\Access\Gate($this->app, fn () => $this->user);
        $gate->define('view', function ($user, $model) use ($record, &$allowed) {
            $this->assertSame($record, $model);
            $this->assertSame('source-record', \Illuminate\Support\Facades\Route::currentRouteName());
            $this->assertSame($record, \Illuminate\Support\Facades\Route::current()->parameter('record'));
            $this->assertSame(request(), app('router')->getCurrentRequest());
            $this->assertSame(request()->route(), app(\Illuminate\Routing\Route::class));
            return $allowed;
        });
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        foreach ([true, false] as $allow) {
            $allowed = $allow;
            try {
                FormSubmission::authorizeSource('http://localhost/admin/records/7', 'GET');
                $this->assertTrue($allow);
            } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
                $this->assertFalse($allow);
            }
            $this->assertSame($this->request, request());
            $this->assertSame($originalRoute, $router->current());
            $this->assertSame($originalRouterRequest, $router->getCurrentRequest());
            $this->assertSame($originalRoute, app(\Illuminate\Routing\Route::class));
        }
    }

    public function test_source_binding_failures_also_restore_the_dynamic_route_context(): void
    {
        $router = $this->app['router'];
        $router->dispatch($this->request);
        $originalRoute = $router->current();
        $router->bind('record', static function () { throw new \RuntimeException('Binding failed.'); });
        $router->get('admin/records/{record}', fn () => null)->middleware(['admin.permission:free', 'can:view,record']);
        try {
            FormSubmission::authorizeSource('http://localhost/admin/records/7', 'GET');
            $this->fail('Binding failure was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Binding failed.', $exception->getMessage());
            $this->assertSame($this->request, request());
            $this->assertSame($originalRoute, $router->current());
            $this->assertSame($this->request, $router->getCurrentRequest());
            $this->assertSame($originalRoute, app(\Illuminate\Routing\Route::class));
        }
    }
}

class PolicyDocument extends \Illuminate\Database\Eloquent\Model
{
    protected $keyType = 'string';
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->forceFill(['id' => $value]);
    }
}
