<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Encore\Admin\Form\Field\Table;
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

class ReadOnlyFieldsTest extends TestCase
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
        $this->form = new Form(new class extends Model {});
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }



    private function form(string $mode): Form
    {
        $form = new Form(new class extends Model {});
        $form->model()->setRawAttributes(['title' => 'Alpha']);
        $form->text('title')->{$mode}()->rules('required');
        $form->text('slug')->rules('different:title');
        return $form;
    }

    public function test_dependent_validation_uses_stored_readonly_and_disabled_values(): void
    {
        foreach (['readonly', 'disable'] as $mode) {
            $form = $this->form($mode);
            foreach ([['title' => 'Forged', 'slug' => 'Alpha'], ['slug' => 'Alpha']] as $input) {
                $this->assertArrayHasKey('slug', $form->validationMessages($input)->toArray());
            }
            $this->assertFalse($form->validationMessages(['title' => 'Beta', 'slug' => 'Beta']));
            foreach (['prepareInsert', 'prepareUpdate'] as $method) {
                $prepared = (new \ReflectionMethod($form, $method))->invoke($form, ['title' => 'Forged', 'slug' => 'Beta']);
                $this->assertSame(['slug' => 'Beta'], $prepared);
            }
        }
    }

    public function test_new_fields_use_server_defaults_and_existing_nulls_remain_null(): void
    {
        $form = new Form(new class extends Model {});
        $form->text('kind')->disable()->default('required');
        $form->text('extra')->rules('required_if:kind,required');
        $this->assertArrayHasKey('extra', $form->validationMessages(['kind' => 'optional'])->toArray());
        $form->model()->setRawAttributes(['kind' => null]);
        $this->assertFalse($form->validationMessages(['kind' => 'required']));
    }

    public function test_widget_validation_uses_fill_data_but_sanitizing_does_not_submit_protected_values(): void
    {
        foreach (['readonly', 'disable'] as $mode) {
            $form = new \Encore\Admin\Widgets\Form();
            $form->fill(['title' => 'Alpha']);
            $form->text('title')->{$mode}();
            $form->text('slug')->rules('different:title');
            $bad = Request::create('/form', 'POST', ['title' => 'Forged', 'slug' => 'Alpha']);
            $this->assertArrayHasKey('slug', $form->validate($bad)->toArray());
            $good = Request::create('/form', 'POST', ['title' => 'Forged', 'slug' => 'Beta']);
            $this->assertFalse($form->validate($good));
            app()->instance('request', $good);
            Facade::clearResolvedInstance('request');
            $form->sanitize();
            $this->assertSame(['slug' => 'Beta'], request()->all());
        }
    }

    public function test_embeds_and_table_use_each_records_original_values(): void
    {
        foreach (['readonly', 'disable'] as $mode) {
            $builder = function ($row) use ($mode) {
                $row->text('title')->{$mode}();
                $row->text('slug')->rules('different:title');
            };
            $table = (new Table('rows', ['Rows', $builder]))->setForm($this->form);
            $table->setOriginal(['rows' => [['title' => 'Alpha'], ['title' => 'Beta']]]);
            $input = ['rows' => [
                ['title' => 'Forged', 'slug' => 'Alpha'], ['title' => 'Forged', 'slug' => 'Beta'],
            ]];
            $validator = $table->getValidator($input);
            $this->assertFalse($validator->passes());
            $this->assertTrue($validator->errors()->has('rows.0.slug'));
            $this->assertTrue($validator->errors()->has('rows.1.slug'));
            $this->assertTrue($table->getValidator(['rows' => [['slug' => 'Beta'], ['slug' => 'Alpha']]])->passes());
            $embedded = (new Form\Field\Embeds('profile', ['Profile', $builder]))->setForm($this->form);
            $embedded->setOriginal(['profile' => ['title' => 'Alpha']]);
            $this->assertFalse($embedded->getValidator(['profile' => ['slug' => 'Alpha']])->passes());
            $this->assertTrue($embedded->getValidator(['profile' => ['title' => 'Forged', 'slug' => 'Beta']])->passes());
        }
    }

    public function test_multicolumn_and_dotted_protected_values_use_their_own_paths(): void
    {
        $form = new Form(new class extends Model {});
        $form->model()->setRawAttributes(['profile' => ['kind' => 'required'], 'start' => '2026-01-01', 'end' => '2026-01-03']);
        $form->text('profile.kind')->readonly();
        $form->dateRange('start', 'end')->disable();
        $form->text('extra')->rules('required_if:profile.kind,required');
        $form->date('chosen')->rules('after:start|before:end');
        $errors = $form->validationMessages(['profile' => ['kind' => 'optional'], 'start' => '2025-01-01', 'end' => '2027-01-01', 'chosen' => '2026-02-01']);
        $this->assertArrayHasKey('extra', $errors->toArray());
        $this->assertArrayHasKey('chosen', $errors->toArray());
    }

    public function test_has_many_dependencies_use_the_original_child_selected_by_its_id(): void
    {
        $field = (new class('rows', ['Rows', function ($row) {
            $row->text('title')->readonly();
            $row->text('slug')->rules('different:title');
        }]) extends Form\Field\HasMany {
            protected function getKeyName() { return 'id'; }
        })->setForm($this->form);
        $field->setOriginal(['rows' => [['id' => 7, 'title' => 'Alpha'], ['id' => 8, 'title' => 'Beta']]]);
        $validator = $field->getValidator(['rows' => [
            ['id' => 8, 'title' => 'Forged', 'slug' => 'Beta'],
            ['id' => 7, 'title' => 'Forged', 'slug' => 'Alpha'],
        ]]);
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('rows.0.slug'));
        $this->assertTrue($validator->errors()->has('rows.1.slug'));
        $this->assertTrue($field->getValidator(['rows' => [['id' => 7, 'slug' => 'Beta']]])->passes());
    }

    public function test_an_active_writable_definition_can_share_a_protected_column(): void
    {
        $form = new Form(new class extends Model {});
        $form->model()->setRawAttributes(['kind' => 2, 'title' => 'Original']);
        $form->radio('kind')->when(1, function ($form) { $form->text('title')->readonly(); })
            ->when(2, function ($form) { $form->text('title'); });
        $form->text('slug')->rules('different:title');
        $this->assertFalse($form->validationMessages(['kind' => 2, 'title' => 'Edited', 'slug' => 'Original']));
        $this->assertArrayHasKey('slug', $form->validationMessages(['kind' => 2, 'title' => 'Edited', 'slug' => 'Edited'])->toArray());
    }
}
