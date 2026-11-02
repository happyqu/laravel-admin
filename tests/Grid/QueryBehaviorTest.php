<?php

namespace Encore\Admin\Tests\Grid;

use Encore\Admin\Grid;
use Encore\Admin\Grid\Model as GridModel;
use Encore\Admin\Grid\Tools\Selector;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
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

    public function test_self_relation_sort_aliases_the_related_side_for_belongs_to_and_has_one(): void
    {
        foreach (['managerByCode' => 'code', 'report' => 'manager_code'] as $relation => $relatedKey) {
            $grid = new Grid(new ReviewChild());
            $column = $grid->{$relation}()->title()->sortable();
            $this->request(['_sort' => ['column' => $column->getName(), 'type' => 'asc']]);
            $query = $grid->model()->getQueryBuilder();
            $alias = 'admin_sort_'.substr(sha1($relation), 0, 12);
            $sql = $query->toSql();
            $parentKey = $relation === 'managerByCode' ? 'manager_code' : 'code';
            $this->assertStringContainsString('left join `children` as `'.$alias.'` on `children`.`'.$parentKey.'` = `'.$alias.'`.`'.$relatedKey.'`', $sql);
            $this->assertStringContainsString('order by `'.$alias.'`.`title` asc', $sql);
            $this->assertStringContainsString('select `children`.*', $sql);
            $this->assertSame($sql, $grid->model()->getQueryBuilder()->toSql());
            $this->assertSame([], $query->getBindings());
        }
    }

    public function test_dynamic_attribute_accessors_are_columns_rather_than_relations(): void
    {
        $grid = new Grid(new ReviewChild());
        $this->assertSame('displayName', $grid->displayName()->getName());
        $this->assertSame('display_name', $grid->display_name()->getName());
    }

    public function test_named_range_filters_render_and_accept_their_own_namespace(): void
    {
        $filter = new Grid\Filter(new GridModel(new ReviewChild()));
        $filter->setName('comments');
        $between = $filter->between('created_at');
        $html = $between->render()->render();
        $this->assertStringContainsString('name="comments_created_at[start]"', $html);
        $this->assertStringContainsString('name="comments_created_at[end]"', $html);
        $this->request(['comments_created_at' => ['start' => '2026-01-01', 'end' => '2026-10-01']]);
        $query = $filter->getModel()->getQueryBuilder();
        $this->assertStringContainsString('`created_at` between ? and ?', $query->toSql());
        $this->assertSame(['2026-01-01', '2026-10-01'], $query->getBindings());
    }

    public function test_grid_links_reject_dangerous_urls_from_values_strings_and_callbacks(): void
    {
        $grid = new Grid(new ReviewChild());
        $column = new Grid\Column('url', 'URL');
        $row = new ReviewChild();
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\nscript:alert(1)", "java\tscript:alert(1)", 'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)'] as $url) {
            $link = new Grid\Displayers\Link($url, $grid, $column, $row);
            $this->assertSame(e($url), $link->display('', '_self'));
            $link = new Grid\Displayers\Link('Click', $grid, $column, $row);
            $this->assertSame('Click', $link->display($url, '_self'));
            $this->assertSame('Click', $link->display(function () use ($url) { return $url; }, '_self'));
        }
        foreach (['https://example.com/?a=1&b=2', 'http://example.com', 'mailto:a@example.com', 'tel:+123', '/admin/users', '../users', '#details', '//example.com'] as $url) {
            $link = new Grid\Displayers\Link('<Click>', $grid, $column, $row);
            $this->assertSame("<a href='".e($url)."' target='_self'>&lt;Click&gt;</a>", $link->display($url, '_self'));
        }
    }

    public function test_repeat_displayer_supports_strings_and_model_bound_closures(): void
    {
        $row = (new ReviewChild())->forceFill(['symbol' => '#']);
        Grid\Column::setOriginalGridModels(collect([$row]));
        foreach (['*', function ($count) { return $this->symbol.$count; }, static fn ($count) => 'n'.$count] as $index => $input) {
            $column = (new Grid\Column('count', 'Count'))->repeat($input, ',');
            $expected = ['*,*,*', '#3,#3,#3', 'n3,n3,n3'][$index];
            $this->assertSame([['count' => $expected]], $column->fill([['count' => 3]]));
        }
        $column = (new Grid\Column('count', 'Count'))->repeat('*');
        $this->assertSame([['count' => '']], $column->fill([['count' => 0]]));
    }

    public function test_display_supports_static_and_model_bound_callbacks_in_order(): void
    {
        $rows = [(new ReviewChild())->forceFill(['title' => 'a', 'id' => 7])];
        Grid\Column::setOriginalGridModels(collect($rows));
        $column = (new Grid(new ReviewChild()))->column('title', 'Title');
        $column->display(static fn ($value) => strtoupper($value));
        $column->display(function ($value, $column) {
            return $this->id.':'.$value.':'.$column->getOriginal();
        });

        $this->assertSame([['title' => '7:A:a']], $column->fill([['title' => 'a']]));
    }

    public function test_display_dynamic_chains_are_isolated_per_row_and_preserve_following_callbacks(): void
    {
        $data = [['title' => 'A'], ['title' => 'B'], ['title' => 'C']];
        Grid\Column::setOriginalGridModels(collect(array_map(
            static fn ($row) => (new ReviewChild())->forceFill($row), $data
        )));
        $column = (new Grid(new ReviewChild()))->column('title', 'Title');
        $column->display(function ($value, $column) {
            if ($this->title === 'B') {
                return $value;
            }

            return $column->prefix('P', '')->suffix('S', '');
        })->display(fn ($value) => '['.$value.']');

        $expected = [['title' => '[PAS]'], ['title' => '[B]'], ['title' => '[PCS]']];
        $this->assertSame($expected, $column->fill($data));
        $this->assertSame($expected, $column->fill($data));
    }

    public function test_display_supports_nested_dynamic_callbacks_and_column_returns_without_callbacks(): void
    {
        Grid\Column::setOriginalGridModels(collect([new ReviewChild()]));
        $column = (new Grid(new ReviewChild()))->column('title', 'Title');
        $column->display(function ($value, $column) {
            return $column->display(function ($value, $column) {
                return $column->prefix('P', '')->suffix('S', '');
            })->suffix('!', '');
        })->display(function ($value, $column) {
            return $column->style('color:red');
        })->display(fn ($value) => '['.$value.']');

        $this->assertSame([['title' => '[PAS!]']], $column->fill([['title' => 'A']]));
    }

    public function test_display_restores_callbacks_after_an_exception(): void
    {
        Grid\Column::setOriginalGridModels(collect([new ReviewChild()]));
        $column = (new Grid(new ReviewChild()))->column('title', 'Title');
        $column->display(function ($value, $column) {
            $column->prefix('P', '');
            if ($value === 'fail') {
                throw new \RuntimeException('Display failed');
            }

            return $column;
        })->display(fn ($value) => '['.$value.']');

        try {
            $column->fill([['title' => 'fail']]);
            $this->fail('Expected the display callback to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Display failed', $exception->getMessage());
        }

        $this->assertSame([['title' => '[PA]']], $column->fill([['title' => 'A']]));
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

    public function test_constructor_builder_runs_once_before_list_query_conditions(): void
    {
        $this->request(['__search__' => 'needle', 'status' => '1']);
        $calls = 0;
        $grid = new Grid(new ReviewChild(), function ($grid) use (&$calls) {
            $calls++;
            $grid->column('title', 'Title');
            $grid->column('status', 'Status')->filter();
            $grid->quickSearch('title');
            $grid->model()->where('tenant_id', 7);
        });

        $grid->applyQuery();
        $grid->applyQuery();

        $this->assertSame(1, $calls);
        $this->assertSame([7, '%needle%', '1'], $grid->model()->getQueryBuilder()->getBindings());
    }

    public function test_rendered_export_runs_builder_and_keeps_its_row_constraints(): void
    {
        $this->request(['_export_' => 'all']);
        Grid\Exporter::reset();
        $calls = 0;
        $grid = new Grid(new ReviewChild(), function ($grid) use (&$calls) {
            $calls++;
            $grid->column('title', 'Title');
            $grid->model()->where('tenant_id', 7);
        });

        try {
            $queries = $this->connection->pretend(function () use ($grid, &$csv) {
                $csv = $this->captureCsv(fn () => $grid->render());
            });
            $this->assertSame("\xEF\xBB\xBFTitle\n", $csv);
            $this->assertSame(1, $calls);
            $this->assertStringContainsString('where `tenant_id` = ', $queries[0]['query']);
            $this->assertSame([7], $queries[0]['bindings']);
        } finally {
            Grid\Exporter::reset();
        }
    }

    public function test_query_export_applies_search_column_and_selector_conditions_once(): void
    {
        $this->request(['__search__' => 'needle', 'status' => '1', '_selector' => ['kind' => '2']]);
        $grid = new Grid(new ReviewChild());
        $grid->model()->where('tenant_id', 7);
        $grid->column('title', 'Title');
        $grid->column('status', 'Status')->filter();
        $grid->quickSearch('title');
        $grid->selector(function ($selector) { $selector->select('kind', 'Kind', [2 => 'Two']); });
        $exporter = new class($grid) extends Grid\Exporters\AbstractExporter {
            public function export() {}
        };

        $query = $exporter->getQuery();
        $this->assertSame([7, '%needle%', '1', '2'], $query->getBindings());
        $this->assertSame($query->toSql(), $exporter->getQuery()->toSql());
        $this->assertSame($query->getBindings(), $exporter->getQuery()->getBindings());
    }

    public function test_csv_respects_hidden_columns_before_building_the_grid(): void
    {
        $grid = new Grid(new ReviewChild());
        $grid->column('title', 'Title');
        $grid->column('secret', 'Secret')->hide();
        $exporter = new class($grid) extends Grid\Exporters\CsvExporter {
            public function chunk(callable $callback, $count = 100)
            {
                $callback(collect([(new ReviewChild())->forceFill(['title' => 'Visible', 'secret' => 'hidden-value'])]));
            }
        };

        $this->assertSame("\xEF\xBB\xBFTitle\nVisible\n", $this->captureCsv(fn () => $exporter->export()));

        // A user can still explicitly select a column that is only hidden by default.
        $this->request(['_columns_' => 'secret']);
        $this->assertSame("\xEF\xBB\xBFSecret\nhidden-value\n", $this->captureCsv(fn () => $exporter->export()));

        $this->request([]);
        $grid->hideColumns(['title']);
        $this->assertSame([], $grid->visibleColumnNames());
        $this->assertTrue($grid->visibleColumns()->isEmpty());
        $this->assertStringNotContainsString('hidden-value', $this->captureCsv(fn () => $exporter->export()));
    }

    public function test_default_values_escape_plain_text_without_double_encoding_renderers(): void
    {
        Grid\Column::setOriginalGridModels(collect([new ReviewChild()]));
        $grid = new Grid(new ReviewChild());
        $value = '<img src=x onerror=alert(1)> & text';
        $column = $grid->column('title', 'Title')->default('-');
        $this->assertSame([['title' => e($value)]], $column->fill([['title' => $value]]));
        $this->assertSame([['title' => '-']], $column->fill([['title' => null]]));
        $column->default('fallback');
        $this->assertSame([['title' => e($value)]], $column->fill([['title' => $value]]));

        $column = $grid->column('label', 'Label')->default('-')->label();
        $this->assertSame([['label' => "<span class='label label-success'>".e($value).'</span>']], $column->fill([['label' => $value]]));

        $column = $grid->column('html', 'HTML')->display(fn ($value) => '<b>'.e($value).'</b>')->default('-');
        $this->assertSame([['html' => '<b>'.e($value).'</b>']], $column->fill([['html' => $value]]));
        $column = $grid->column('fallback', 'Fallback')->default(new \Illuminate\Support\HtmlString('<b>Empty</b>'));
        $result = $column->fill([['fallback' => null]]);
        $this->assertSame('<b>Empty</b>', (string) $result[0]['fallback']);
    }

    public function test_dynamic_defaults_are_escaped_only_after_the_complete_display_chain(): void
    {
        Grid\Column::setOriginalGridModels(collect([new ReviewChild()]));
        $grid = new Grid(new ReviewChild());
        $value = '<img src=x onerror=alert(1)> & text';
        $dynamicDefault = function ($value, $column) {
            return $column->default('-');
        };

        $column = $grid->column('plain')->display($dynamicDefault);
        $this->assertSame([['plain' => e($value)]], $column->fill([['plain' => $value]]));
        $this->assertSame([['plain' => '-']], $column->fill([['plain' => null]]));

        $column = $grid->column('label')->display($dynamicDefault)->label()->default('-');
        $this->assertSame([['label' => "<span class='label label-success'>".e($value).'</span>']], $column->fill([['label' => $value]]));
        $this->assertSame([['label' => "<span class='label label-success'>-</span>"]], $column->fill([['label' => null]]));

        $column = $grid->column('nested')->display(function ($value, $column) use ($dynamicDefault) {
            return $column->display($dynamicDefault);
        })->label();
        $this->assertSame([['nested' => "<span class='label label-success'>".e($value).'</span>']], $column->fill([['nested' => $value]]));

        $column = $grid->column('html')->display(function ($value, $column) {
            return $column->default(new \Illuminate\Support\HtmlString('<b>Empty</b>'));
        });
        $result = $column->fill([['html' => null]]);
        $this->assertSame('<b>Empty</b>', (string) $result[0]['html']);
    }

    public function test_named_grid_pagination_preserves_names_and_explicit_arguments(): void
    {
        foreach ([[], ['comments_per_page' => '30'], ['comments_per_page' => '999']] as $input) {
            $this->request($input);
            $grid = (new Grid(new ReviewChild()))->setName('comments')->paginate(10);
            $this->connection->pretend(function () use ($grid) { $grid->model()->buildData(false); });
            $paginator = $grid->model()->eloquent();
            $this->assertSame('comments_page', $paginator->getPageName());
            $this->assertSame(isset($input['comments_per_page']) && $input['comments_per_page'] === '30' ? 30 : 10, $paginator->perPage());
            $this->assertStringContainsString('comments_page=2', $paginator->url(2));
        }

        $this->request([]);
        $grid = (new Grid(new ReviewChild()))->setName('comments');
        $grid->model()->paginate(15, ['title'], 'custom_page');
        $this->connection->pretend(function () use ($grid) { $grid->model()->buildData(false); });
        $this->assertSame('custom_page', $grid->model()->eloquent()->getPageName());
        $this->assertSame(15, $grid->model()->eloquent()->perPage());
    }

    private function captureCsv(callable $export): string
    {
        try {
            $export();
            $this->fail('Expected a streamed export response.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            ob_start();
            try {
                ($exception->getResponse()->getCallback())();

                return str_replace("\r\n", "\n", ob_get_contents());
            } finally {
                ob_end_clean();
            }
        }
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
    public function managerByCode() { return $this->belongsTo(self::class, 'manager_code', 'code'); }
    public function report() { return $this->hasOne(self::class, 'manager_code', 'code'); }
    protected function displayName(): Attribute { return Attribute::make(get: fn () => 'Display name'); }
}
