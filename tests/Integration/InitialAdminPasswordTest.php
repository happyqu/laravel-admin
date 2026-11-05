<?php

namespace Encore\Admin\Tests\Integration;

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Auth\Database\OperationLog;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Facades\Admin;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;

class InitialAdminPasswordTest extends TestCase
{
    private $previous;
    private $resolver;
    private $dispatcher;
    private Application $app;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required for seeder integration tests.');
        }
        $this->previous = Container::getInstance();
        $this->resolver = Model::getConnectionResolver();
        $this->dispatcher = Model::getEventDispatcher();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'database' => ['default' => 'sqlite', 'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ]],
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        ]));
        $this->app['config']->set('admin', require dirname(__DIR__, 2).'/config/admin.php');
        $this->app['config']->set('admin.database.connection', 'sqlite');
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Database\DatabaseServiceProvider::class,
            \Illuminate\Hashing\HashServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        (new \Illuminate\Database\DatabaseServiceProvider($this->app))->boot();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        require_once dirname(__DIR__, 2).'/database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
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

    public function test_initialization_hashes_the_supplied_password_and_never_resets_an_existing_account(): void
    {
        $seeder = new AdminTablesSeeder();
        $seeder->run('InitialStrong123!');
        $admin = Administrator::firstOrFail();
        $this->assertTrue(Hash::check('InitialStrong123!', $admin->password));
        $this->assertFalse(Hash::check('admin', $admin->password));
        $this->assertSame('administrator', $admin->roles()->firstOrFail()->slug);
        $admin->name = 'Preserve me';
        $admin->save();
        Role::create(['name' => 'Business role', 'slug' => 'business']);
        $seeder->run('OtherStrong123!');
        $this->assertSame(1, Administrator::count());
        $this->assertSame('Preserve me', $admin->fresh()->name);
        $this->assertTrue(Hash::check('InitialStrong123!', $admin->fresh()->password));
        $this->assertTrue(Role::where('slug', 'business')->exists());
    }

    public function test_initialization_skips_when_any_admin_table_already_holds_data(): void
    {
        // A host may customize roles, permissions or menus before (or without) an administrator.
        // Running the seeder again must not truncate them.
        Role::create(['name' => 'Preserve role', 'slug' => 'preserve']);
        Permission::insert(['name' => 'Preserve permission', 'slug' => 'preserve', 'http_method' => '', 'http_path' => '/preserved']);
        Menu::insert(['parent_id' => 0, 'order' => 1, 'title' => 'Preserved menu', 'icon' => 'fa-star', 'uri' => 'preserved']);
        (new AdminTablesSeeder())->run('InitialStrong123!');
        $this->assertSame(0, Administrator::count());
        $this->assertTrue(Role::where('slug', 'preserve')->exists());
        $this->assertTrue(Permission::where('slug', 'preserve')->exists());
        $this->assertTrue(Menu::where('title', 'Preserved menu')->exists());
    }

    public function test_invalid_initial_password_does_not_truncate_fresh_lookup_tables(): void
    {
        try {
            (new AdminTablesSeeder())->run('weak');
            $this->fail('Weak initialization password was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(0, Administrator::count());
            $this->assertSame(0, Role::count());
        }
    }

    public function test_audit_result_is_persisted_in_the_existing_log_schema(): void
    {
        (new AdminTablesSeeder())->run('InitialStrong123!');
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn(Administrator::firstOrFail());
        Admin::swap($admin);
        config(['admin.operation_log.enable' => true, 'admin.operation_log.except' => []]);
        $request = \Illuminate\Http\Request::create('/admin/orders/7', 'PUT', ['title' => 'Changed', 'password' => 'not-for-the-log']);
        (new \Encore\Admin\Middleware\LogOperation())->handle($request, fn () => new \Illuminate\Http\JsonResponse(['status' => true]));
        $log = OperationLog::firstOrFail();
        $data = json_decode($log->input, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Changed', $data['title']);
        $this->assertSame('[REDACTED]', $data['password']);
        $this->assertSame('succeeded', $data['_admin_audit']['outcome']);
        $this->assertSame(200, $data['_admin_audit']['http_status']);
    }
}
