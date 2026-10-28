<?php

namespace Encore\Admin\Tests\Auth;

use Encore\Admin\Auth\Database\Permission;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PermissionMatchTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository(['admin' => [
            'route' => ['prefix' => 'admin'], 'database' => ['permissions_table' => 'permissions'],
        ]]));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    public function test_inline_methods_are_parsed_before_adding_the_route_prefix(): void
    {
        $permission = (new Permission())->forceFill([
            'http_method' => [], 'http_path' => "GET:/users\r\nPOST, PUT:/orders/*",
        ]);
        foreach ([['/admin/users', 'GET', true], ['/admin/users', 'POST', false],
            ['/admin/orders/7', 'POST', true], ['/admin/orders/7', 'PUT', true],
            ['/admin/orders/7', 'GET', false], ['/users', 'GET', false]] as [$path, $method, $expected]) {
            $this->assertSame($expected, $permission->shouldPassThrough(Request::create($path, $method)));
        }
    }

    public function test_plain_paths_keep_the_default_methods_with_or_without_a_prefix(): void
    {
        foreach (['admin', ''] as $prefix) {
            config(['admin.route.prefix' => $prefix]);
            $permission = (new Permission())->forceFill([
                'http_method' => ['GET'], 'http_path' => "POST:/special\n/users",
            ]);
            $path = ($prefix ? '/'.$prefix : '').'/users';
            $this->assertTrue($permission->shouldPassThrough(Request::create($path, 'GET')));
            $this->assertFalse($permission->shouldPassThrough(Request::create($path, 'POST')));
            $this->assertTrue($permission->shouldPassThrough(Request::create(($prefix ? '/'.$prefix : '').'/special', 'POST')));
        }
    }

    public function test_wildcard_permissions_cover_the_admin_root_and_children_only(): void
    {
        foreach (['admin', 'internal/admin', ''] as $prefix) {
            config(['admin.route.prefix' => $prefix]);
            foreach (['*', 'GET:*'] as $path) {
                $permission = (new Permission())->forceFill(['http_method' => [], 'http_path' => $path]);
                $root = $prefix ? '/'.$prefix : '/';
                $child = ($prefix ? '/'.$prefix : '').'/users';
                $this->assertTrue($permission->shouldPassThrough(Request::create($root, 'GET')));
                $this->assertTrue($permission->shouldPassThrough(Request::create($child, 'GET')));
                $this->assertSame($path === '*', $permission->shouldPassThrough(Request::create($root, 'POST')));
                if ($prefix !== '') {
                    $this->assertFalse($permission->shouldPassThrough(Request::create('/'.$prefix.'-other/users')));
                }
            }
        }
    }
}
