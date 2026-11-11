<?php

namespace Encore\Admin\Tests\Middleware;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Middleware\Permission;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PermissionTest extends TestCase
{
    private $previous;
    private Application $app;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'admin' => ['route' => ['prefix' => 'admin'], 'check_route_permission' => true],
            'app' => ['locale' => 'en'],
        ]));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $this->app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $user = new class {
            public function isRole($role) { return false; }
            public function cannot($permission) { return $permission !== 'read'; }
            public function inRoles($roles) { return in_array('operator', $roles, true); }
            public function allPermissions() { return collect(); }
        };
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn($user);
        Admin::swap($admin);
        $this->app['router']->aliasMiddleware('admin.permission', Permission::class);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function dispatch(array $middleware)
    {
        $router = $this->app['router'];
        if ($middleware !== []) {
            $router->group(['middleware' => array_slice($middleware, 0, 1)], function ($router) use ($middleware) {
                $router->get('admin/records', fn () => 'passed')->middleware(array_slice($middleware, 1));
            });
        }
        $request = Request::create('/admin/records');
        $request->headers->set('Accept', 'application/json');
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        return $router->dispatch($request);
    }

    public function test_every_nested_permission_condition_is_enforced(): void
    {
        $this->expectException(HttpException::class);
        try {
            $this->dispatch(['admin.permission', 'admin.permission:check,read', 'admin.permission:check,secret']);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            throw $exception;
        }
    }

    public function test_explicit_middleware_checks_permissions_without_a_global_instance(): void
    {
        $this->expectException(HttpException::class);
        $this->dispatch(['admin.permission:check,secret']);
    }

    public function test_free_does_not_bypass_a_later_role_restriction(): void
    {
        $this->expectException(HttpException::class);
        $this->dispatch(['admin.permission', 'admin.permission:free', 'admin.permission:deny,operator']);
    }

    public function test_all_satisfied_conditions_replace_path_matching(): void
    {
        $this->assertSame('passed', $this->dispatch([
            'admin.permission', 'admin.permission:check,read', 'admin.permission:allow,operator',
        ])->getContent());
    }

    public function test_permission_failure_in_controller_middleware_is_enforced(): void
    {
        $this->app['router']->get('admin/records', [PermissionTestController::class, 'index'])->middleware('admin.permission');
        $this->expectException(HttpException::class);
        $this->dispatch([]);
    }
}

class PermissionTestController extends \Illuminate\Routing\Controller
{
    public function __construct()
    {
        $this->middleware('admin.permission:check,secret');
    }

    public function index() { return 'passed'; }
}
