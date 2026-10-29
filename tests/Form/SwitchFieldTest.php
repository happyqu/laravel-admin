<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Encore\Admin\Form\Field\SwitchField;
use Encore\Admin\Grid\Displayers\SwitchDisplay;
use Encore\Admin\Grid\Displayers\SwitchGroup;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SwitchFieldTest extends TestCase
{
    private $previousContainer;
    private Form $form;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        ]));
        $session = new Store('fields', new ArraySessionHandler(120));
        $app->instance('session', $session);
        $request = Request::create('/admin/items', 'POST');
        $request->setLaravelSession($session);
        $app->instance('request', $request);
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $app->register(\Illuminate\View\ViewServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $app['view']->share('errors', new \Illuminate\Support\ViewErrorBag());
        $this->form = new Form(new class extends Model {});
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    public function test_shortcuts_and_legacy_states_merge_without_resetting_other_options(): void
    {
        $field = (new SwitchField('enabled'))->texts('打开', '关闭')
            ->colors('success', 'danger')->values('yes', 'no')
            ->states(['on' => ['text' => '启用']]);
        $states = (new \ReflectionProperty(SwitchField::class, 'states'))->getValue($field);
        $this->assertSame([
            'on' => ['value' => 'yes', 'text' => '启用', 'color' => 'success'],
            'off' => ['value' => 'no', 'text' => '关闭', 'color' => 'danger'],
        ], $states);
        $this->assertSame('yes', $field->prepare('on'));
        $this->assertSame('no', $field->prepare('off'));
        $this->assertSame('yes', $field->prepare('yes'));
    }

    public function test_defaults_boolean_casts_and_database_strings_use_the_same_state(): void
    {
        foreach ([[1, 'on'], ['1', 'on'], [true, 'on'], [0, 'off'], ['0', 'off'],
            [false, 'off'], [null, 'off'], ['unknown', 'off'], ['on', 'on']] as [$value, $state]) {
            $field = (new SwitchField('enabled'))->setForm($this->form);
            if ($value !== null) {
                $field->value($value);
            }
            $html = $field->render();
            $this->assertStringContainsString('name="enabled" value="'.$state.'"', $html);
            $this->assertSame($value, $field->value());
            $this->assertSame($state, $field->variables()['switchState']);
        }
    }

    public function test_custom_stored_values_survive_repeated_rendering(): void
    {
        $field = (new SwitchField('status'))->setForm($this->form)
            ->values('enabled', 'disabled')->value('enabled');
        for ($i = 0; $i < 2; $i++) {
            $this->assertStringContainsString('name="status" value="on"', $field->render());
            $this->assertSame('enabled', $field->value());
        }
        $field->value('disabled');
        $this->assertStringContainsString('name="status" value="off"', $field->render());
    }

    public function test_equivalent_numeric_storage_formats_keep_their_state_on_submission(): void
    {
        foreach ([['1.00', 1, 0, 'on'], ['01', 1, 0, 'on'], ['1e0', 1, 0, 'on'],
            ['0.00', 1, 0, 'off'], ['00', 0, 1, 'on'], ['1.50', 1.5, 0, 'on'],
            [1, '1.00', '0.00', 'on']] as [$value, $on, $off, $expected]) {
            $field = (new SwitchField('enabled'))->setForm($this->form)->values($on, $off)->value($value);
            $html = $field->render();
            $this->assertSame(1, preg_match('/name="enabled" value="(on|off)"/', $html, $matches));
            $this->assertSame($expected, $matches[1]);
            $this->assertSame($expected === 'on' ? $on : $off, $field->prepare($matches[1]));
            $this->assertSame($value, $field->value());
        }
    }

    public function test_numeric_matching_does_not_coerce_arbitrary_text_to_zero(): void
    {
        $field = (new SwitchField('enabled'))->setForm($this->form)->values(0, 1);
        foreach (['enabled', '0abc', '', 'unknown'] as $value) {
            $field->value($value);
            $this->assertSame('off', $field->variables()['switchState']);
        }
    }

    public function test_nested_old_input_takes_priority_over_stored_values(): void
    {
        request()->session()->flashInput(['rows' => ['new_1' => ['enabled' => 'off']]]);
        $field = (new SwitchField('enabled'))->setForm($this->form)
            ->setErrorKey('rows.new_1.enabled')->value(1);
        $this->assertSame('off', $field->variables()['switchState']);
        $this->assertSame(1, $field->value());
    }

    public function test_old_transport_tokens_are_not_confused_with_inverted_stored_values(): void
    {
        $field = (new SwitchField('enabled'))->setForm($this->form)
            ->values('off', 'on')->value('off');
        $this->assertSame('on', $field->variables()['switchState']);
        request()->session()->flashInput(['enabled' => 'off']);
        $this->assertSame('off', $field->variables()['switchState']);
        $this->assertSame('on', $field->prepare('off'));
    }

    public function test_javascript_options_escape_quotes_newlines_and_script_tags(): void
    {
        $label = "It's open\n</script>";
        $field = (new SwitchField('enabled'))->setForm($this->form)->texts($label, '关闭');
        $field->render();
        $script = $field->getScript();
        $this->assertStringNotContainsString('</script>', $script);
        $this->assertSame(1, preg_match('/\$\.extend\(\{\}, (\{[^\n]+\}), \{/', $script, $matches));
        $options = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($label, $options['onText']);
        $this->assertSame('关闭', $options['offText']);
        $this->assertSame('primary', $options['onColor']);
    }

    public function test_grid_switches_share_form_state_matching(): void
    {
        foreach ([SwitchDisplay::class, SwitchGroup::class] as $class) {
            $display = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $display->values('enabled', 'disabled');
            $method = new \ReflectionMethod($class, 'switchState');
            $this->assertSame('on', $method->invoke($display, 'enabled'));
            $this->assertSame('off', $method->invoke($display, true));
            $this->assertSame('off', $method->invoke($display, 'disabled'));
            $display->values(1, 0);
            $this->assertSame('on', $method->invoke($display, true));
            $this->assertSame('on', $method->invoke($display, '1'));
            $this->assertSame('on', $method->invoke($display, '1.00'));
            $this->assertSame('on', $method->invoke($display, '01'));
            $this->assertSame('off', $method->invoke($display, '0.00'));
            $this->assertSame('off', $method->invoke($display, false));
        }
    }
}
