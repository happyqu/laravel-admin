<?php

namespace Encore\Admin\Tests\Grid;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\Context;
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

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
            'admin' => ['route' => ['prefix' => 'admin']],
        ]));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->session = new Store('actions', new ArraySessionHandler(120));
        $this->app->instance('session', $this->session);
        $this->app->instance('request', Request::create('http://localhost/admin/users'));
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $this->app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $this->app['router']->get('admin/users', fn () => '');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $user = new class {
            public function getAuthIdentifier() { return 42; }
            public function isRole($role) { return $role === 'administrator'; }
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
        preg_match('/data-admin-action="([a-f0-9]{48})"/', $html, $match);
        return [$html, $match[1]];
    }

    public function test_rendered_delete_defers_its_execution_ticket_until_confirmation(): void
    {
        [$html, $token] = $this->render();
        preg_match('/data-admin-dialog="([^"]+)"/', $html, $match);
        $dialog = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($dialog['showCancelButton']);
        $this->assertTrue($dialog['showLoaderOnConfirm']);
        // The button carries the definition ticket only; the page must not
        // embed a long-lived execution ticket that can expire or be consumed.
        $this->assertStringContainsString('data-admin-prepare="1"', $html);
        $ticket = $this->session->get('admin.action_contexts.'.$token);
        $this->assertSame('definition', $ticket['phase']);
        $this->assertArrayHasKey('2', $ticket['keys']);
        // Confirming loads the definition and issues a fresh execution ticket
        // bound to the record rendered with the button (HandleController::loadAction).
        $context = $this->app[Context::class];
        [$action, $model] = $context->resolve(
            Request::create('/admin/_load_action_', 'GET', ['_action_token' => $token, '_key' => [2]]),
            true
        );
        $this->assertInstanceOf(ConfirmationDelete::class, $action);
        $this->assertSame(2, $model->getKey());
        $execution = $context->executionTicket($token, [2], $model);
        $executeTicket = $this->session->get('admin.action_contexts.'.$execution);
        $this->assertSame('execute', $executeTicket['phase']);
        $this->assertSame([2], $executeTicket['selected']);
        $this->assertSame('2026-01-01 12:00:00', $executeTicket['versions'][ConfirmationRow::class.':2']);
        // Client-supplied keys cannot replace the record bound at load time.
        [, $resolved] = $this->app[Context::class]->resolve(Request::create('/admin/_handle_action_', 'POST', [
            '_action_token' => $execution, '_key' => [99],
        ]));
        $this->assertSame(2, $resolved->getKey());
    }

    public function test_record_changes_after_confirmation_reject_the_execution(): void
    {
        [$html, $token] = $this->render();
        $context = $this->app[Context::class];
        [, $model] = $context->resolve(
            Request::create('/admin/_load_action_', 'GET', ['_action_token' => $token, '_key' => [2]]),
            true
        );
        $execution = $context->executionTicket($token, [2], $model);
        $this->row->setRawAttributes(['id' => 2, 'updated_at' => '2026-01-01 12:01:00'], true);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('The record changed. Reopen the action.');
        $context->resolve(Request::create('/admin/_handle_action_', 'POST', ['_action_token' => $execution]));
    }

    public function test_custom_row_dialogs_also_defer_their_execution_ticket(): void
    {
        $html = (new ConfirmationCustomAction())->setRow($this->row)->render();
        $this->assertStringContainsString('data-admin-dialog=', $html);
        $this->assertStringContainsString('data-admin-prepare="1"', $html);
        preg_match('/data-admin-action="([a-f0-9]{48})"/', $html, $match);
        $this->assertSame('definition', $this->session->get('admin.action_contexts.'.$match[1].'.phase'));
    }

    public function test_batch_dialogs_render_confirmation_but_defer_binding_selection(): void
    {
        $html = (new ConfirmationBatchAction())->render();
        $this->assertStringContainsString('data-admin-dialog=', $html);
        $this->assertStringContainsString('data-admin-prepare="1"', $html);
        preg_match('/data-admin-action="([a-f0-9]{48})"/', $html, $match);
        $this->assertSame('definition', $this->session->get('admin.action_contexts.'.$match[1].'.phase'));
        $this->expectExceptionMessage('Open the action first.');
        $this->app[Context::class]->resolve(Request::create('/admin/_handle_action_', 'POST', [
            '_action_token' => $match[1], '_key' => [2],
        ]));
    }

    public function test_toolbar_action_placeholder_key_is_not_treated_as_a_record(): void
    {
        $token = $this->app[Context::class]->register(new ConfirmationToolbarAction());

        [$action, $model, , $keys] = $this->app[Context::class]->resolve(
            Request::create('/admin/_load_action_', 'GET', ['_action_token' => $token, '_key' => ['0']]),
            true
        );

        $this->assertInstanceOf(ConfirmationToolbarAction::class, $action);
        $this->assertNull($model);
        $this->assertSame([], $keys);
    }
}

class ConfirmationDelete extends Delete
{
    public function parameters() { return []; }
}

class ConfirmationRow extends Model {}

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
