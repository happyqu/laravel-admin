<?php

namespace Encore\Admin\Tests\Widgets;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Carousel;
use Encore\Admin\Widgets\Collapse;
use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\MultipleSteps;
use Encore\Admin\Widgets\StepForm;
use Encore\Admin\Widgets\Tab;
use Encore\Admin\Widgets\Table;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class WidgetBehaviorTest extends TestCase
{
    private $previous;
    private Store $session;
    private object $user;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'admin' => ['route' => ['prefix' => 'admin']],
        ]));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->session = new Store('widgets', new ArraySessionHandler(120));
        $this->session->regenerateToken();
        $app->instance('session', $this->session);
        foreach ([\Illuminate\Translation\TranslationServiceProvider::class, \Illuminate\Validation\ValidationServiceProvider::class,
            \Illuminate\Routing\RoutingServiceProvider::class, \Illuminate\View\ViewServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $app['view']->share('errors', new \Illuminate\Support\ViewErrorBag());
        $this->user = new class { public string $id = 'first'; public function getAuthIdentifier() { return $this->id; } };
        $admin = $this->getMockBuilder(\Encore\Admin\Admin::class)->onlyMethods(['user'])->getMock();
        $admin->method('user')->willReturn($this->user);
        Admin::swap($admin);
        $this->request([]);
    }

    protected function tearDown(): void
    {
        \Encore\Admin\Admin::$script = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function request(array $input, string $method = 'GET', string $url = '/admin/widgets'): Request
    {
        $request = Request::create('http://localhost'.$url, $method, $input);
        $request->setLaravelSession($this->session);
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        return $request;
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML($html); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new \DOMXPath($dom);
    }

    public function test_attributes_reject_injected_names_and_support_boolean_and_array_values(): void
    {
        $widget = new Collapse();
        $widget->offsetSet('x" onclick="alert(1)', 'value');
        $widget->offsetSet('disabled', false);
        $widget->offsetSet('hidden', true);
        $widget->offsetSet('data-config', ['quoted' => 'A&B']);
        $node = $this->dom($widget->render())->query('//body/div')->item(0);
        $this->assertFalse($node->hasAttribute('onclick'));
        $this->assertFalse($node->hasAttribute('disabled'));
        $this->assertTrue($node->hasAttribute('hidden'));
        $this->assertSame(['quoted' => 'A&B'], json_decode($node->getAttribute('data-config'), true));
    }

    public function test_operation_log_parameters_have_a_bounded_preview_and_escaped_full_detail(): void
    {
        $input = json_encode(['message' => str_repeat('Long message ', 1000),
            'html' => '</pre><script>alert(1)</script>', 'name' => '中文',
            '_token' => 'csrf-secret', '_action_token' => str_repeat('ticket', 1000), '_method' => 'PUT']);
        $formatter = \Encore\Admin\Widgets\OperationLogInput::class;
        $preview = $formatter::preview($input);
        $this->assertLessThanOrEqual(103, strlen($preview));
        $this->assertStringNotContainsString("\n", $preview);
        $full = $formatter::format($input);
        $this->assertStringContainsString('中文', $full);
        $this->assertStringContainsString(str_repeat('Long message ', 1000), $full);
        $this->assertStringNotContainsString('csrf-secret', $full);
        $this->assertStringNotContainsString('ticket', $full);
        $html = view('admin::widgets.operation-log-input', ['input' => $full])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertSame(0, $this->dom($html)->query('//script')->length);
    }

    public function test_operation_log_detail_preserves_truncated_json_and_empty_parameters(): void
    {
        $formatter = \Encore\Admin\Widgets\OperationLogInput::class;
        $truncated = '{"message":"incomplete';
        $this->assertSame($truncated, $formatter::format($truncated));
        $this->assertSame('{}', $formatter::format(['_token' => 'csrf', '_previous_' => '/admin']));
        $this->assertSame('{}', $formatter::format(null));
        $this->assertSame('0', $formatter::format('0'));
        $this->assertSame('false', $formatter::format('false'));
    }

    public function test_operation_log_list_embeds_only_a_preview_and_loads_detail_asynchronously(): void
    {
        app()['router']->get('admin/_handle_renderable_', fn () => null)->name('admin.handle-renderable');
        app()['router']->getRoutes()->refreshNameLookups();
        config(['admin.database.operation_log_table' => 'admin_operation_log']);
        $row = new \Encore\Admin\Auth\Database\OperationLog();
        $row->setRawAttributes(['id' => 7, 'input' => json_encode(['message' => str_repeat('payload ', 1000).'END_OF_PAYLOAD'])]);
        $grid = new \Encore\Admin\Grid($row);
        \Encore\Admin\Grid\Column::setOriginalGridModels(new \Illuminate\Database\Eloquent\Collection([$row]));
        $column = $grid->column('input')->display(function ($input) {
            return \Encore\Admin\Widgets\OperationLogInput::preview($input);
        })->modal('Request parameters', \Encore\Admin\Widgets\OperationLogInput::class);
        $html = $column->fill([$row->toArray()])[0]['input'];
        $this->assertStringContainsString('payload', $html);
        $this->assertStringNotContainsString('END_OF_PAYLOAD', $html);
        $this->assertLessThan(3000, strlen($html));
        $tickets = session('admin.component_submissions');
        $this->assertCount(1, $tickets);
        $ticket = reset($tickets);
        $this->assertSame(\Encore\Admin\Widgets\OperationLogInput::class, $ticket['class']);
        $this->assertSame([7], $ticket['payload']);
    }

    public function test_boxes_keep_zero_and_defer_renderable_content(): void
    {
        $child = new class implements \Illuminate\Contracts\Support\Renderable {
            public string $value = 'before';
            public int $renders = 0;
            public function render() { $this->renders++; return $this->value; }
        };
        $box = new Box('0', $child, '0');
        $this->assertSame(0, $child->renders);
        $child->value = 'after';
        $html = $box->render();
        $this->assertStringContainsString('after', $html);
        $this->assertSame(1, $child->renders);
        $this->assertStringContainsString('box-footer', $html);
        $this->assertStringContainsString('>0</h3>', $html);
        $this->assertStringNotContainsString('here is the box content', (new Box())->render());
        $this->assertStringContainsString('0', (new Box('', 0))->render());
    }

    public function test_collapse_instances_have_independent_panel_ids(): void
    {
        $html = (new Collapse())->add('One', 'first')->render().(new Collapse())->add('Two', 'second')->render();
        $xpath = $this->dom($html);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) $ids[] = $node->getAttribute('id');
        $this->assertCount(count($ids), array_unique($ids));
        foreach ($xpath->query('//a[@data-toggle="collapse"]') as $link) {
            $this->assertSame(1, $xpath->query('//*[@id="'.substr($link->getAttribute('href'), 1).'"]')->length);
        }
    }

    public function test_table_rows_are_replaced_and_later_style_changes_are_applied(): void
    {
        $table = new Table([], ['old' => 'Old'], ['table-striped']);
        $table->setRows(['new' => 'New'])->setStyle(['table-bordered']);
        $html = $table->render();
        $this->assertStringNotContainsString('Old', $html);
        $this->assertStringContainsString('New', $html);
        $this->assertStringContainsString('table-bordered', $html);
        $this->assertStringNotContainsString('table-striped', $html);
    }

    public function test_keyed_carousels_have_an_active_first_slide_and_escape_ids(): void
    {
        $carousel = new Carousel(['first' => ['image' => '/one.png', 'caption' => 'One'], 'second' => ['image' => '/two.png', 'caption' => 'Two']]);
        $carousel->id('custom" onclick="alert(1)');
        $xpath = $this->dom($carousel->render());
        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", @class, " "), " item ") and contains(@class,"active")]')->length);
        $this->assertSame(0, $xpath->query('//*[@onclick]')->length);
        $this->assertSame('0', $xpath->query('//li[@data-slide-to]')->item(0)->getAttribute('data-slide-to'));
    }

    public function test_widget_form_definitions_and_internal_fields_are_not_duplicated(): void
    {
        $form = new HandledWidget();
        $form->render();
        $form->render();
        $this->assertFalse($form->validate($this->request(['name' => 'Value'], 'POST')));
        $this->assertSame(1, $form->builds);
        $this->assertSame(['name', '_form_', '_form_token_'], $form->fields()->map->column()->all());
    }

    public function test_widget_form_method_switching_spoofs_patch_delete_and_removes_old_overrides(): void
    {
        $form = new Form();
        foreach (['PATCH', 'DELETE', 'PUT'] as $method) {
            $form->method('GET')->method($method);
            $xpath = $this->dom($form->render());
            $this->assertSame('POST', $xpath->query('//form')->item(0)->getAttribute('method'));
            $this->assertSame(1, $xpath->query('//input[@name="_method"]')->length);
            $this->assertSame($method, $xpath->query('//input[@name="_method"]')->item(0)->getAttribute('value'));
        }
        $this->assertSame(0, $this->dom($form->method('GET')->render())->query('//input[@name="_method"]')->length);
    }

    public function test_form_fill_can_be_cleared_and_widths_apply_to_later_fields(): void
    {
        $form = new Form(['name' => 'Old']);
        $form->setWidth(6, 3);
        $field = $form->text('name', 'Name');
        $this->assertSame('col-sm-6', $field->getViewElementClasses()['field']);
        $this->assertStringContainsString('value="Old"', $form->render());
        $this->assertStringNotContainsString('value="Old"', $form->fill([])->render());
        $form->table('rows', 'Rows', function () {});
        $this->assertStringContainsString('multipart/form-data', $form->formatAttribute());
    }

    public function test_rendering_again_keeps_explicit_input_values_and_password_types(): void
    {
        $form = new Form(['fixed' => 'Data', 'password' => 'one']);
        $form->text('fixed', 'Fixed')->attribute('value', 'Explicit');
        $form->password('password', 'Password');
        $form->render();
        $xpath = $this->dom($form->fill(['fixed' => 'Changed', 'password' => 'two'])->render());
        $this->assertSame('Explicit', $xpath->query('//input[@name="fixed"]')->item(0)->getAttribute('value'));
        $this->assertSame('password', $xpath->query('//input[@name="password"]')->item(0)->getAttribute('type'));
        $this->assertSame('two', $xpath->query('//input[@name="password"]')->item(0)->getAttribute('value'));
        $field = $form->fields()->first(fn ($field) => $field->column() === 'password');
        $field->attribute('value', 'two');
        $xpath = $this->dom($form->fill(['password' => 'three'])->render());
        $this->assertSame('two', $xpath->query('//input[@name="password"]')->item(0)->getAttribute('value'));
    }

    public function test_widget_sanitization_removes_undeclared_nested_and_readonly_fields(): void
    {
        $form = new Form();
        $form->table('rows', 'Rows', function ($nested) {
            $nested->text('title', 'Title');
            $nested->text('locked', 'Locked')->readonly();
        });
        $form->embeds('settings', 'Settings', function ($nested) { $nested->text('color', 'Color'); });
        $this->request(['rows' => ['new_1' => ['title' => 'Allowed', 'locked' => 'Changed', 'admin' => true]],
            'settings' => ['color' => 'blue', 'secret' => 'Changed'], 'other' => 'Changed'], 'POST');
        $form->sanitize();
        $this->assertSame(['rows' => ['new_1' => ['title' => 'Allowed']], 'settings' => ['color' => 'blue']], request()->all());
    }

    public function test_unauthorized_form_is_not_built_or_rendered(): void
    {
        $form = new class extends Form {
            public function authorize($user) { return false; }
            public function form() { throw new \RuntimeException('Private data was loaded.'); }
        };
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $form->render();
    }

    public function test_tab_empty_dropdown_and_malformed_active_parameter_do_not_throw(): void
    {
        $this->assertInstanceOf(Tab::class, (new Tab())->dropDown([]));
        $this->request(['active' => ['bad']]);
        $tab = Tab::forms([0 => HandledWidget::class, 1 => HandledWidget::class], 0);
        $this->assertStringContainsString('data-toggle="tab"', $tab->render());
        $this->assertStringContainsString('data-toggle="tab"', Tab::forms([0 => HandledWidget::class])->render());
    }

    private function flow(): string
    {
        $this->request(['business' => '7']);
        (new MultipleSteps(['first.one' => FirstWidgetStep::class, 'second' => SecondWidgetStep::class]))->render();
        return array_key_first(session('admin.step_flows'));
    }

    public function test_step_flow_preserves_query_data_and_dot_named_steps(): void
    {
        $flow = $this->flow();
        $step = new FirstWidgetStep();
        $step->restoreSubmissionContext(['flow' => $flow, 'current' => 'first.one']);
        $redirect = $step->advance(['name' => 'Saved']);
        parse_str(parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('7', $query['business']);
        $this->assertSame('second', $query['step']);
        $this->assertSame($flow, $query['_flow']);
        $this->assertSame(['name' => 'Saved'], $step->data());
    }

    public function test_last_step_cannot_mutate_data_before_failing_to_advance(): void
    {
        $flow = $this->flow();
        $first = new FirstWidgetStep();
        $first->restoreSubmissionContext(['flow' => $flow, 'current' => 'first.one']);
        $first->advance(['name' => 'Original']);
        $last = new SecondWidgetStep();
        $last->restoreSubmissionContext(['flow' => $flow, 'current' => 'second']);
        $state = session('admin.step_flows.'.$flow);
        try {
            $last->advance(['name' => 'Unexpected']);
            $this->fail('Last step was advanced.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($state, session('admin.step_flows.'.$flow));
    }

    public function test_step_flow_rejects_another_user_and_future_steps(): void
    {
        $flow = $this->flow();
        $this->request(['_flow' => $flow, 'step' => 'second']);
        try {
            (new MultipleSteps(['first.one' => FirstWidgetStep::class, 'second' => SecondWidgetStep::class]))->render();
            $this->fail('A future step was rendered.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->user->id = 'second-user';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new FirstWidgetStep())->restoreSubmissionContext(['flow' => $flow, 'current' => 'first.one']);
    }

    public function test_empty_steps_and_array_step_parameters_are_safe(): void
    {
        $this->assertSame('', (new MultipleSteps())->render());
        $this->request(['step' => ['bad']]);
        $this->assertStringContainsString('<form', (new MultipleSteps([0 => FirstWidgetStep::class]))->render());
    }

    public function test_step_flow_cannot_be_reused_with_different_business_query_parameters(): void
    {
        $flow = $this->flow();
        $this->request(['business' => '7', '_flow' => $flow, 'step' => 'first.one']);
        $steps = ['first.one' => FirstWidgetStep::class, 'second' => SecondWidgetStep::class];
        $this->assertStringContainsString('<form', (new MultipleSteps($steps))->render());
        $this->request(['business' => '8', '_flow' => $flow, 'step' => 'first.one']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MultipleSteps($steps))->render();
    }
}

class HandledWidget extends Form
{
    public $title = 'Example';
    public int $builds = 0;
    public function form() { $this->builds++; $this->text('name', 'Name')->rules('required'); }
    public function handle(Request $request) { return null; }
}
class FirstWidgetStep extends StepForm
{
    public function form() { $this->text('name', 'Name'); }
    public function handle(Request $request) { return null; }
    public function advance(array $data) { return $this->next($data); }
}
class SecondWidgetStep extends FirstWidgetStep {}
