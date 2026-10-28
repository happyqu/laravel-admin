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

    public function test_model_options_accept_id_lists_and_related_records(): void
    {
        $field = new class('choices') extends \Encore\Admin\Form\Field\MultipleSelect {
            public function optionsFor($value) { return ($this->options)($value); }
        };
        $field->model(ChoiceLookupModel::class);
        foreach ([[1, 2], [4 => 1, 8 => 2], [['id' => 1], ['id' => 2]], collect([['id' => 1], ['id' => 2]])] as $value) {
            $this->assertSame([1 => 'Choice 1', 2 => 'Choice 2'], $field->optionsFor($value));
        }
        $this->assertSame([1 => 'Choice 1'], $field->optionsFor(['id' => 1, 'name' => 'Stored label']));
        $this->assertSame([0 => 'Choice 0'], $field->optionsFor(0));
        $this->assertSame([], $field->optionsFor([]));
    }

    public function test_date_ranges_normalize_empty_dates_on_insert_and_update(): void
    {
        $form = new class extends Form {
            public $testFields;
            public function __construct() { $this->testFields = collect(); }
            public function fields() { return $this->testFields; }
            public function insertData($input) { return $this->prepareInsert($input); }
            public function updateData($input) { return $this->prepareUpdate($input); }
        };
        foreach ([\Encore\Admin\Form\Field\DateRange::class, \Encore\Admin\Form\Field\DatetimeRange::class,
            \Encore\Admin\Form\Field\TimeRange::class] as $class) {
            $form->testFields = collect([new $class('starts_at', ['ends_at'])]);
            foreach ([['starts_at' => '', 'ends_at' => ''], ['starts_at' => '2026-10-28', 'ends_at' => '']] as $input) {
                $expected = array_map(static fn ($date) => $date === '' ? null : $date, $input);
                $this->assertSame($expected, $form->insertData($input));
                $this->assertSame($expected, $form->updateData($input));
            }
        }
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

    public function test_table_with_deletion_disabled_rejects_omitted_original_rows(): void
    {
        $field = (new Table('rows', ['Rows', function ($form) { $form->text('title', 'Title'); }]))
            ->setForm($this->form)->disableDelete();
        $field->setOriginal(['rows' => [0 => ['title' => 'First'], 3 => ['title' => 'Second']]]);
        foreach ([[], [0 => ['title' => 'Edited']]] as $input) {
            try {
                $field->prepare($input);
                $this->fail('Omitting an existing row must not bypass disableDelete.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('rows', $exception->errors());
            }
        }
        $this->assertSame([
            0 => ['title' => 'Edited'], 3 => ['title' => 'Second'], 'new_1' => ['title' => 'New'],
        ], $field->prepare([
            0 => ['title' => 'Edited'], 3 => ['title' => 'Second'], 'new_1' => ['title' => 'New'],
        ]));
    }

    public function test_action_table_filters_nested_values_and_files_without_losing_callback_parameters(): void
    {
        $action = new class extends \Encore\Admin\Actions\Action {
            public function form()
            {
                $this->text('summary')->value('Server value')->readonly();
                $this->table('rows', 'Rows', function ($form) {
                    $form->text('title')->rules('required');
                    $form->text('locked')->readonly();
                    $form->text('disabled')->disable();
                    $form->table('children', 'Children', function ($child) {
                        $child->text('name');
                        $child->text('locked')->readonly();
                    });
                });
            }
        };
        $upload = new \Illuminate\Http\UploadedFile(__FILE__, 'test.php', null, null, true);
        $request = Request::create('/admin/_handle_action_', 'POST', [
            '_action_token' => 'signed-ticket', '_class' => 'TrustedController', '_cb' => 'save', '_key' => '7',
            'summary' => 'Forged summary',
            'rows' => ['new_1' => [
                'title' => 'Valid', 'locked' => 'Forged', 'disabled' => 'Forged', 'extra' => 'Undeclared',
                'children' => ['new_1' => ['name' => 'Child', 'locked' => 'Forged', 'extra' => 'Undeclared']],
            ]],
        ], [], ['rows' => ['new_1' => [
            'locked' => $upload, 'extra' => $upload,
            'children' => ['new_1' => ['locked' => $upload, 'extra' => $upload]],
        ]]]);
        $request->attributes->set('_admin_action_parameters', [
            '_action_token' => 'signed-ticket', '_class' => 'TrustedController', '_cb' => 'save', '_key' => '7',
        ]);
        $action->validate($request);
        $this->assertSame(['new_1' => [
            'title' => 'Valid', 'children' => ['new_1' => ['name' => 'Child']],
        ]], request()->input('rows'));
        $this->assertSame('Server value', request('summary'));
        $this->assertSame('signed-ticket', request('_action_token'));
        $this->assertSame('TrustedController', request('_class'));
        $this->assertSame('save', request('_cb'));
        $this->assertSame('7', request('_key'));
        $this->assertFalse(request()->hasFile('rows.new_1.locked'));
        $this->assertFalse(request()->hasFile('rows.new_1.extra'));
        $this->assertFalse(request()->hasFile('rows.new_1.children.new_1.locked'));
        $this->assertFalse(request()->hasFile('rows.new_1.children.new_1.extra'));
    }

    public function test_action_select_initialization_matches_only_its_own_field(): void
    {
        $row = new class extends Model {};
        $row->setRawAttributes(['id' => 323], true);
        $action = new class extends \Encore\Admin\Actions\RowAction {
            public $name = 'Add performance';
            public function form()
            {
                $this->hidden('performance_id')->value($this->getRow()->getKey());
                $this->select('team_user_id', 'Employee')->options([1 => 'Alice'])->required();
                $this->text('percent', 'Percent')->required()->help('Enter 8 for 8%');
                $this->select('status', 'Status')->options([0 => 'Pending', 1 => 'Paid']);
            }
        };
        $action->setRow($row);
        $payload = $action->loadInteraction();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML($payload['html']); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new \DOMXPath($dom);
        $hidden = $xpath->query('//input[@name="performance_id"]')->item(0);
        $text = $xpath->query('//input[@name="percent"]')->item(0);
        $this->assertSame('hidden', $hidden->getAttribute('type'));
        $this->assertSame('323', $hidden->getAttribute('value'));
        $this->assertSame('text', $text->getAttribute('type'));
        preg_match_all('/\$\("([^"]+)"\)\.select2\(/', implode("\n", $payload['scripts']), $matches);
        $this->assertCount(2, $matches[1]);
        $names = [];
        foreach ($matches[1] as $selector) {
            $conditions = array_map(static fn ($class) => "contains(concat(' ', normalize-space(@class), ' '), ' ".$class." ')",
                explode('.', ltrim($selector, '.')));
            $nodes = $xpath->query('//*['.implode(' and ', $conditions).']');
            $this->assertSame(1, $nodes->length, 'Widget selector must match exactly one field: '.$selector);
            $this->assertSame('select', $nodes->item(0)->nodeName);
            $names[] = $nodes->item(0)->getAttribute('name');
        }
        $this->assertSame(['team_user_id', 'status'], $names);

        $second = $action->loadInteraction();
        $this->assertSame($payload['scripts'], $second['scripts']);
        // Another action in the same document must have a different modal scope.
        $otherAction = new class extends \Encore\Admin\Actions\Action {
            public function form() { $this->select('team_user_id', 'Employee')->options([2 => 'Bob']); }
        };
        $other = $otherAction->loadInteraction();
        $this->assertNotSame($payload['scripts'][0], $other['scripts'][0]);
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

class ChoiceLookupModel extends Model
{
    public static function find($ids)
    {
        return collect($ids)->map(static fn ($id) => (new static())->forceFill(['id' => $id, 'name' => 'Choice '.$id]));
    }
}
