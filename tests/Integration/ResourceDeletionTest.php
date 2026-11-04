<?php

namespace Encore\Admin\Tests\Integration;

use Encore\Admin\Actions\Context;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Controllers\HandleController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\UploadTransaction;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Actions\Delete;
use Encore\Admin\Grid\Actions\ResetPassword;
use Illuminate\Auth\Access\Gate;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ResourceDeletionTest extends TestCase
{
    private $previous;
    private $previousResolver;
    private $previousDispatcher;
    private Application $app;
    private object $user;
    private string $directory;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required for resource integration tests.');
        }
        $this->previous = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->directory = sys_get_temp_dir().'/admin-deletion-'.bin2hex(random_bytes(8));
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'database' => ['default' => 'default', 'connections' => [
                'default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
                'records' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ]],
            'filesystems' => ['default' => 'review', 'disks' => ['review' => ['driver' => 'local', 'root' => $this->directory]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
            'admin' => ['route' => ['prefix' => 'admin'], 'check_route_permission' => true,
                'auth' => ['excepts' => []], 'upload' => ['disk' => 'review']],
        ]));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->app->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        $this->app->instance('session', new Store('integration', new ArraySessionHandler(120)));
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Routing\RoutingServiceProvider::class,
            \Illuminate\Database\DatabaseServiceProvider::class, \Illuminate\Filesystem\FilesystemServiceProvider::class,
            \Illuminate\Translation\TranslationServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
            \Illuminate\Cache\CacheServiceProvider::class, \Illuminate\Validation\ValidationServiceProvider::class,
            \Illuminate\Hashing\HashServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        (new \Illuminate\Database\DatabaseServiceProvider($this->app))->boot();
        $handler = $this->createMock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->method('render')->willReturnCallback(static function ($request, $exception) { throw $exception; });
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->singleton(Context::class);
        $this->app->singleton(UploadTransaction::class);
        $this->app['events']->listen(\Illuminate\Database\Events\TransactionRolledBack::class,
            fn ($event) => $this->app[UploadTransaction::class]->rolledBack($event));
        $connection = $this->app['db']->connection('records');
        $connection->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
        $connection->getSchemaBuilder()->create('deletion_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('attachment')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $connection->getSchemaBuilder()->create('operation_logs', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('path');
            $table->string('method');
            $table->string('ip');
            $table->text('input');
            $table->timestamps();
        });
        config(['admin.database.connection' => 'records', 'admin.database.operation_log_table' => 'operation_logs']);
        $this->user = new class {
            public array $methods = ['GET'];
            public bool $policy = true;
            public function getAuthIdentifier() { return 42; }
            public function isRole($role) { return false; }
            public function can($permission) { return false; }
            public function allPermissions() {
                return collect([new \Encore\Admin\Auth\Database\Permission([
                    'http_method' => $this->methods, 'http_path' => "/records*\n/hard-records*\n/default-records*\n/auth/logs*",
                ])]);
            }
        };
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn($this->user);
        Admin::swap($admin);
        $gate = new Gate($this->app, fn () => $this->user);
        $gate->define('update', fn ($user, $record) => $user->policy && $record instanceof DeletionRecord);
        $gate->define('forceDelete', fn ($user, $record) => $user->policy && in_array('FORCE', $user->methods, true));
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        $router = $this->app['router'];
        $router->aliasMiddleware('admin.permission', \Encore\Admin\Middleware\Permission::class);
        $router->aliasMiddleware('can', \Illuminate\Auth\Middleware\Authorize::class);
        $router->bind('record', fn ($id) => DeletionRecord::findOrFail($id));
        $router->get('admin/records', fn () => 'records')->middleware('admin.permission');
        $router->delete('admin/records/{id}', [DeletionController::class, 'destroy'])->middleware('admin.permission');
        $router->delete('admin/hard-records/{id}', [HardDeletionController::class, 'destroy'])->middleware('admin.permission');
        $router->delete('admin/default-records/{id}', [DefaultConnectionDeletionController::class, 'destroy'])->middleware('admin.permission');
        $router->get('admin/auth/logs', fn () => 'logs')->middleware('admin.permission');
        $router->delete('admin/auth/logs/{id}', [\Encore\Admin\Controllers\LogController::class, 'destroy'])->middleware('admin.permission');
        $router->put('admin/records/{record}', fn () => 'updated')->middleware(['admin.permission', 'can:update,record']);
        $router->post('admin/_handle_action_', [HandleController::class, 'handleAction']);
        $router->post('admin/_load_action_', [HandleController::class, 'loadAction']);
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        DeletionController::$hooks = [];
        DeletionController::$blocked = false;
        $this->request('GET', '/admin/records');
    }

    protected function tearDown(): void
    {
        if (!isset($this->app)) return;
        $this->app['files']->deleteDirectory($this->directory);
        DeletionRecord::clearBootedModels();
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        if ($this->previousDispatcher) Model::setEventDispatcher($this->previousDispatcher);
        else Model::unsetEventDispatcher();
        \Encore\Admin\Admin::$js = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function request(string $method, string $path, array $input = []): Request
    {
        $request = Request::create('http://localhost'.$path, $method, $input, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession($this->app['session']);
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        return $request;
    }

    private function dispatch(string $method, string $path, array $input = [])
    {
        return $this->app['router']->dispatch($this->request($method, $path, $input));
    }

    private function record(): DeletionRecord
    {
        Storage::put('document.txt', 'attachment');
        return DeletionRecord::create(['name' => 'record', 'attachment' => 'document.txt']);
    }

    private function action($class, Model $record, string $resource = '/admin/records')
    {
        $this->request('GET', '/admin/records');
        return (new $class())->setGrid((new Grid($record->newInstance()))->resource($resource))->setRow($record);
    }

    private function ticket($action): string
    {
        $html = $action->render();
        $this->assertStringContainsString('data-admin-action=', $html);
        preg_match('/data-admin-action="([^"]+)"/', $html, $match);
        return html_entity_decode($match[1], ENT_QUOTES);
    }

    private function forbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('A write operation was allowed.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_read_only_user_cannot_render_or_execute_builtin_writes(): void
    {
        $record = $this->record();
        $this->assertSame('', $this->action(Delete::class, $record)->render());
        $this->assertSame('', $this->action(ResetPassword::class, $record)->render());
        $this->forbidden(fn () => $this->dispatch('DELETE', '/admin/records/'.$record->id));
        $this->assertFalse($record->fresh()->trashed());

        $this->user->methods[] = 'DELETE';
        $token = $this->ticket($this->action(Delete::class, $record));
        $this->user->methods = ['GET'];
        $this->forbidden(fn () => $this->dispatch('DELETE', '/admin/records/'.$record->id,
            ['_action_token' => $token, '_key' => $record->id]));
        // A stale/forged generic endpoint still rechecks the target DELETE permission.
        $this->forbidden(fn () => $this->dispatch('POST', '/admin/_handle_action_',
            ['_action_token' => $token, '_key' => $record->id]));
    }

    public function test_signed_row_deletion_uses_controller_hooks_and_soft_delete_replays_keep_files(): void
    {
        $this->user->methods[] = 'DELETE';
        $record = $this->record();
        $action = $this->action(Delete::class, $record);
        $html = $action->render();
        $this->assertStringContainsString('data-admin-method="DELETE"', $html);
        $this->assertStringContainsString('data-admin-execute="/admin/records/'.$record->id.'"', $html);
        $response = $this->dispatch('DELETE', '/admin/records/'.$record->id,
            ['_action_token' => $this->ticket($action), '_key' => $record->id])->getData(true);
        $this->assertTrue($response['status']);
        $this->assertSame('refresh', $response['then']['action']);
        $this->assertSame(['deleting', 'deleted'], DeletionController::$hooks);
        $this->assertTrue(DeletionRecord::withTrashed()->findOrFail($record->id)->trashed());
        $this->assertTrue(Storage::exists('document.txt'));
        $this->assertTrue($this->dispatch('DELETE', '/admin/records/'.$record->id)->getData(true)['status']);
        $this->assertNotNull(DeletionRecord::withTrashed()->find($record->id));
        $this->assertTrue(Storage::exists('document.txt'));
    }

    public function test_signed_deletion_works_under_a_subdirectory_and_still_rejects_another_resource(): void
    {
        $this->user->methods[] = 'DELETE';
        foreach (['/myapp', '/myapp/index.php'] as $baseUrl) {
            $record = $this->record();
            $server = ['HTTP_ACCEPT' => 'application/json', 'SCRIPT_NAME' => '/myapp/index.php',
                'SCRIPT_FILENAME' => '/var/www/myapp/index.php', 'PHP_SELF' => '/myapp/index.php'];
            $request = Request::create('http://localhost'.$baseUrl.'/admin/records', 'GET', [], [], [], $server);
            $request->setLaravelSession($this->app['session']);
            $this->app->instance('request', $request);
            Facade::clearResolvedInstance('request');
            $action = (new Delete())->setGrid((new Grid(new DeletionRecord()))->resource($baseUrl.'/admin/records'))->setRow($record);
            $token = $this->ticket($action);
            $submit = function ($resource) use ($record, $baseUrl, $server, $token) {
                $request = Request::create('http://localhost'.$baseUrl.'/admin/'.$resource.'/'.$record->id, 'DELETE',
                    ['_action_token' => $token, '_key' => $record->id], [], [], $server);
                $request->setLaravelSession($this->app['session']);
                $this->app->instance('request', $request);
                Facade::clearResolvedInstance('request');
                return $this->app['router']->dispatch($request);
            };
            $this->forbidden(fn () => $submit('hard-records'));
            $response = $submit('records')->getData(true);
            $this->assertTrue($response['status']);
            $this->assertSame('refresh', $response['then']['action']);
            $this->assertTrue(DeletionRecord::withTrashed()->findOrFail($record->id)->trashed());
        }
    }

    public function test_resource_controller_protection_cannot_be_bypassed_by_a_signed_action(): void
    {
        $this->user->methods[] = 'DELETE';
        $record = $this->record();
        $token = $this->ticket($this->action(Delete::class, $record));
        DeletionController::$blocked = true;
        $this->forbidden(fn () => $this->dispatch('DELETE', '/admin/records/'.$record->id,
            ['_action_token' => $token, '_key' => $record->id]));
        $this->assertFalse($record->fresh()->trashed());
    }

    public function test_signed_row_delete_cannot_target_another_resource_or_record(): void
    {
        $this->user->methods[] = 'DELETE';
        $record = $this->record();
        $other = DeletionRecord::create(['name' => 'other']);
        $token = $this->ticket($this->action(Delete::class, $record));
        foreach (['/admin/records/'.$other->id, '/admin/hard-records/'.$record->id] as $path) {
            $this->forbidden(fn () => $this->dispatch('DELETE', $path,
                ['_action_token' => $token, '_key' => $record->id]));
        }
        $this->assertFalse($record->fresh()->trashed());
        $this->assertFalse($other->fresh()->trashed());
    }

    public function test_signed_row_deletion_resolves_an_implicit_default_connection(): void
    {
        config(['database.default' => 'records']);
        $this->user->methods[] = 'DELETE';
        $record = DefaultConnectionDeletionRecord::create(['name' => 'default connection'])->fresh();
        $this->assertNull((new DefaultConnectionDeletionRecord())->getConnectionName());
        $this->assertSame('records', $record->getConnectionName());
        $token = $this->ticket($this->action(Delete::class, $record, '/admin/default-records'));
        $response = $this->dispatch('DELETE', '/admin/default-records/'.$record->id,
            ['_action_token' => $token, '_key' => $record->id])->getData(true);
        $this->assertTrue($response['status']);
        $this->assertNull($record->fresh());
    }

    public function test_signed_row_deletion_still_rejects_a_different_actual_connection(): void
    {
        config(['database.default' => 'records']);
        $this->user->methods[] = 'DELETE';
        app('db')->connection('default')->getSchemaBuilder()->create('deletion_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        $record = (new DefaultConnectionDeletionRecord())->setConnection('default')->newQuery()->create(['name' => 'other database']);
        $token = $this->ticket($this->action(Delete::class, $record, '/admin/default-records'));
        $this->forbidden(fn () => $this->dispatch('DELETE', '/admin/default-records/'.$record->id,
            ['_action_token' => $token, '_key' => $record->id]));
        $this->assertNotNull($record->fresh());
    }

    public function test_builtin_operation_log_deletion_uses_the_signed_resource_flow_and_refreshes(): void
    {
        $this->user->methods[] = 'DELETE';
        $record = \Encore\Admin\Auth\Database\OperationLog::create([
            'user_id' => 42, 'path' => 'admin/records', 'method' => 'POST', 'ip' => '127.0.0.1', 'input' => '{}',
        ]);
        $this->request('GET', '/admin/auth/logs');
        $action = (new Delete())->setGrid((new Grid($record->newInstance()))->resource('/admin/auth/logs'))->setRow($record);
        $token = $this->ticket($action);
        $response = $this->dispatch('DELETE', '/admin/auth/logs/'.$record->id,
            ['_action_token' => $token, '_key' => $record->id])->getData(true);
        $this->assertTrue($response['status']);
        $this->assertSame('refresh', $response['then']['action']);
        $this->assertNull($record->fresh());
    }

    public function test_hard_delete_uses_model_connection_and_defers_file_cleanup_until_commit(): void
    {
        $this->user->methods[] = 'DELETE';
        Storage::put('document.txt', 'attachment');
        $record = HardDeletionRecord::create(['name' => 'hard', 'attachment' => 'document.txt']);
        $duringDelete = [];
        HardDeletionRecord::deleted(function ($model) use (&$duringDelete) {
            $duringDelete = [$model->getConnection()->transactionLevel(), Storage::exists('document.txt')];
        });
        $token = $this->ticket($this->action(Delete::class, $record, '/admin/hard-records'));
        $response = $this->dispatch('DELETE', '/admin/hard-records/'.$record->id,
            ['_action_token' => $token, '_key' => $record->id])->getData(true);
        $this->assertTrue($response['status']);
        $this->assertSame([1, true], $duringDelete);
        $this->assertNull($record->fresh());
        $this->assertFalse(Storage::exists('document.txt'));
        $this->assertSame(['deleting', 'deleted'], DeletionController::$hooks);
    }

    public function test_cancelled_batch_delete_rolls_back_records_and_keeps_all_attachments(): void
    {
        $this->user->methods[] = 'DELETE';
        Storage::put('first.txt', 'first');
        Storage::put('second.txt', 'second');
        $first = HardDeletionRecord::create(['name' => 'first', 'attachment' => 'first.txt']);
        $second = HardDeletionRecord::create(['name' => 'blocked', 'attachment' => 'second.txt']);
        HardDeletionRecord::deleting(static fn ($model) => $model->name !== 'blocked');
        $response = $this->dispatch('DELETE', '/admin/hard-records/'.$first->id.','.$second->id)->getData(true);
        $this->assertFalse($response['status']);
        $this->assertNotNull($first->fresh());
        $this->assertNotNull($second->fresh());
        $this->assertTrue(Storage::exists('first.txt'));
        $this->assertTrue(Storage::exists('second.txt'));
        $this->assertSame(['deleting'], DeletionController::$hooks);
    }

    public function test_permanent_deletion_requires_separate_policy_and_commits_file_cleanup(): void
    {
        $record = $this->record();
        $record->delete();
        $form = (new DeletionController())->form();
        try {
            $form->forceDestroy($record->id);
            $this->fail('Permanent deletion must require its own ability.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            $this->assertNotNull(DeletionRecord::withTrashed()->find($record->id));
            $this->assertTrue(Storage::exists('document.txt'));
            $this->assertSame([], DeletionController::$hooks);
        }
        $this->user->methods[] = 'FORCE';
        $this->assertTrue($form->forceDestroy($record->id)->getData(true)['status']);
        $this->assertNull(DeletionRecord::withTrashed()->find($record->id));
        $this->assertFalse(Storage::exists('document.txt'));
    }

    public function test_permanent_deletion_rejects_active_records_before_running_hooks(): void
    {
        $record = $this->record();
        $this->user->methods[] = 'FORCE';
        try {
            (new DeletionController())->form()->forceDestroy($record->id);
            $this->fail('Active records must first be soft deleted.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertFalse($record->fresh()->trashed());
        $this->assertTrue(Storage::exists('document.txt'));
        $this->assertSame([], DeletionController::$hooks);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_reset_password_rechecks_update_permission_and_record_policy(): void
    {
        // Password's validator registration is process-wide, like a booted host app.
        \Encore\Admin\Rules\Password::register();
        $record = $this->record();
        $this->user->methods[] = 'PUT';
        $action = $this->action(ResetPassword::class, $record);
        $token = $this->ticket($action);
        $this->user->policy = false;
        $this->assertSame('', $this->action(ResetPassword::class, $record)->render());
        $this->forbidden(fn () => $this->dispatch('POST', '/admin/_load_action_',
            ['_action_token' => $token, '_key' => $record->id]));
        $this->user->policy = true;
        $loaded = $this->dispatch('POST', '/admin/_load_action_',
            ['_action_token' => $token, '_key' => $record->id])->getData(true);
        $this->user->methods = ['GET'];
        $this->forbidden(fn () => $this->dispatch('POST', '/admin/_handle_action_',
            ['_action_token' => $loaded['ticket'], '_key' => $record->id,
                'password' => 'ReviewStrong123!', 'password_confirmation' => 'ReviewStrong123!']));
        $this->assertNull($record->fresh()->password);
        $this->user->methods[] = 'PUT';
        $response = $this->dispatch('POST', '/admin/_handle_action_',
            ['_action_token' => $loaded['ticket'], '_key' => $record->id,
                'password' => 'ReviewStrong123!', 'password_confirmation' => 'ReviewStrong123!'])->getData(true);
        $this->assertTrue($response['status']);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('ReviewStrong123!', $record->fresh()->password));
    }
}

class DeletionRecord extends Model
{
    use SoftDeletes;
    protected $connection = 'records';
    protected $table = 'deletion_records';
    protected $guarded = [];
}

class DeletionController extends AdminController
{
    public static array $hooks = [];
    public static bool $blocked = false;

    public function form()
    {
        return $this->recordForm(new DeletionRecord());
    }

    protected function recordForm(Model $model): Form
    {
        $form = new Form($model);
        $form->file('attachment');
        $form->deleting(function () { self::$hooks[] = 'deleting'; });
        $form->deleted(function () { self::$hooks[] = 'deleted'; });
        return $form;
    }

    public function destroy($id)
    {
        abort_if(self::$blocked, 403);
        return parent::destroy($id);
    }
}

class HardDeletionRecord extends Model
{
    protected $connection = 'records';
    protected $table = 'deletion_records';
    protected $guarded = [];
}

class HardDeletionController extends DeletionController
{
    public function form()
    {
        return $this->recordForm(new HardDeletionRecord());
    }
}

class DefaultConnectionDeletionRecord extends Model
{
    protected $table = 'deletion_records';
    protected $guarded = [];
}

class DefaultConnectionDeletionController extends DeletionController
{
    public function form()
    {
        return $this->recordForm(new DefaultConnectionDeletionRecord());
    }
}
