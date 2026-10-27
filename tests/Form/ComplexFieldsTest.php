<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Encore\Admin\Form\Field\Embeds;
use Encore\Admin\Form\Field\HasMany;
use Encore\Admin\Form\Field\ListField;
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

class ComplexFieldsTest extends TestCase
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

    private function hasMany(\Closure $builder): HasMany
    {
        return (new class('rows', ['Rows', $builder]) extends HasMany {
            protected function getKeyName() { return 'id'; }
        })->setForm($this->form);
    }

    public function test_nested_text_and_array_fields_keep_all_validation_input(): void
    {
        $field = $this->hasMany(function ($form) {
            $form->text('title', 'Title')->rules('required');
            $form->tags('labels', 'Labels')->rules('required|array');
            $form->multipleSelect('choices', 'Choices')->rules('required|array');
        });
        $validator = $field->getValidator(['rows' => ['new_1' => [
            'title' => 'Valid', 'labels' => ['foo', ''], 'choices' => ['0', ''],
        ]]]);
        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
        $validator = $field->getValidator(['rows' => ['new_1' => [
            'title' => '', 'labels' => [''], 'choices' => [''],
        ]]]);
        $this->assertFalse($validator->passes());
        $this->assertCount(3, $validator->errors()->messages());
        $this->assertTrue($validator->errors()->has('rows.new_1.title'));
    }

    public function test_nested_custom_validators_execute_and_errors_use_the_row_path(): void
    {
        $calls = 0;
        $field = $this->hasMany(function ($form) use (&$calls) {
            $form->text('title', 'Title')->validator(function ($input) use (&$calls) {
                $calls++;
                return validator($input, ['title' => 'in:allowed']);
            });
        });
        $validator = $field->getValidator(['rows' => ['new_2' => ['title' => 'disallowed']]]);
        $this->assertFalse($validator->passes());
        $this->assertSame(1, $calls);
        $this->assertTrue($validator->errors()->has('rows.new_2.title'));
    }

    public function test_embedded_custom_validators_execute(): void
    {
        $field = (new Embeds('extra', ['Extra', function ($form) {
            $form->text('code', 'Code')->validator(function ($input) {
                return validator($input, ['code' => 'in:allowed']);
            });
        }]))->setForm($this->form);
        $validator = $field->getValidator(['extra' => ['code' => 'disallowed']]);
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('extra.code'));
    }

    public function test_distinct_rules_still_compare_rows_and_ignore_deleted_rows(): void
    {
        $field = $this->hasMany(function ($form) { $form->text('title', 'Title')->rules('required'); })
            ->distinctFields(['title']);
        $validator = $field->getValidator(['rows' => [
            ['title' => 'same'], ['title' => 'same'],
        ]]);
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('rows.0.title'));
        $validator = $field->getValidator(['rows' => [
            ['title' => 'same'], ['title' => 'same', '_remove_' => '1'], ['_remove_' => '1'],
        ]]);
        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    public function test_nested_range_and_dependent_rules_use_sibling_input(): void
    {
        $field = $this->hasMany(function ($form) {
            $form->dateRange('start', 'end', 'Dates')->rules('required|date');
            $form->text('password', 'Password')->rules('confirmed');
            $form->text('password_confirmation', 'Confirmation');
        });
        $validator = $field->getValidator(['rows' => [[
            'start' => '2026-01-01', 'end' => '2026-01-02',
            'password' => 'abc', 'password_confirmation' => 'abc',
        ]]]);
        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    public function test_existing_nested_file_does_not_require_reupload(): void
    {
        // No disk access is needed when keeping an existing upload.
        Form::extend('reviewFile', ValidationOnlyFile::class);
        try {
            $field = $this->hasMany(function ($form) { $form->reviewFile('attachment', 'Attachment')->rules('required'); });
            $field->setOriginal(['rows' => [['id' => 7, 'attachment' => 'saved.pdf']]]);
            $validator = $field->getValidator(['rows' => [['id' => 7, 'attachment' => '']]]);
            $this->assertTrue($validator->passes(), $validator->errors()->toJson());
            $validator = $field->getValidator(['rows' => [['id' => '', 'attachment' => '']]]);
            $this->assertFalse($validator->passes());
        } finally {
            unset(Form::$availableFields['reviewFile']);
        }
    }

    public function test_new_nested_multiselect_keeps_values_equal_to_the_previous_row(): void
    {
        $nested = (new Form\NestedForm('rows'))->setForm($this->form);
        $nested->multipleSelect('choices', 'Choices');
        $nested->hidden('id', 'ID');
        $nested->setOriginal([['id' => 7, 'choices' => ['a']]], 'id');
        $prepared = $nested->prepare([
            7 => ['id' => '7', 'choices' => ['a']],
            'new_1' => ['id' => '', 'choices' => ['a']],
        ]);
        $this->assertSame(['a'], $prepared['new_1']['choices']);
    }

    public function test_empty_tags_fail_required_but_zero_is_a_valid_tag(): void
    {
        $field = (new Tags('labels', ['Labels']))->rules('required');
        $this->assertFalse($field->getValidator(['labels' => ['']])->passes());
        $this->assertTrue($field->getValidator(['labels' => ['0', '']])->passes());
        $this->assertSame('0', $field->prepare(['', '0']));
    }

    public function test_table_cannot_delete_when_deletion_is_disabled(): void
    {
        $field = (new Table('rows', ['Rows', function ($form) { $form->text('title', 'Title'); }]))
            ->setForm($this->form)->disableDelete();
        $field->setOriginal(['rows' => [['title' => 'Existing']]]);
        $this->expectException(ValidationException::class);
        $field->prepare([['title' => 'Existing', '_remove_' => '1']]);
    }

    public function test_table_can_edit_existing_rows_with_creation_disabled(): void
    {
        $field = (new Table('rows', ['Rows', function ($form) { $form->text('title', 'Title'); }]))
            ->setForm($this->form)->disableCreate();
        $field->setOriginal(['rows' => [0 => ['title' => 'Existing']]]);
        $this->assertSame([['title' => 'Edited']], $field->prepare([['title' => 'Edited']]));
        $this->expectException(ValidationException::class);
        $field->prepare(['new_1' => ['title' => 'New']]);
    }

    public function test_list_size_limits_work_without_item_rules(): void
    {
        $field = (new ListField('items', ['Items']))->min(2)->max(3);
        $this->assertFalse($field->getValidator(['items' => ['values' => ['a']]])->passes());
        $this->assertTrue($field->getValidator(['items' => ['values' => ['a', 'b']]])->passes());
        $this->assertFalse($field->getValidator(['items' => ['values' => ['a', 'b', 'c', 'd']]])->passes());
    }

    public function test_keyvalue_checks_duplicates_and_shape_without_item_rules(): void
    {
        $field = new Form\Field\KeyValue('settings', ['Settings']);
        foreach ([
            ['keys' => ['x', 'x'], 'values' => ['a', 'b']],
            ['keys' => ['x'], 'values' => []],
            ['keys' => [['nested']], 'values' => ['a']],
            ['keys' => 'invalid', 'values' => []],
            'invalid',
        ] as $value) {
            $this->assertFalse($field->getValidator(['settings' => $value])->passes());
        }
        $this->assertTrue($field->getValidator(['settings' => ['keys' => ['x'], 'values' => ['a']]])->passes());
        $this->assertSame(['x' => 'a'], $field->prepare(['keys' => ['x'], 'values' => ['a']]));
        $this->expectException(ValidationException::class);
        $field->prepare(['keys' => ['x'], 'values' => []]);
    }

    public function test_collection_editors_can_save_an_explicit_empty_value(): void
    {
        foreach ([new ListField('items', ['Items']), new Form\Field\KeyValue('items', ['Items'])] as $field) {
            $this->assertTrue($field->getValidator(['items' => ['_present' => '1']])->passes());
            $this->assertSame([], $field->prepare(['_present' => '1']));
        }
        $field = (new ListField('items', ['Items']))->min(1);
        $this->assertFalse($field->getValidator(['items' => ['_present' => '1']])->passes());
        $this->assertFalse($field->getValidator(['items' => 'invalid'])->passes());
    }

    public function test_nested_collection_views_keep_names_and_old_input(): void
    {
        request()->session()->flashInput(['rows' => ['new_1' => ['items' => ['keys' => ['key'], 'values' => ['Retained']]]]]);
        foreach (['listfield', 'keyvalue'] as $view) {
            $html = view('admin::form.'.$view, [
                'viewClass' => ['form-group' => '', 'label' => '', 'field' => ''],
                'label' => 'Items', 'name' => 'rows[new_1][items]', 'column' => 'items',
                'errorKey' => 'rows.new_1.items', 'value' => ['key' => 'Value'],
                'errors' => new \Illuminate\Support\MessageBag(),
            ])->render();
            $this->assertStringContainsString('name="rows[new_1][items][_present]"', $html);
            $this->assertStringContainsString('name="rows[new_1][items][values][]"', $html);
            $this->assertStringNotContainsString('name="items[values][]"', $html);
            $this->assertStringContainsString('Retained', $html);
        }
    }

    public function test_nested_uploads_enable_native_multipart_submission(): void
    {
        foreach ([new Table('rows', ['Rows', function () {}]), new Embeds('extra', ['Extra', function () {}])] as $field) {
            $builder = new class($field) extends Form\Builder {
                public function __construct($field) { $this->fields = collect([$field]); }
                protected function addRedirectUrlField() {}
                public function getAction(): string { return '/admin/items'; }
            };
            $this->assertStringContainsString('enctype="multipart/form-data"', $builder->open());
        }
    }

    public function test_multiple_choice_cascades_accept_null_and_array_values(): void
    {
        $form = new Form(new class extends Model {});
        $field = $form->multipleSelect('choices', 'Choices')->when('has', 'x', function ($form) {
            $form->text('extra', 'Extra');
        });
        $field->fill(['choices' => null]);
        $field->fill(['choices' => ['x']]);
        $groups = $form->fields()->filter(fn ($field) => $field instanceof Form\Field\CascadeGroup);
        $this->assertCount(1, $groups);
        $this->assertStringNotContainsString(' hide', $groups->first()->render());
    }

    public function test_empty_optional_multiple_upload_does_not_throw_on_null(): void
    {
        $field = new ValidationOnlyMultipleFile('attachments', ['Attachments']);
        $this->assertTrue($field->getValidator(['attachments' => null])->passes());
        $field->rules('required');
        $this->assertFalse($field->getValidator(['attachments' => null])->passes());
    }
}

class ValidationOnlyFile extends \Encore\Admin\Form\Field\File
{
    protected function initStorage() {}
}

class ValidationOnlyMultipleFile extends \Encore\Admin\Form\Field\MultipleFile
{
    protected function initStorage() {}
}
