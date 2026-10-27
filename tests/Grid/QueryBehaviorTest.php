<?php

namespace Encore\Admin\Tests\Grid;

use Encore\Admin\Grid;
use Encore\Admin\Grid\Model as GridModel;
use Encore\Admin\Grid\Tools\Selector;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class QueryBehaviorTest extends TestCase
{
    private $previousContainer;
    private $previousResolver;
    private MySqlConnection $connection;

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
        $app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $app->register(\Illuminate\View\ViewServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->request([]);
        $this->connection = new MySqlConnection(static fn () => null, 'review');
        $resolver = new ConnectionResolver(['review' => $this->connection]);
        $resolver->setDefaultConnection('review');
        Model::setConnectionResolver($resolver);
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

    private function request(array $query): void
    {
        app()->instance('request', Request::create('http://localhost/admin/records', 'GET', $query));
        Facade::clearResolvedInstance('request');
    }

    public function test_relation_queries_and_chunks_keep_the_parent_and_grid_filters(): void
    {
        $parent = new ReviewParent();
        $parent->id = 7;
        $relation = $parent->children();
        $model = (new GridModel(new ReviewChild()))->setRelation($relation);
        $model->where('active', 1);
        $query = $model->getQueryBuilder();
        $this->assertStringContainsString('`children`.`parent_id` = ?', $query->toSql());
        $this->assertStringContainsString('`active` = ?', $query->toSql());
        $this->assertSame([7, 1], $query->getBindings());
        $this->assertSame([7], $relation->getQuery()->getBindings());
        $model->usePaginate(false);
        $queries = $this->connection->pretend(function () use ($model) { $model->chunk(function () {}); });
        $this->assertSame([7, 1], $queries[0]['bindings']);
    }

    public function test_cached_data_can_be_requested_as_both_array_and_collection(): void
    {
        $model = new class(new ReviewChild()) extends GridModel {
            public int $loads = 0;
            protected function get() { $this->loads++; return collect([['id' => 1]]); }
        };
        $this->assertSame([['id' => 1]], $model->buildData());
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $model->buildData(false));
        $this->assertSame(1, $model->loads);
    }

    public function test_selectors_clear_values_and_do_not_reuse_a_previous_request(): void
    {
        $this->request(['_selector' => ['status' => '0,1'], 'other' => 'kept']);
        $this->assertSame(['status' => ['0', '1']], Selector::parseSelected());
        parse_str(parse_url(Selector::url('status'), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('_selector', $query);
        $this->assertSame('kept', $query['other']);
        $this->request(['_selector' => ['status' => ['bad'], 'type' => 'new']]);
        $this->assertSame(['type' => ['new']], Selector::parseSelected());
    }

    public function test_quicksearch_handles_zero_and_unknown_columns(): void
    {
        $grid = new class(new ReviewChild()) extends Grid {
            public function search() { $this->applyQuickSearch(); }
        };
        $grid->column('code', 'Code');
        $grid->quickSearch('code');
        $this->request(['__search__' => '0']);
        $grid->search();
        $this->assertSame(['%0%'], $grid->model()->getQueryBuilder()->getBindings());

        $grid = new class(new ReviewChild()) extends Grid {
            public function search() { $this->applyQuickSearch(); }
        };
        $grid->column('code', 'Code');
        $this->request(['__search__' => 'unknown:x code:0']);
        $grid->search();
        $this->assertSame(['0'], $grid->model()->getQueryBuilder()->getBindings());
    }

    public function test_ignored_in_filters_add_no_condition(): void
    {
        foreach (['In', 'NotIn', 'Like', 'Gt', 'Lt', 'NotEqual', 'Hidden'] as $type) {
            $class = 'Encore\\Admin\\Grid\\Filter\\'.$type;
            $this->assertNull((new $class('status', 'Status'))->ignore()->condition(['status' => '1']));
        }
    }

    public function test_named_filters_remove_only_the_leading_namespace(): void
    {
        $filter = new Grid\Filter(new GridModel(new ReviewChild()));
        $filter->setName('tenant');
        $filter->equal('tenant_id', 'Tenant');
        $this->request(['tenant_tenant_id' => '7']);
        $this->assertContains(['where' => ['tenant_id', '7']], $filter->conditions());
    }

    public function test_rebuilding_a_sorted_query_does_not_duplicate_raw_ordering(): void
    {
        $grid = new Grid(new ReviewChild());
        $grid->registerSortableColumn('id', 'SIGNED');
        $this->request(['_sort' => ['column' => 'id', 'type' => 'asc']]);
        $first = $grid->model()->getQueryBuilder()->toSql();
        $this->assertSame($first, $grid->model()->getQueryBuilder()->toSql());
        $this->assertSame(1, substr_count($first, 'CAST('));
    }

    public function test_empty_csv_still_has_column_headings(): void
    {
        $exporter = new class extends Grid\Exporters\CsvExporter {
            protected function getVisiableTitles() { return ['Title']; }
            public function chunk(callable $callback, $count = 100) { return true; }
        };
        $exporter->filename('empty');
        try {
            $exporter->export();
            $this->fail('Expected a streamed export.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            ob_start();
            ($exception->getResponse()->getCallback())();
            $csv = ob_get_clean();
        }
        $this->assertSame("\xEF\xBB\xBFTitle\n", str_replace("\r\n", "\n", $csv));
    }

    public function test_range_filters_keep_zero_and_ignore_unknown_bounds(): void
    {
        $filter = new Grid\Column\RangeFilter('number');
        $filter->setParent(new Grid\Column('price', 'Price'));
        $model = new GridModel(new ReviewChild());
        $filter->addBinding(['start' => '0', 'end' => '10', 'unexpected' => 'bad'], $model);
        $query = $model->getQueryBuilder();
        $this->assertStringContainsString('between ? and ?', $query->toSql());
        $this->assertSame(['0', '10'], $query->getBindings());
        $input = new Grid\Column\InputFilter('equal');
        $input->setParent(new Grid\Column('price', 'Price'));
        $model = new GridModel(new ReviewChild());
        $input->addBinding('0', $model);
        $this->assertSame(['0'], $model->getQueryBuilder()->getBindings());
        $this->assertSame(['where' => ['code', 'like', '%0%']], (new Grid\Filter\Like('code', 'Code'))->condition(['code' => '0']));
    }

    public function test_relation_sort_supports_array_eager_loads_and_custom_owner_keys(): void
    {
        $grid = new Grid(new ReviewChild());
        $grid->registerSortableColumn('parentByCode.title');
        $grid->model()->with(['parentByCode' => function ($query) {}]);
        $this->request(['_sort' => ['column' => 'parentByCode.title', 'type' => 'asc']]);
        $sql = $grid->model()->getQueryBuilder()->toSql();
        $this->assertStringContainsString('left join `parents` on `children`.`parent_code` = `parents`.`code`', $sql);
        $this->assertStringContainsString('order by `parents`.`title` asc', $sql);
        $this->assertSame($sql, $grid->model()->getQueryBuilder()->toSql());
    }

    public function test_editable_attributes_preserve_quotes_and_ampersands(): void
    {
        $grid = new Grid(new ReviewChild());
        $grid->setResource('/admin/records');
        $row = new ReviewChild();
        $row->id = 7;
        $value = 'A&B "quoted"';
        $html = (new Grid\Displayers\Editable($value, $grid, new Grid\Column('title', 'Title'), $row))->display();
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        $this->assertSame($value, $dom->getElementsByTagName('a')->item(0)->getAttribute('data-value'));
        \Encore\Admin\Admin::$script = [];
    }

    public function test_invalid_export_scopes_return_a_client_error(): void
    {
        $exporter = new Grid\Exporters\CsvExporter(new Grid(new ReviewChild()));
        foreach (['invalid', 'page:0', 'page:abc', []] as $scope) {
            try {
                $exporter->withScope($scope);
                $this->fail('Expected an invalid scope to be rejected.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(400, $exception->getStatusCode());
            }
        }
    }
}

class ReviewParent extends Model
{
    protected $table = 'parents';
    public function children() { return $this->hasMany(ReviewChild::class, 'parent_id'); }
}

class ReviewChild extends Model
{
    protected $table = 'children';
    public function parentByCode() { return $this->belongsTo(ReviewParent::class, 'parent_code', 'code'); }
}
