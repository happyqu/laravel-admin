<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class CascadeFieldsTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new \Illuminate\Config\Repository(['app' => ['locale' => 'en']]));
        $app->instance('session', new \Illuminate\Session\Store('cascade', new \Illuminate\Session\ArraySessionHandler(120)));
        $app->instance('request', \Illuminate\Http\Request::create('/admin/items', 'POST'));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $app->register(\Illuminate\Validation\ValidationServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function form(): Form
    {
        return new Form(new class extends Model {});
    }

    private function prepare(Form $form, string $method, array $input): array
    {
        return (new \ReflectionMethod($form, $method))->invoke($form, $input);
    }

    public function test_inactive_branches_do_not_validate_or_prepare_forged_values(): void
    {
        $form = $this->form();
        $form->radio('nationality')
            ->when(1, fn ($form) => $form->text('idcard')->rules('required'))
            ->when(2, fn ($form) => $form->text('passport')->rules('required'));
        $input = ['nationality' => 1, 'idcard' => '123'];
        $this->assertFalse($form->validationMessages($input));
        $this->assertArrayHasKey('passport', $form->validationMessages(['nationality' => 2])->toArray());
        foreach (['prepareInsert', 'prepareUpdate'] as $method) {
            $this->assertSame($input, $this->prepare($form, $method, $input + ['passport' => 'forged']));
        }
    }

    public function test_active_definition_wins_when_branches_share_a_column(): void
    {
        $form = $this->form();
        $form->select('kind')
            ->when(1, function ($form) {
                $form->pushField(new class('name') extends Form\Field\Text {
                    public function prepare($value) { return 'inactive-'.$value; }
                });
                $form->fields()->last()->rules('integer');
            })
            ->when(2, fn ($form) => $form->text('name')->rules('required'));
        $input = ['kind' => '2', 'name' => 'Alice'];
        $this->assertFalse($form->validationMessages($input));
        foreach (['prepareInsert', 'prepareUpdate'] as $method) {
            $this->assertSame($input, $this->prepare($form, $method, $input));
        }
    }

    public function test_nested_conditions_require_all_ancestors_and_filter_nested_columns(): void
    {
        $form = $this->form();
        $form->select('outer')->when(1, function ($form) {
            $form->select('inner')->when('has', 'x', function ($form) {
                $form->text('profile.secret')->rules('required');
                $form->dateRange('start', 'end');
            });
        });
        $form->text('profile.public');
        $input = ['outer' => 0, 'inner' => ['x'], 'profile' => ['secret' => 'forged', 'public' => 'kept'], 'start' => 'a', 'end' => 'b'];
        $this->assertFalse($form->validationMessages($input));
        $this->assertSame(['outer' => 0, 'profile' => ['public' => 'kept']], Form\Cascade::filterInput($form->fields(), $input));
        $this->assertArrayHasKey('profile.secret', $form->validationMessages(['outer' => 1, 'inner' => ['x']])->toArray());
    }

    public function test_all_supported_controls_apply_server_conditions(): void
    {
        foreach (['select', 'multipleSelect', 'belongsTo', 'belongsToMany', 'radio', 'radioButton', 'radioCard', 'checkbox', 'checkboxButton', 'checkboxCard'] as $type) {
            $form = $this->form();
            $field = in_array($type, ['belongsTo', 'belongsToMany'])
                ? $form->{$type}('status', CascadeSelectable::class) : $form->{$type}('status');
            $multiple = in_array($type, ['multipleSelect', 'belongsToMany', 'checkbox', 'checkboxButton', 'checkboxCard']);
            $field->when($multiple ? 'has' : '=', 1, fn ($form) => $form->text('extra')->rules('required'));
            $this->assertFalse($form->validationMessages(['status' => $multiple ? ['2'] : '2']), $type);
            $this->assertArrayHasKey('extra', $form->validationMessages(['status' => $multiple ? ['1'] : '1'])->toArray(), $type);
        }
    }

    public function test_widget_validation_and_upload_sanitizing_share_the_condition_context(): void
    {
        $form = new \Encore\Admin\Widgets\Form();
        $form->select('kind')->when(1, fn ($form) => $form->text('document')->rules('required'));
        $upload = \Illuminate\Http\UploadedFile::fake()->create('document.txt', 1);
        foreach ([1, 2] as $kind) {
            $request = \Illuminate\Http\Request::create('/admin/form', 'POST', ['kind' => $kind], [], ['document' => $upload]);
            app()->instance('request', $request);
            Facade::clearResolvedInstance('request');
            $this->assertFalse($form->validate($request));
            $form->sanitize();
            $this->assertSame($kind === 1, request()->hasFile('document'));
        }
    }

    public function test_partial_update_uses_the_saved_controller_when_it_is_omitted(): void
    {
        $form = $this->form();
        $form->model()->setRawAttributes(['kind' => 2]);
        $form->select('kind')->when(1, fn ($form) => $form->text('extra')->rules('required'));
        (new \ReflectionProperty($form, 'partialSubmission'))->setValue($form, true);
        $this->assertFalse($form->validationMessages(['extra' => 'forged']));
        $this->assertSame([], $this->prepare($form, 'prepareUpdate', ['extra' => 'forged']));
        $form->model()->setRawAttributes(['kind' => 1]);
        $this->assertFalse($form->validationMessages(['extra' => 'valid']));
        $this->assertSame(['extra' => 'valid'], $this->prepare($form, 'prepareUpdate', ['extra' => 'valid']));
    }

    public function test_partial_conditions_preserve_nested_controls_but_honor_explicit_empty_lists(): void
    {
        $form = $this->form();
        $form->model()->setRawAttributes(['profile' => ['kind' => 1], 'flags' => ['x']]);
        $form->select('profile->kind')->when(1, fn ($form) => $form->text('profile->extra'));
        $form->multipleSelect('flags')->when('has', 'x', fn ($form) => $form->text('secret'));
        (new \ReflectionProperty($form, 'partialSubmission'))->setValue($form, true);
        $input = ['profile' => ['extra' => 'valid'], 'flags' => [], 'secret' => 'forged'];
        $this->assertSame(['profile' => ['extra' => 'valid'], 'flags' => []], $this->prepare($form, 'prepareUpdate', $input));
    }

    public function test_disabled_controller_uses_saved_state_for_full_posts_and_ignores_forged_values(): void
    {
        $form = $this->form();
        $form->model()->setRawAttributes(['kind' => 1]);
        $form->select('kind')->disable()->when(1, fn ($form) => $form->text('extra')->rules('required'));
        $this->assertArrayHasKey('extra', $form->validationMessages([])->toArray());
        $this->assertArrayHasKey('extra', $form->validationMessages(['kind' => 2])->toArray());
        $this->assertFalse($form->validationMessages(['extra' => 'valid']));
        foreach (['prepareInsert', 'prepareUpdate'] as $method) {
            $this->assertSame(['extra' => 'valid'], $this->prepare($form, $method, ['kind' => 2, 'extra' => 'valid']));
        }
    }

    public function test_disabled_widget_controller_uses_trusted_fill_data_during_validation_and_sanitizing(): void
    {
        $form = new \Encore\Admin\Widgets\Form();
        $form->fill(['kind' => 1]);
        $form->select('kind')->disable()->when(1, fn ($form) => $form->text('document')->rules('required'));
        $this->assertArrayHasKey('document', $form->validate(\Illuminate\Http\Request::create('/admin/form', 'POST', ['kind' => 2]))->toArray());
        $request = \Illuminate\Http\Request::create('/admin/form', 'POST', ['kind' => 2], [], [
            'document' => \Illuminate\Http\UploadedFile::fake()->create('document.txt', 1),
        ]);
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        $this->assertFalse($form->validate($request));
        $form->sanitize();
        $this->assertTrue(request()->hasFile('document'));
        $this->assertFalse(request()->has('kind'), 'trusted fallback controls are not injected into submission data');
    }

    public function test_disabled_new_record_controller_uses_its_server_defined_default(): void
    {
        $form = $this->form();
        $form->radio('kind')->disable()->default(1)->when(1, fn ($form) => $form->text('extra')->rules('required'));
        $this->assertArrayHasKey('extra', $form->validationMessages([])->toArray());
        $this->assertSame(['extra' => 'valid'], $this->prepare($form, 'prepareInsert', ['extra' => 'valid']));
    }

    public function test_omitted_partial_relation_control_uses_normalized_saved_ids_but_explicit_clears_win(): void
    {
        $form = $this->form();
        $form->model()->setRawAttributes(['id' => 1]);
        $role = new class extends Model {};
        $role->setRawAttributes(['id' => 7, 'label' => 'Selected role']);
        $pivot = new \Illuminate\Database\Eloquent\Relations\Pivot();
        $pivot->setRawAttributes(['role_id' => 7]);
        $role->setRelation('pivot', $pivot);
        $form->model()->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([$role]));
        $field = $form->belongsToMany('roles', CascadeSelectable::class);
        // Cached relation key normally resolved from the relation definition.
        (new \ReflectionProperty($field, 'otherKey'))->setValue($field, 'pivot.role_id');
        $field->when('has', 7, fn ($form) => $form->text('extra')->rules('required'));
        (new \ReflectionProperty($form, 'partialSubmission'))->setValue($form, true);
        $this->assertArrayHasKey('extra', $form->validationMessages(['extra' => ''])->toArray());
        $this->assertSame(['extra' => 'valid'], $this->prepare($form, 'prepareUpdate', ['extra' => 'valid']));
        foreach ([[], ['2']] as $roles) {
            $this->assertFalse($form->validationMessages(['roles' => $roles, 'extra' => '']));
            $this->assertSame(['roles' => $roles], $this->prepare($form, 'prepareInsert', ['roles' => $roles, 'extra' => 'forged']));
        }
        $field->disable();
        (new \ReflectionProperty($form, 'partialSubmission'))->setValue($form, false);
        $this->assertArrayHasKey('extra', $form->validationMessages([])->toArray());
        $this->assertSame(['extra' => 'valid'], $this->prepare($form, 'prepareUpdate', ['extra' => 'valid']));
    }

    public function test_enabled_control_omitted_from_a_full_post_is_not_restored_from_the_model(): void
    {
        $form = $this->form();
        $form->model()->setRawAttributes(['kind' => 1]);
        $form->radio('kind')->when(1, fn ($form) => $form->text('extra')->rules('required'));
        $this->assertFalse($form->validationMessages([]));
        $this->assertSame([], $this->prepare($form, 'prepareUpdate', ['extra' => 'inactive']));
    }

    public function test_conditional_validation_rules_use_the_same_trusted_control_values_as_when(): void
    {
        $form = $this->form();
        $form->model()->setRawAttributes(['kind' => 1]);
        $form->select('kind')->disable()->when(1, fn ($form) => $form->text('extra')->rules('required_if:kind,1'));
        foreach ([[], ['kind' => 2]] as $input) {
            $this->assertArrayHasKey('extra', $form->validationMessages($input)->toArray());
        }
        $this->assertSame(['extra' => 'valid'], $this->prepare($form, 'prepareUpdate', ['extra' => 'valid']));
    }
}

class CascadeSelectable extends \Encore\Admin\Grid\Selectable
{
    public function make() {}
}
