<?php

namespace Encore\Admin\Tests\Middleware;

use Encore\Admin\Middleware\PermissionsPolicy;
use Encore\Admin\Providers\AdminRoutingServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

class PermissionsPolicyTest extends TestCase
{
    private $previousContainer;

    private Application $app;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();

        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'admin' => ['permissions_policy' => ['unload' => 'self']],
        ]));

        Container::setInstance($this->app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    public function test_it_allows_unload_handlers_by_default(): void
    {
        $this->assertSame('unload=self', $this->header());
    }

    public function test_it_keeps_the_directives_already_sent_by_the_application(): void
    {
        $this->assertSame('geolocation=(), unload=self', $this->header('geolocation=()'));
    }

    public function test_it_never_overrides_an_existing_unload_directive(): void
    {
        $this->assertSame('unload=()', $this->header('unload=()'));
    }

    public function test_it_can_be_disabled_with_an_empty_array(): void
    {
        $this->app['config']->set('admin.permissions_policy', []);

        $this->assertNull($this->header());
    }

    public function test_it_can_be_disabled_with_false(): void
    {
        $this->app['config']->set('admin.permissions_policy', false);

        $this->assertNull($this->header());
    }

    public function test_it_is_registered_on_the_admin_middleware_group(): void
    {
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);

        (new AdminRoutingServiceProvider($this->app))->register();

        $router = $this->app['router'];

        $this->assertSame(PermissionsPolicy::class, $router->getMiddleware()['admin.policy']);
        $this->assertContains('admin.policy', $router->getMiddlewareGroups()['admin']);
    }

    private function header(?string $existing = null): ?string
    {
        $headers = $existing === null ? [] : ['Permissions-Policy' => $existing];

        $response = (new PermissionsPolicy())->handle(
            Request::create('http://localhost/admin/users'),
            fn () => new Response('ok', 200, $headers)
        );

        return $response->headers->get('Permissions-Policy');
    }
}
