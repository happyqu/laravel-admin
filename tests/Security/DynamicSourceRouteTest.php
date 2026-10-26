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
}
