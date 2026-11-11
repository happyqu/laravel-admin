<?php

namespace Encore\Admin\Tests\Integration;

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Actions\Delete;
use Illuminate\Auth\Access\Gate;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class ResourcePermissionQueriesTest extends TestCase
{
    private $previous;
    private $resolver;
    private $dispatcher;
    private Application $app;
    private Administrator $operator;
    private Role $role;
    private Permission $permission;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for permission query regression tests.');
        }
        $this->previous = Container::getInstance();
        $this->resolver = Model::getConnectionResolver();
        $this->dispatcher = Model::getEventDispatcher();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'url' => 'http://localhost'],
            'database' => ['default' => 'sqlite', 'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ]],
        ]));
        $this->app['config']->set('admin', require dirname(__DIR__, 2).'/config/admin.php');
        $this->app['config']->set('admin.route.prefix', 'admin');
        $this->app['config']->set('admin.database.connection', 'sqlite');
        $this->app['config']->set('admin.check_route_permission', true);
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->request();
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Database\DatabaseServiceProvider::class,
            \Illuminate\Routing\RoutingServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        (new \Illuminate\Database\DatabaseServiceProvider($this->app))->boot();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        require_once dirname(__DIR__, 2).'/database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->operator = Administrator::create(['username' => 'operator', 'password' => 'unused', 'name' => 'Operator']);
        $this->role = Role::create(['name' => 'Operator', 'slug' => 'operator']);
        $this->permission = Permission::create([
            'name' => 'Delete records', 'slug' => 'records.delete', 'http_method' => ['DELETE'], 'http_path' => '/query-records/*',
        ]);
        $this->role->permissions()->attach($this->permission);
        $this->operator->roles()->attach($this->role);
        $this->operator->load('roles', 'permissions');
        $this->asUser($this->operator);
        $this->app['router']->delete('admin/query-records/{record}', fn () => null)->middleware('admin.permission');
    }

    protected function tearDown(): void
    {
        if (!isset($this->app)) return;
        Model::clearBootedModels();
        if ($this->resolver) Model::setConnectionResolver($this->resolver);
        else Model::unsetConnectionResolver();
        if ($this->dispatcher) Model::setEventDispatcher($this->dispatcher);
        else Model::unsetEventDispatcher();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function request(): void
    {
        $this->app->instance('request', Request::create('http://localhost/admin/query-records', 'GET'));
        Facade::clearResolvedInstance('request');
    }

    private function asUser(Administrator $user): void
    {
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn($user);
        Admin::swap($admin);
    }

    private function action(Administrator $row): Delete
    {
        return (new Delete())->setGrid((new Grid(new Administrator()))->resource('/admin/query-records'))->setRow($row);
    }

    public function test_rendering_twenty_rows_loads_role_permissions_only_once(): void
    {
        $rows = [];
        for ($index = 0; $index < 20; $index++) {
            $rows[] = Administrator::create(['username' => 'record-'.$index, 'password' => 'unused', 'name' => 'Record']);
        }
        $connection = $this->app['db']->connection('sqlite');
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        foreach ($rows as $row) {
            $this->assertTrue($this->action($row)->passesAuthorizationForRendering());
        }
        $this->assertCount(2, $connection->getQueryLog(), 'Roles and role permissions should each be queried once per rendered request.');
    }

    public function test_deleting_permissions_removes_both_direct_and_role_assignments(): void
    {
        $this->operator->permissions()->attach($this->permission);
        $other = Permission::create(['name' => 'Other', 'slug' => 'other', 'http_path' => '/other']);
        $this->operator->permissions()->attach($other);
        $this->permission->delete();

        $this->assertFalse($this->app['db']->table(config('admin.database.user_permissions_table'))->where('permission_id', $this->permission->id)->exists());
        $this->assertFalse($this->app['db']->table(config('admin.database.role_permissions_table'))->where('permission_id', $this->permission->id)->exists());
        $this->assertSame([$other->id], $this->operator->fresh()->permissions->modelKeys());
        $this->assertTrue($this->role->exists());
    }

    public function test_deleting_roles_removes_user_permission_and_menu_assignments(): void
    {
        $menu = \Encore\Admin\Auth\Database\Menu::create(['title' => 'Records', 'icon' => 'fa-list', 'uri' => 'query-records']);
        $menu->roles()->attach($this->role);
        $other = Role::create(['name' => 'Other', 'slug' => 'other']);
        $menu->roles()->attach($other);
        $this->role->delete();

        foreach (['role_users_table', 'role_permissions_table', 'role_menu_table'] as $table) {
            $this->assertFalse($this->app['db']->table(config('admin.database.'.$table))->where('role_id', $this->role->id)->exists());
        }
        $this->assertSame([$other->id], $menu->fresh()->roles->modelKeys());
        $this->assertTrue($this->permission->exists());
        $this->assertTrue($this->operator->exists());
    }

    public function test_render_snapshot_does_not_cross_users_requests_or_execution_checks(): void
    {
        $action = $this->action($this->operator);
        $this->assertTrue($action->passesAuthorizationForRendering());
        $other = Administrator::create(['username' => 'other', 'password' => 'unused', 'name' => 'Other']);
        $this->asUser($other);
        $this->assertFalse($action->passesAuthorizationForRendering());
        $this->asUser($this->operator);
        $this->role->permissions()->detach($this->permission);
        $this->assertFalse($action->passesAuthorization($this->operator), 'Execution must recheck permissions even if this request rendered the button earlier.');
        $this->request();
        $this->assertFalse($action->passesAuthorizationForRendering(), 'A new request must not reuse the previous permission snapshot.');
        $this->role->permissions()->attach($this->permission);
        $this->request();
        $this->assertTrue($action->passesAuthorizationForRendering());
    }

    public function test_record_policies_are_evaluated_for_each_row_despite_permission_reuse(): void
    {
        $other = Administrator::create(['username' => 'other', 'password' => 'unused', 'name' => 'Other']);
        $checked = [];
        $allow = true;
        $gate = new Gate($this->app, fn () => $this->operator);
        $gate->define('delete', function ($user, $record) use (&$checked, &$allow) {
            $checked[] = $record->getKey();
            return $allow && $record->getKey() === $user->getKey();
        });
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        $this->app['router']->aliasMiddleware('can', \Illuminate\Auth\Middleware\Authorize::class);
        $this->app['router']->bind('record', fn ($id) => Administrator::findOrFail($id));
        $route = $this->app['router']->getRoutes()->match(Request::create('/admin/query-records/'.$this->operator->id, 'DELETE'));
        $route->middleware('can:delete,record');
        $this->assertTrue($this->action($this->operator)->passesAuthorizationForRendering());
        $this->assertFalse($this->action($other)->passesAuthorizationForRendering());
        $allow = false;
        $this->assertFalse($this->action($this->operator)->passesAuthorizationForRendering());
        $this->assertSame([$this->operator->id, $other->id, $this->operator->id], $checked);
    }
}
