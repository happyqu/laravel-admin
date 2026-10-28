<?php

namespace Encore\Admin\Tests\Grid;

use Encore\Admin\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Support\Html;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class FilterBehaviorTest extends TestCase
{
    private $previousContainer;
    private $previousResolver;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        ]));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
            \Illuminate\Routing\RoutingServiceProvider::class, \Illuminate\View\ViewServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $resolver = new ConnectionResolver(['review' => new MySqlConnection(static fn () => null, 'review')]);
        $resolver->setDefaultConnection('review');
        Model::setConnectionResolver($resolver);
        $this->request([]);
        Admin::$script = [];
    }

    protected function tearDown(): void
    {
        Admin::$script = [];
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    private function request(array $query): void
    {
        app()->instance('request', Request::create('http://localhost/admin/records', 'GET', $query));
        Facade::clearResolvedInstance('request');
    }

    private function filter(string $name = ''): Grid\Filter
    {
        $filter = (new Grid(new FilterRecord()))->getFilter();
        $filter->disableIdFilter();
        if ($name !== '') {
            $filter->setName($name);
        }

        return $filter;
    }

    public function test_column_filters_escape_values_and_reject_nested_input_shapes(): void
    {
        $payload = '"><img src=x onerror="alert(1)">';
        $this->request(['title' => $payload, 'score' => ['start' => $payload, 'end' => '0']]);
        $input = new Grid\Column\InputFilter('equal');
        $input->setParent(new Grid\Column('title', 'Title'));
        $range = new Grid\Column\RangeFilter('number');
        $range->setParent(new Grid\Column('score', 'Score'));
        foreach ([$input, $range] as $filter) {
            $dom = Html::document($filter->render());
            $this->assertSame(0, $dom->getElementsByTagName('img')->length);
            $this->assertSame($payload, $dom->getElementsByTagName('input')->item(0)->getAttribute('value'));
        }
        $this->request(['title' => ['invalid'], 'score' => ['start' => ['invalid'], 'end' => '0']]);
        $this->assertSame('', Html::first($input->render(), 'input')->getAttribute('value'));
        $dom = Html::document($range->render());
        $this->assertSame('', $dom->getElementsByTagName('input')->item(0)->getAttribute('value'));
        $this->assertSame('0', $dom->getElementsByTagName('input')->item(1)->getAttribute('value'));
        $this->assertNotSame($dom->getElementsByTagName('input')->item(0)->getAttribute('class'), $dom->getElementsByTagName('input')->item(1)->getAttribute('class'));
    }

    public function test_range_bindings_have_fixed_order_and_ignore_unknown_or_nested_bounds(): void
    {
        foreach ([['end' => '20', 'start' => '0'], ['junk' => '999', 'end' => '20', 'start' => '0']] as $bounds) {
            $this->request(['score' => $bounds]);
            $filter = $this->filter();
            $filter->between('score');
            $query = $filter->getModel()->getQueryBuilder();
            $this->assertStringContainsString('`score` between ? and ?', $query->toSql());
            $this->assertSame(['0', '20'], $query->getBindings());
            $column = new Grid\Column\RangeFilter('number');
            $column->setParent(new Grid\Column('score', 'Score'));
            $model = new Grid\Model(new FilterRecord());
            $column->addBinding($bounds, $model);
            $this->assertSame(['0', '20'], $model->getQueryBuilder()->getBindings());
        }
        $between = new Grid\Filter\Between('score');
        $this->assertNull($between->condition(['score' => ['junk' => 999, 'start' => ['bad']]]));
        $this->assertSame(['where' => ['score', '<=', '0']], $between->condition(['score' => ['start' => ['bad'], 'end' => '0']]));
        $between->condition(['score' => ['start' => 1, 'end' => 2]]);
        $this->assertSame(['where' => ['score', '>=', 1]], $between->condition(['score' => ['start' => 1]]));
    }

    public function test_relation_ranges_keep_the_query_method_when_conditions_are_reused(): void
    {
        $between = new Grid\Filter\Between('children.score');
        $both = $between->condition(['children' => ['score' => ['end' => 20, 'start' => 0]]]);
        $start = $between->condition(['children' => ['score' => ['start' => 0]]]);
        $model = new Grid\Model(new FilterRecord());
        $model->addConditions([$both, $start]);
        $query = $model->getQueryBuilder();
        $this->assertStringContainsString('`score` between ? and ?', $query->toSql());
        $this->assertStringContainsString('`score` >= ?', $query->toSql());
        $this->assertSame([0, 20, 0], $query->getBindings());
    }

    public function test_groups_keep_selected_operators_for_text_datetime_and_named_grids(): void
    {
        foreach (['', 'children'] as $name) {
            foreach ([false, true] as $datetime) {
                $prefix = $name ? $name.'_' : '';
                $this->request([$prefix.'age' => '30', $prefix.'age_group' => '1']);
                $filter = $this->filter($name);
                $group = $filter->group('age', function ($group) { $group->equal('Equals')->gt('Greater'); });
                if ($datetime) {
                    $group->datetime();
                }
                $query = $filter->getModel()->getQueryBuilder();
                $this->assertStringContainsString('`age` > ?', $query->toSql());
                $this->assertSame(['30'], $query->getBindings());
                $dom = Html::document($group->render()->render());
                $hidden = $dom->getElementsByTagName('input')->item(0);
                $this->assertSame($prefix.'age_group', $hidden->getAttribute('name'));
                $this->assertSame('1', $hidden->getAttribute('value'));
                $this->assertSame('Greater', Html::firstNode($dom, '.'.$group->getId().'-filter-group-label')->textContent);
                $group->condition(['age' => '40', 'age_group' => '1']);
                $this->assertCount(2, $group->group);
                $this->assertSame(['where' => ['age', '>', '40']], $group->condition(['age' => '40', 'age_group' => '1']));
            }
        }
    }

    public function test_model_options_load_id_lists_sparse_lists_and_zero(): void
    {
        foreach ([[1, 2], [4 => 1, 8 => 2], ['0']] as $ids) {
            $this->request(['owners' => $ids]);
            $filter = $this->filter();
            $field = $filter->in('owners');
            $field->multipleSelect()->model(FilterOption::class);
            $filter->conditions();
            $dom = Html::document($field->render()->render());
            $selected = [];
            foreach ($dom->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    $selected[] = $option->getAttribute('value');
                }
            }
            $this->assertSame(array_values(array_map('strval', $ids)), $selected);
        }
        $this->request(['status' => '0']);
        $filter = $this->filter();
        $field = $filter->equal('status')->default(1);
        $field->select()->model(FilterOption::class);
        $filter->conditions();
        $html = $field->render()->render();
        $this->assertStringContainsString('Option 0', $html);
        $this->assertStringNotContainsString('Option 1', $html);
    }

    public function test_layout_only_values_use_the_grid_namespace_and_preserve_defaults(): void
    {
        foreach (['', 'children'] as $name) {
            $prefix = $name ? $name.'_' : '';
            $filter = $this->filter();
            $field = $filter->layoutOnly()->equal('age')->default('10');
            if ($name) {
                $filter->setName($name);
            }
            $this->request(['unrelated' => '1']);
            $this->assertSame([], $filter->conditions());
            $this->assertSame('10', Html::first($field->render()->render(), 'input')->getAttribute('value'));
            $this->request([$prefix.'age' => '0']);
            $this->assertSame([], $filter->conditions());
            $this->assertSame('0', $field->getValue());
            $this->assertSame('0', Html::first($field->render()->render(), 'input')->getAttribute('value'));
        }
    }

    public function test_date_ids_are_namespaced_even_if_the_grid_is_named_after_configuration(): void
    {
        $ids = [];
        foreach (['first', 'second'] as $name) {
            $filter = $this->filter();
            $date = $filter->date('created_at');
            $range = $filter->between('updated_at')->datetime();
            $filter->setName($name);
            $html = $date->render()->render().$range->render()->render();
            foreach (Html::document($html)->getElementsByTagName('input') as $input) {
                $ids[] = $input->getAttribute('id');
            }
        }
        $this->assertSame(['first_created_at', 'first_updated_at_start', 'first_updated_at_end',
            'second_created_at', 'second_updated_at_start', 'second_updated_at_end'], $ids);
    }
}

class FilterRecord extends Model
{
    protected $table = 'records';
    public function children() { return $this->hasMany(FilterChild::class, 'record_id'); }
}

class FilterChild extends Model
{
    protected $table = 'children';
}

class FilterOption extends Model
{
    public static function find($ids)
    {
        return collect($ids)->map(static fn ($id) => (new static())->forceFill(['id' => $id, 'name' => 'Option '.$id]));
    }
}
