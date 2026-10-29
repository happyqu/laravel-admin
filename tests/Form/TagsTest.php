<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Encore\Admin\Form\Field\Table;
use Encore\Admin\Form\Field\Tags;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class TagsTest extends TestCase
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
        $app->register(\Illuminate\Validation\ValidationServiceProvider::class);
        $app->register(\Illuminate\View\ViewServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $this->form = new class extends Form {
            public function __construct() { $this->model = new class extends Model {}; }
        };
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }


    public function test_invalid_tag_items_fail_validation_and_preparation_with_or_without_array_rules(): void
    {
        foreach ([false, true] as $customRules) {
            foreach ([['nested' => 'value'], [], (object) ['nested' => 'value']] as $item) {
                $field = (new Tags('labels'))->setForm($this->form);
                if ($customRules) $field->rules('array');
                $validator = $field->getValidator(['labels' => [$item]]);
                $this->assertFalse($validator->passes());
                $this->assertTrue($validator->errors()->has('labels.0'));
                try {
                    $field->prepare([$item]);
                    $this->fail('Malformed tag items must never reach storage.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('labels', $exception->errors());
                }
            }
        }
        $field = new Tags('labels');
        $this->assertFalse($field->getValidator(['labels' => 'unexpected scalar'])->passes());
    }

    public function test_commas_are_rejected_before_writing_the_legacy_delimited_format(): void
    {
        $field = (new Tags('labels'))->separators([';']);
        $this->assertFalse($field->getValidator(['labels' => ['ACME,Inc']])->passes());
        $this->expectException(ValidationException::class);
        $field->prepare(['ACME,Inc']);
    }

    public function test_pluck_mode_keeps_array_storage_and_saving_callback_argument_types(): void
    {
        $field = (new Tags('labels'))->pluck('name', 'id');
        $this->assertTrue($field->getValidator(['labels' => ['ACME,Inc']])->passes());
        $this->assertSame(['ACME,Inc'], $field->prepare(['ACME,Inc', '']));
        $field->saving(function ($ids) { return ['received' => $ids]; });
        $this->assertSame(['received' => [0, 2]], $field->prepare([0, 2, '', null]));
    }

    public function test_legacy_text_tags_round_trip_and_zero_is_preserved(): void
    {
        $field = new Tags('labels');
        $stored = $field->prepare(['hello', '标签', '0', '', null]);
        $this->assertSame('hello,标签,0', $stored);
        $field->fill(['labels' => $stored]);
        $this->assertSame(['hello', '标签', '0'], $field->value());
        $this->assertSame('', $field->prepare(['', null]));
    }

    public function test_required_and_nullable_rules_still_see_normalized_empty_values(): void
    {
        $field = (new Tags('labels'))->rules('required|array|min:1');
        $this->assertFalse($field->getValidator(['labels' => ['', null]])->passes());
        $this->assertTrue($field->getValidator(['labels' => ['', '0']])->passes());
        $field = (new Tags('labels'))->rules('nullable|array');
        $this->assertTrue($field->getValidator(['labels' => null])->passes());
        $this->assertTrue($field->getValidator([])->passes());
        $field->readonly();
        $this->assertFalse($field->getValidator(['labels' => [['bad']]]));
    }

    public function test_nested_tag_errors_use_the_complete_row_path(): void
    {
        $field = (new Table('rows', ['Rows', function ($row) { $row->tags('labels'); }]))->setForm($this->form);
        $validator = $field->getValidator(['rows' => ['new_1' => ['labels' => [['bad']]]]]);
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('rows.new_1.labels.0'));
    }

    public function test_invalid_old_items_render_the_validation_message_without_crashing(): void
    {
        request()->session()->flashInput(['labels' => [['bad']]]);
        app('view')->share('errors', (new \Illuminate\Support\ViewErrorBag())->put('default',
            new \Illuminate\Support\MessageBag(['labels.0' => 'Expected scalar tag values.'])));
        $html = (new Tags('labels'))->setForm($this->form)->render();
        $this->assertStringContainsString('Expected scalar tag values.', $html);
        $this->assertStringContainsString('has-error', $html);
    }
}
