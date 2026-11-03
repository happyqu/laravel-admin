<?php

namespace Encore\Admin\Tests\Actions;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\ButtonAction;
use Encore\Admin\Actions\CallbackAction;
use Encore\Admin\Actions\CallbackBatchAction;
use Encore\Admin\Actions\CallbackRowAction;
use Encore\Admin\Actions\Response;
use Encore\Admin\Actions\RowAction;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class ConfigurableActionTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $container->instance('request', Request::create('/'));
        $container->instance('translator', new Translator(new ArrayLoader(), 'en'));
        Callback_Handler::$result = null;
        Callback_Handler::$received = null;
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    public function test_callback_configuration_survives_restore_and_ignores_request_dispatch_parameters(): void
    {
        $original = (new CallbackRowAction())->setCallback(Callback_Handler::class, 'run')->setName('Run');
        $rebuilt = new CallbackRowAction();
        $rebuilt->restoreContext($original->contextState());
        $model = new class extends Model {};
        $request = Request::create('/', 'POST', ['_class' => 'Invalid', '_cb' => 'invalid']);
        $this->assertTrue($rebuilt->handle($model, $request)->status);
        $this->assertSame([$model, $request], Callback_Handler::$received);
        $this->assertSame('Run', $rebuilt->name());
    }

    public function test_callbacks_return_response_unchanged_and_convert_legacy_results(): void
    {
        $action = (new CallbackRowAction())->setCallback(Callback_Handler::class, 'run');
        Callback_Handler::$result = (new Response())->toastr()->error('Custom')->redirect('/orders');
        $this->assertSame(Callback_Handler::$result, $action->handle(null, request()));
        foreach ([false, 'Business error'] as $result) {
            Callback_Handler::$result = $result;
            $this->assertFalse($action->handle(null, request())->status);
        }
        Callback_Handler::$result = null;
        $this->assertTrue($action->handle(null, request())->status);
    }

    public function test_missing_callback_is_not_reported_as_success(): void
    {
        $this->expectException(\LogicException::class);
        (new CallbackRowAction())->handle(null, request());
    }

    public function test_non_static_callback_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        (new CallbackRowAction())->setCallback(Callback_Handler::class, 'instanceMethod')->handle(null, request());
    }

    public function test_generic_action_uses_resolved_model_and_batch_receives_entire_collection(): void
    {
        $model = new class extends Model {};
        $action = (new CallbackAction())->setCallback(Callback_Handler::class, 'run')->setModel(new class extends Model {});
        $action->setResolvedModel($model);
        $action->handle(request());
        $this->assertSame($model, Callback_Handler::$received[0]);
        $collection = new Collection([$model]);
        (new CallbackBatchAction())->setCallback(Callback_Handler::class, 'run')->handle($collection);
        $this->assertSame($collection, Callback_Handler::$received[0]);
    }

    public function test_configured_dialog_and_inherited_name_survive_restore(): void
    {
        $original = (new CallbackAction())->setName('Configured')->setDialog('Continue?');
        $rebuilt = new CallbackAction();
        $rebuilt->restoreContext($original->contextState());
        $this->assertSame('Configured', $rebuilt->name());
        $this->assertSame('dialog', $rebuilt->interaction());
        $this->assertSame('Continue?', $rebuilt->loadInteraction()['settings']['title']);
        $this->assertInstanceOf(\Encore\Admin\Actions\SweatAlert2::class, $rebuilt->response()->getPlugin());
    }

    public function test_configuring_dialog_does_not_change_existing_form_actions(): void
    {
        $action = new class extends Action { public function form() {} };
        $this->assertSame('form', $action->interaction());
        $this->expectException(\LogicException::class);
        $action->setDialog('Continue?');
    }

    public function test_row_links_render_attributes_target_and_escaped_text(): void
    {
        $action = new class extends RowAction {
            public function passesAuthorizationForRendering(): bool { return true; }
        };
        $html = $action->setName('<Run>')->setHref('/orders?x="', '_blank')
            ->attribute('data-pageid', 'orders"')->render();
        $this->assertStringContainsString('&lt;Run&gt;', $html);
        $this->assertStringContainsString('href="/orders?x=&quot;"', $html);
        $this->assertStringContainsString('data-pageid="orders&quot;"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('noopener noreferrer', $html);
        $this->assertStringNotContainsString('data-admin-action', $html);
    }

    public function test_button_links_and_actions_share_rendering_but_only_actions_load_runtime(): void
    {
        $action = new class extends ButtonAction {
            public bool $loaded = false;
            public function passesAuthorizationForRendering(): bool { return true; }
            protected function addScript() { $this->loaded = true; $this->attribute('data-admin-action', 'ticket'); }
        };
        $action->setName('<Run>')->setIcon('fa-save')->attribute('data-test', 'value');
        $html = $action->render();
        $this->assertTrue($action->loaded);
        $this->assertStringContainsString('data-admin-action="ticket"', $html);
        $this->assertStringContainsString('&lt;Run&gt;', $html);
        $this->assertStringContainsString('data-test="value"', $html);
        $this->assertStringContainsString('href="javascript:void(0);"', $html);
        // Use a fresh instance, as a rendered action already carries runtime attributes.
        $class = get_class($action);
        $link = (new $class())->setHref('/orders', '_blank')->attribute('data-test', 'value');
        $html = $link->render();
        $this->assertFalse($link->loaded);
        $this->assertStringContainsString('data-test="value"', $html);
        $this->assertStringContainsString('href="/orders"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('noopener noreferrer', $html);
    }

    public function test_button_class_merges_style_and_element_class_without_escaping_slots(): void
    {
        $action = new class extends ButtonAction {
            public bool $rendered = false;
            public function passesAuthorizationForRendering(): bool { return true; }
            protected function addScript() { $this->rendered = true; }
            public function elementClass(): string { return $this->getElementClass(); }
        };
        $html = $action->setName('Sync')->setButtonStyle('btn-primary')->render();
        $this->assertTrue($action->rendered);
        $this->assertStringContainsString('class="btn btn-sm btn-primary '.$action->elementClass().'"', $html);
        $this->assertStringContainsString('>Sync</a>', $html);
    }

    public function test_configured_name_is_the_signed_source_for_the_executing_action(): void
    {
        $original = (new CallbackAction())->setName('Executed');
        // A later direct assignment is display-only: public $name never enters the ticket.
        $original->name = 'Rendered';
        $this->assertSame('Rendered', $original->name());
        $rebuilt = new CallbackAction();
        $rebuilt->restoreContext($original->contextState());
        $this->assertSame('Executed', $rebuilt->name());
    }

    public function test_direct_name_assignment_alone_is_not_signed(): void
    {
        $original = new CallbackAction();
        $original->name = 'Display only';
        $this->assertArrayNotHasKey(
            'runtime2:'.Action::class.'::name', $original->contextState()
        );
    }

    public function test_link_configuration_is_restored_for_the_executing_action(): void
    {
        $original = (new CallbackRowAction())->setHref('/orders/1/edit', '_blank');
        $state = $original->contextState();
        $this->assertArrayHasKey('runtime2:'.RowAction::class.'::link', $state);
        $this->assertArrayHasKey('runtime2:'.RowAction::class.'::hrefTarget', $state);
        $rebuilt = new CallbackRowAction();
        $rebuilt->restoreContext($state);
        $this->assertSame('/orders/1/edit', $rebuilt->getHref());
    }

    public function test_form_and_dialog_declarations_are_rejected_together(): void
    {
        $this->expectException(\LogicException::class);
        new class extends Action {
            public function form() {}
            public function dialog() {}
        };
    }

    public function test_dialog_configuration_is_rejected_when_a_dialog_is_already_declared(): void
    {
        $action = new class extends Action {
            public function dialog() { $this->confirm('Declared'); }
        };
        $this->expectException(\LogicException::class);
        $action->setDialog('Configured');
    }

    public function test_missing_callback_class_is_reported_instead_of_silently_succeeding(): void
    {
        $this->expectException(\LogicException::class);
        (new CallbackRowAction())->setCallback('Missing\\CallbackClass', 'run')->handle(null, request());
    }
}

class Callback_Handler
{
    public static $result;
    public static $received;
    public static function run($model, Request $request)
    {
        self::$received = [$model, $request];
        return self::$result;
    }
    public function instanceMethod() {}
}
