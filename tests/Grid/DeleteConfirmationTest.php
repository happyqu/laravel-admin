<?php

namespace Encore\Admin\Tests\Grid;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\Context;
use Encore\Admin\Actions\ContextToken;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Actions\Delete;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class DeleteConfirmationTest extends TestCase
{
    private $previousContainer;
    private Application $app;
    private Store $session;
    private ConfirmationRow $row;
    private object $user;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'admin' => ['route' => ['prefix' => 'admin']],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]));
        $this->app->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->session = new Store('actions', new ArraySessionHandler(120));
        $this->app->instance('session', $this->session);
        $this->app->instance('request', Request::create('http://localhost/admin/users'));
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $this->app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $this->app->register(\Illuminate\Validation\ValidationServiceProvider::class);
        $this->app->register(\Illuminate\View\ViewServiceProvider::class);
        $this->app->register(\Illuminate\Cache\CacheServiceProvider::class);
        $this->app['router']->get('admin/users', fn () => '')->middleware('admin.permission:check,view-users');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $user = $this->user = new class {
            public int $id = 42;
            public bool $allowed = true;
            public function getAuthIdentifier() { return $this->id; }
            public function isRole($role) { return false; }
            public function can($permission) { return $this->allowed; }
        };
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn($user);
        Admin::swap($admin);
        $this->row = new ConfirmationRow();
        $this->row->setRawAttributes(['id' => 2, 'updated_at' => '2026-01-01 12:00:00'], true);
        $context = new class($this->row) extends Context {
            public function __construct(private Model $row) {}
            public function query(array $reference) {
                return new class($this->row) {
                    public function __construct(private Model $row) {}
                    public function whereKey($keys) { return $this; }
                    public function get() { return new Collection([$this->row]); }
                };
            }
        };
        $this->app->instance(Context::class, $context);
    }

    protected function tearDown(): void
    {
        \Encore\Admin\Admin::$js = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    private function render(): array
    {
        $html = (new ConfirmationDelete())->setRow($this->row)->render();
        preg_match('/data-admin-action="([^"]+)"/', $html, $match);
        return [$html, $match[1]];
    }

    private function assertActionError(callable $callback, string $code, int $status = 409): void
    {
        try {
            $callback();
            $this->fail('Expected action error: '.$code);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame($status, $exception->getResponse()->getStatusCode());
            $this->assertSame($code, $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_row_confirmation_uses_a_small_signed_ticket_and_executes_without_a_load(): void
    {
        [$html, $token] = $this->render();
        $this->assertStringContainsString('data-admin-dialog=', $html);
        $this->assertStringNotContainsString('data-admin-prepare=', $html);
        $this->assertStringStartsWith('a3.', $token);
        $this->assertLessThan(1200, strlen($token));
        $ticket = ContextToken::decode($token);
        $this->assertSame(ConfirmationDelete::class, $ticket['action']);
        $this->assertSame(['2'], $ticket['keys']);
        $this->assertSame('2026-01-01 12:00:00', $ticket['versions'][2]);
        foreach (['expires', 'phase', 'state', 'identity', 'definition'] as $key) $this->assertArrayNotHasKey($key, $ticket);
        [$action, $model] = $this->app[Context::class]->resolve(Request::create('/execute', 'POST', [
            '_action_token' => $token, '_key' => 2, '_input' => true,
        ]));
        $this->assertInstanceOf(ConfirmationDelete::class, $action);
        $this->assertSame($this->row, $model);
    }

    public function test_display_properties_do_not_enter_the_ticket(): void
    {
        $action = (new ConfirmationCustomAction())->setRow($this->row);
        $context = $this->app[Context::class];
        $first = $context->register($action);
        $action->name = str_repeat('display only', 10000);
        $this->assertSame($first, $context->register($action));
        $this->assertArrayNotHasKey('options', ContextToken::decode($first));
    }

    public function test_record_changes_reject_a_signed_row_action(): void
    {
        [, $token] = $this->render();
        $this->row->setRawAttributes(['id' => 2, 'updated_at' => '2026-01-01 12:01:00'], true);
        $this->assertActionError(fn () => $this->app[Context::class]->resolve(Request::create('/execute', 'POST', [
            '_action_token' => $token, '_key' => 2,
        ])), 'action_record_changed');
    }

    public function test_batch_confirmations_submit_only_selected_records_in_the_signed_scope(): void
    {
        $html = (new ConfirmationBatchAction())->render();
        $this->assertStringNotContainsString('data-admin-prepare=', $html);
        preg_match('/data-admin-action="([^"]+)"/', $html, $match);
        [, $models, , $keys] = $this->app[Context::class]->resolve(Request::create('/execute', 'POST', [
            '_action_token' => $match[1], '_key' => [2],
        ]));
        $this->assertInstanceOf(Collection::class, $models);
        $this->assertSame([2], $keys);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->app[Context::class]->resolve(Request::create('/execute', 'POST', ['_action_token' => $match[1], '_key' => [99]]));
    }

    public function test_toolbar_placeholder_is_ignored_but_a_bound_model_is_restored(): void
    {
        $context = $this->app[Context::class];
        $token = $context->register(new ConfirmationToolbarAction());
        [, $model, , $keys] = $context->resolve(Request::create('/load', 'POST', ['_action_token' => $token, '_key' => '0']), true);
        $this->assertNull($model);
        $this->assertSame([], $keys);
        $action = new ConfirmationBoundToolbar();
        $action->bind($this->row);
        $token = $context->register($action);
        [$resolved, $model] = $context->resolve(Request::create('/load', 'POST', ['_action_token' => $token, '_key' => 2]), true);
        $this->assertSame($this->row, $model);
        $this->assertSame($this->row, $resolved->contextModel());
    }

    public function test_definitions_survive_more_than_512_actions_without_a_session_map(): void
    {
        [, $token] = $this->render();
        $context = $this->app[Context::class];
        for ($i = 10; $i < 540; $i++) {
            $row = clone $this->row;
            $row->id = $i;
            $context->register((new ConfirmationDelete())->setRow($row));
        }
        $this->assertFalse($this->session->has('admin.action_contexts'));
        $this->session->forget('admin.action_contexts');
        [, $model] = $context->resolve(Request::create('/execute', 'POST', ['_action_token' => $token, '_key' => 2]));
        $this->assertSame(2, $model->getKey());
    }

    public function test_invalid_and_tampered_tickets_are_business_errors_and_not_419(): void
    {
        [, $token] = $this->render();
        foreach ([str_repeat('a', 48), 'a2.old-ticket', ['invalid']] as $invalid) {
            $this->assertActionError(fn () => $this->app[Context::class]->resolve(Request::create('/load', 'POST', [
                '_action_token' => $invalid,
            ]), true), 'action_context_invalid');
        }
        $parts = explode('.', $token);
        $payload = ContextToken::decode($token);
        $payload['parameters']['_cb'] = 'unauthorizedMethod';
        $parts[1] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $this->assertActionError(fn () => ContextToken::decode(implode('.', $parts)), 'action_context_invalid', 403);
    }

    public function test_login_user_and_admin_scope_changes_invalidate_tickets(): void
    {
        [, $token] = $this->render();
        $this->user->id = 43;
        $this->assertActionError(fn () => ContextToken::decode($token), 'action_context_invalid', 403);
        $this->user->id = 42;
        config(['admin.route.prefix' => 'other']);
        $this->assertActionError(fn () => ContextToken::decode($token), 'action_context_invalid', 403);
        config(['admin.route.prefix' => 'admin']);
        $this->session->regenerateToken();
        $this->assertActionError(fn () => ContextToken::decode($token), 'action_context_invalid', 403);
    }

    public function test_execution_rechecks_current_source_and_record_permissions(): void
    {
        [, $token] = $this->render();
        foreach (['source', 'record'] as $permission) {
            $this->user->allowed = $permission !== 'source';
            $this->row->actionAllowed = $permission !== 'record';
            try {
                $this->app[Context::class]->resolve(Request::create('/execute', 'POST', ['_action_token' => $token, '_key' => 2]));
                $this->fail('Permission revocation was ignored.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_signed_callbacks_override_client_controls_and_row_is_available_to_form_builders(): void
    {
        $context = $this->app[Context::class];
        $action = (new ConfirmationImportRow())->setRow($this->row);
        $action->headers(['id', 'code']);
        $token = $context->register($action);
        $ticket = ContextToken::decode($token);
        $this->assertSame(['headerList' => ['id', 'code']], $ticket['options']);
        [$resolved] = $context->resolve(Request::create('/load', 'POST', [
            '_action_token' => $token, '_key' => 2, '_class' => 'Forged', '_cb' => 'evil',
        ]), true);
        $this->assertSame('AllowedController', request('_class'));
        $this->assertSame('import', request('_cb'));
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $payload = $resolved->loadInteraction();
        $this->assertStringContainsString('id,code', $payload['html']);
        $this->assertStringContainsString('value="2"', $payload['html']);
    }

    public function test_form_load_binds_selection_and_versions_without_expiry_or_renewal_metadata(): void
    {
        $context = $this->app[Context::class];
        $token = $context->register((new ConfirmationImportRow())->setRow($this->row));
        $this->assertActionError(fn () => $context->resolve(Request::create('/execute', 'POST', [
            '_action_token' => $token, '_key' => 2,
        ])), 'action_open_required');
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $controller = new \Encore\Admin\Controllers\HandleController();
        $payload = $controller->loadAction(Request::create('/load', 'POST', ['_action_token' => $token, '_key' => 2]))->getData(true);
        $this->assertArrayNotHasKey('expires_in', $payload);
        $loaded = ContextToken::decode($payload['ticket']);
        $this->assertTrue($loaded['loaded']);
        $this->assertArrayNotHasKey('expires', $loaded);
        [, $model] = $context->resolve(Request::create('/execute', 'POST', ['_action_token' => $payload['ticket'], '_key' => 99]));
        $this->assertSame(2, $model->getKey());
        $this->row->setRawAttributes(['id' => 2, 'updated_at' => 'changed'], true);
        $this->assertActionError(fn () => $context->resolve(Request::create('/execute', 'POST', [
            '_action_token' => $payload['ticket'],
        ])), 'action_record_changed');
    }

    public function test_existing_completed_execution_marker_still_blocks_repeats(): void
    {
        [, $token] = $this->render();
        $context = $this->app[Context::class];
        $calls = 0;
        $run = function () use (&$calls) { $calls++; return response()->json(['status' => true]); };
        $context->execute(Request::create('/execute', 'POST', ['_action_token' => $token]), $run);
        try {
            $context->execute(Request::create('/execute', 'POST', ['_action_token' => $token]), $run);
            $this->fail('Duplicate execution passed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, $calls);
    }

    public function test_completed_tickets_cannot_be_replayed_after_two_hours(): void
    {
        [, $token] = $this->render();
        $context = $this->app[Context::class];
        $request = Request::create('/execute', 'POST', ['_action_token' => $token]);
        $calls = 0;
        $run = function () use (&$calls) { $calls++; return response()->json(['status' => true]); };
        $context->execute($request, $run);
        try {
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addHours(3));
            try {
                $context->execute($request, $run);
                $this->fail('Completed ticket was replayed.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame(1, $calls);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_business_errors_allow_a_retry_without_consuming_the_form_ticket(): void
    {
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        RetryableFormAction::$reject = true;
        $context = $this->app[Context::class];
        $token = $context->register(new RetryableFormAction());
        $controller = new \Encore\Admin\Controllers\HandleController();
        $loaded = $controller->loadAction(Request::create('/load', 'POST', ['_action_token' => $token]))->getData(true);
        $request = Request::create('/execute', 'POST', ['_action_token' => $loaded['ticket'], 'name' => 'Alice']);
        $response = $controller->handleAction($request)->getData(true);
        $this->assertFalse($response['status']);
        $this->assertSame('error', $response['toastr']['type']);
        RetryableFormAction::$reject = false;
        $this->assertTrue($controller->handleAction($request)->getData(true)['status']);
        try {
            $controller->handleAction($request);
            $this->fail('Successful retry was executed twice.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_form_handlers_only_receive_declared_fields_and_signed_parameters(): void
    {
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        RetryableFormAction::$reject = true;
        $context = $this->app[Context::class];
        $token = $context->register(new RetryableFormAction());
        $controller = new \Encore\Admin\Controllers\HandleController();
        $loaded = $controller->loadAction(Request::create('/load', 'POST', ['_action_token' => $token]))->getData(true);
        $file = new \Illuminate\Http\UploadedFile(__FILE__, 'extra.php', null, null, true);
        $request = Request::create('/execute', 'POST', [
            '_action_token' => $loaded['ticket'], 'name' => 'Alice', 'is_admin' => 1, '_cb' => 'forged',
        ], [], ['unexpected' => $file, '_cb' => $file]);
        $controller->handleAction($request);
        $this->assertSame('Alice', RetryableFormAction::$input['name']);
        $this->assertSame('trusted-callback', RetryableFormAction::$input['_cb']);
        $this->assertSame('signed-option', RetryableFormAction::$input['option']);
        $this->assertSame($loaded['ticket'], RetryableFormAction::$input['_action_token']);
        $this->assertArrayNotHasKey('is_admin', RetryableFormAction::$input);
        $this->assertArrayNotHasKey('unexpected', RetryableFormAction::$input);
    }

    public function test_a_cancelled_model_deletion_returns_an_error(): void
    {
        $database = $this->createMock(\Illuminate\Database\DatabaseManager::class);
        $database->method('__call')->with('transaction', $this->anything())
            ->willReturnCallback(static fn ($method, $arguments) => $arguments[0]());
        \Illuminate\Support\Facades\DB::swap($database);
        $model = new class extends Model { public function delete() { return false; } };
        $response = (new ConfirmationDelete())->handle($model)->send()->getData(true);
        $this->assertFalse($response['status']);
        $this->assertSame('error', $response['swal']['type']);
        $this->assertSame([], $response['then']);
    }

    public function test_explicit_options_and_total_payload_have_size_limits(): void
    {
        $action = (new ConfirmationImportRow())->setRow($this->row);
        $action->headers([str_repeat('x', 4097)]);
        try {
            $this->app[Context::class]->register($action);
            $this->fail('Oversized explicit options were accepted.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('4 KiB', $exception->getMessage());
        }
        $this->expectException(\LengthException::class);
        ContextToken::encode(['data' => str_repeat('x', ContextToken::MAX_PAYLOAD_BYTES)]);
    }
}

class ConfirmationDelete extends Delete
{
    public function parameters() { return []; }
}

class ConfirmationRow extends Model {
    public bool $actionAllowed = true;
    public function actionAuthorize($user, $action) { return $this->actionAllowed; }
}

class ConfirmationToolbarAction extends Action
{
    public function form()
    {
        $this->text('name', 'Name');
    }
}

class ConfirmationCustomAction extends \Encore\Admin\Actions\RowAction
{
    public function parameters() { return []; }
    public function dialog() { $this->confirm('Confirm row '.$this->getRow()->getKey()); }
}

class ConfirmationBatchAction extends \Encore\Admin\Actions\BatchAction
{
    public function parameters() { return []; }
    protected function getModelClass() { return ConfirmationRow::class; }
    public function contextKeys(): array { return [2, 3]; }
    public function dialog() { $this->confirm('Confirm batch'); }
    public function render() { $this->addScript(); return $this->formatAttributes(); }
}

class ConfirmationBoundToolbar extends ConfirmationToolbarAction
{
    protected $model;
    public function bind(Model $model) { $this->model = $model; }
}

class ConfirmationImportRow extends \Encore\Admin\Actions\RowAction
{
    protected $contextProperties = ['headerList'];
    protected $headerList = [];
    public function headers(array $headers) { $this->headerList = $headers; }
    public function parameters() { return ['_model' => ConfirmationRow::class, '_class' => 'AllowedController', '_cb' => 'import']; }
    public function form() {
        $this->text('id', 'ID')->value($this->getRow()->getKey())->readonly();
        $this->text('sample', 'Sample')->value(implode(',', $this->headerList))->readonly();
        $this->text('name', 'Name')->rules('required');
    }
}

class RetryableFormAction extends Action
{
    public static bool $reject = true;
    public static array $input = [];
    public function parameters() { return ['_cb' => 'trusted-callback', 'option' => 'signed-option']; }
    public function form() { $this->text('name')->rules('required'); }
    public function handle(Request $request)
    {
        static::$input = $request->all();
        return static::$reject ? $this->response()->error('Rejected') : $this->response()->success('Saved');
    }
}
