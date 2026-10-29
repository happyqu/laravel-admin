<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Encore\Admin\Form\Field\HasMany;
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

class HasManyComponentTest extends TestCase
{
    private $previousContainer;
    private Form $form;
    private $previousResolver;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new \Illuminate\Database\ConnectionResolver(['hasmany' => new HasManyMemoryConnection(static fn () => null, 'hasmany')]);
        $resolver->setDefaultConnection('hasmany');
        Model::setConnectionResolver($resolver);
        HasManyChild::$saved = HasManyChild::$deleted = [];
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
        $this->form = new class((new HasManyParent())->forceFill(['id' => 1])) extends Form {
            public function persistRelations($input) { $this->updateRelation($input); }
        };
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    private function hasMany(): HasMany
    {
        $field = $this->form->hasMany('children', function ($row) { $row->text('title')->rules('required'); });
        $field->setOriginal(['children' => HasManyChild::ROWS]);
        return $field;
    }

    public function test_empty_has_many_submission_keeps_existing_children_with_either_deletion_setting(): void
    {
        $field = $this->hasMany();
        $this->form->persistRelations(['children' => []]);
        $field->disableDelete();
        $this->form->persistRelations(['children' => []]);
        $this->assertSame([], HasManyChild::$saved);
        $this->assertSame([], HasManyChild::$deleted);
    }

    public function test_empty_multiple_select_still_detaches_related_records(): void
    {
        $this->form->multipleSelect('children');
        $this->form->persistRelations(['children' => []]);
        $this->assertSame([7, 8], array_column(HasManyChild::$saved, 'id'));
        $this->assertSame([null, null], array_column(HasManyChild::$saved, 'parent_id'));
    }

    public function test_explicit_removal_still_deletes_only_the_requested_child(): void
    {
        $this->hasMany();
        $this->form->persistRelations(['children' => [7 => ['id' => 7, '_remove_' => 1]]]);
        $this->assertSame([7], HasManyChild::$deleted);
        $this->assertSame([], HasManyChild::$saved);
    }

    public function test_forbidden_removal_of_an_existing_child_is_rejected(): void
    {
        $this->hasMany()->disableDelete();
        $this->expectException(ValidationException::class);
        $this->form->persistRelations(['children' => [7 => ['id' => 7, '_remove_' => 1]]]);
    }

    public function test_canceling_an_unsaved_row_does_not_count_as_creating_or_deleting_a_record(): void
    {
        $field = $this->hasMany()->disableDelete()->disableCreate();
        $this->assertSame([], $field->prepare(['new_1' => ['id' => '', '_remove_' => 1]]));
        $this->form->persistRelations(['children' => ['new_1' => ['id' => '', '_remove_' => 1]]]);
        $this->assertSame([], HasManyChild::$saved);
        $this->assertSame([], HasManyChild::$deleted);
    }

    public function test_container_rules_count_active_rows_and_retain_sibling_context(): void
    {
        $field = $this->hasMany()->rules('required|array|min:1');
        $deleted = [['id' => 7, '_remove_' => 1], ['id' => 8, '_remove_' => 1]];
        $validator = $field->getValidator(['children' => $deleted]);
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('children'));
        $this->assertTrue($field->getValidator(['children' => [
            ['id' => 7, '_remove_' => 1], ['id' => 8, 'title' => 'Remaining'],
        ]])->passes());
        $field->rules('required_if:enabled,1|array|min:1');
        $this->assertFalse($field->getValidator(['enabled' => 1, 'children' => $deleted])->passes());
    }

    public function test_old_input_keeps_each_submission_key_before_child_fields_are_built(): void
    {
        $field = $this->hasMany();
        request()->session()->flashInput(['children' => [
            'new_1' => ['id' => '', 'title' => 'One', '_remove_' => 0],
            'new_2' => ['id' => '', 'title' => 'Two', '_remove_' => 0],
        ]]);
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
        foreach (['default', 'table', 'tab'] as $mode) {
            $html = $field->mode($mode)->render();
            $this->assertStringContainsString('name="children[new_1][title]"', $html);
            $this->assertStringContainsString('name="children[new_2][title]"', $html);
            $this->assertStringNotContainsString('name="children[0][title]"', $html);
        }
    }
}

class HasManyParent extends Model
{
    public function children() { return $this->hasMany(HasManyChild::class, 'parent_id'); }
}

class HasManyChild extends Model
{
    public const ROWS = [
        ['id' => 7, 'parent_id' => 1, 'title' => 'First'],
        ['id' => 8, 'parent_id' => 1, 'title' => 'Second'],
    ];
    public static array $saved = [];
    public static array $deleted = [];
    protected $guarded = [];
    public function save(array $options = []) { static::$saved[] = $this->getAttributes(); return true; }
    public function delete() { static::$deleted[] = $this->getKey(); return true; }
}

class HasManyMemoryConnection extends \Illuminate\Database\MySqlConnection
{
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return str_contains($query, '0 = 1') ? [] : array_map(static fn ($row) => (object) $row, HasManyChild::ROWS);
    }
}
