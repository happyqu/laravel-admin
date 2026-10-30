<?php

namespace Encore\Admin\Tests\Cascader;

use Encore\Admin\Admin;
use Encore\Admin\Cascader\Component;
use Encore\Admin\Cascader\Source;
use Encore\Admin\Controllers\CascaderController;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Cascader;
use Encore\Admin\Grid;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class CascaderTest extends TestCase
{
    private $previous;
    private $resolver;
    private object $user;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->resolver = Model::getConnectionResolver();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'admin' => ['route' => ['prefix' => 'admin'], 'check_route_permission' => false],
        ]));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->instance('session', new \Illuminate\Session\Store('cascader', new \Illuminate\Session\ArraySessionHandler(120)));
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
            \Illuminate\Validation\ValidationServiceProvider::class, \Illuminate\Routing\RoutingServiceProvider::class,
            \Illuminate\View\ViewServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $app['view']->share('errors', new \Illuminate\Support\ViewErrorBag());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $provider = new class($app) extends \Encore\Admin\Providers\AdminCoreServiceProvider {
            public function translations(): void { $this->registerTranslations(); }
        };
        $provider->translations();
        $this->user = new class {
            public $id = '1';
            public function getAuthIdentifier() { return $this->id; }
            public function isRole($role) { return true; }
        };
        $admin = $this->createMock(Admin::class);
        $admin->method('user')->willReturn($this->user);
        \Encore\Admin\Facades\Admin::swap($admin);
        $app['router']->get('admin/items', fn () => null);
        $app['router']->get('admin/_cascader_', CascaderController::class)->name('admin.cascader');
        $app['router']->getRoutes()->refreshNameLookups();
        $connectionResolver = new ConnectionResolver(['test' => new MySqlConnection(static fn () => null, 'test')]);
        $connectionResolver->setDefaultConnection('test');
        Model::setConnectionResolver($connectionResolver);
        $this->request([]);
    }

    protected function tearDown(): void
    {
        Admin::$script = Admin::$css = Admin::$js = Admin::$html = [];
        $this->resolver ? Model::setConnectionResolver($this->resolver) : Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function request(array $values, string $method = 'GET', string $url = 'http://localhost/admin/items'): Request
    {
        $request = Request::create($url, $method, $values, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session'));
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');

        return $request;
    }

    public function test_source_preserves_zero_and_checks_complete_parent_path_and_disabled_ancestors(): void
    {
        $this->assertSame('Please select', trans('admin::cascader.choose'));
        $this->assertSame('请选择', trans('admin::cascader.choose', [], 'zh-CN'));
        $source = Source::make(FixtureSource::class);
        $this->assertSame(['0', '1', '2'], array_column($source->validate(['0', '1', '2']), 'value'));
        $this->assertSame(['0', '1', '2'], array_column($source->validate('2'), 'value'));
        $this->assertSame(['0'], array_column($source->validate(['0', '', null], false), 'value'));
        foreach ([['0', '1', '4'], ['3', '1', '2'], ['0', '', '2'], ['5', '6'], ['0'], [['bad']]] as $path) {
            try {
                $source->validate($path);
                $this->fail('Accepted invalid path '.json_encode($path));
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertSame([], Source::make(FixtureSource::class, ['empty' => true])->children([])['items']);
    }

    public function test_form_validates_before_insert_update_and_nested_save_and_restores_old_input(): void
    {
        $this->request([], 'POST');
        $field = (new Cascader(['province_id', 'city_id', 'district_id'], ['Region']))->source(FixtureSource::class);
        $valid = ['province_id' => '0', 'city_id' => '1', 'district_id' => '2'];
        $this->assertTrue($field->getValidator($valid)->passes());
        $this->assertFalse($field->getValidator($valid + ['other' => 'x'])->fails());
        $this->assertTrue($field->getValidator(['province_id' => '3', 'city_id' => '1', 'district_id' => '2'])->fails());
        $this->assertTrue($field->getValidator(['province_id' => '3'])->fails());
        $this->assertTrue($field->getValidator(['province_id' => '', 'city_id' => '', 'district_id' => ''])->passes());
        $this->assertTrue($field->rules('required')->getValidator(['province_id' => '', 'city_id' => '', 'district_id' => ''])->fails());
        $single = (new Cascader('district_id', ['Region']))->source(FixtureSource::class);
        $this->assertTrue($single->getValidator(['district_id' => ['0', '1', '2']])->fails());
        $this->assertTrue($single->getValidator(['district_id' => '2'])->passes());

        $form = new class(new class extends Model {}) extends Form {
            public function insertValues($values) { return $this->prepareInsert($values); }
            public function updateValues($values) { return $this->prepareUpdate($values); }
        };
        $form->cascader(array_keys($valid), 'Region')->source(FixtureSource::class);
        $this->assertSame($valid, $form->insertValues($valid));
        $this->assertSame($valid, $form->updateValues($valid));
        $this->assertSame(array_fill_keys(array_keys($valid), null), $form->insertValues(array_fill_keys(array_keys($valid), '')));

        $nested = new \Encore\Admin\Form\NestedForm('rows');
        $nested->cascader(array_keys($valid), 'Region')->source(FixtureSource::class);
        $prepared = $nested->prepare(['new_1' => $valid], true);
        $this->assertSame('0', $prepared['new_1']['province_id']);
        $validator = validator([], []);
        \Encore\Admin\Form\NestedValidation::append($validator, $nested->fields(), ['province_id' => '3', 'city_id' => '1', 'district_id' => '2'], 'rows.new_1');
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('rows.new_1.province_id'));

        $field = (new Cascader(array_keys($valid), ['Region']))->source(FixtureSource::class)->value(['3', '1', '4']);
        request()->session()->flashInput($valid);
        $html = (string) $field->render();
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);
        $this->assertSame('0', $xpath->query('//input[@name="province_id"]')->item(0)->getAttribute('value'));
        $this->assertStringNotContainsString('select2', $field->getScript());
    }

    public function test_named_filter_adds_parent_conditions_and_resets_all_columns(): void
    {
        $filter = (new Grid(new class extends Model {}))->getFilter();
        $filter->disableIdFilter();
        $field = $filter->cascader(['province_id', 'city_id', 'district_id'], 'Region')->source(FixtureSource::class);
        $filter->setName('first');
        $this->request(['first_province_id' => '0', 'first_city_id' => '1', 'first_district_id' => '']);
        $conditions = $filter->conditions();
        $this->assertCount(1, $conditions);
        $query = (new class extends Model {})->newQuery();
        $conditions[0]['where'][0]($query);
        $this->assertSame(['0', '1'], $query->getBindings());
        $html = $field->render()->render();
        $this->assertStringContainsString('name="first_province_id" value="0"', $html);
        $this->assertStringNotContainsString('first_province_id', $filter->urlWithoutFilters());
        $this->assertTrue(json_decode(html_entity_decode(explode('"', explode('data-admin-cascader="', $html)[1])[0]), true)['multiField']);
    }

    public function test_builtin_regions_submit_save_restore_and_filter_chinese_names(): void
    {
        $values = ['province' => '浙江省', 'city' => '杭州市', 'district' => '西湖区'];
        $source = \Encore\Admin\Cascader\RegionSource::class;
        $form = new class(new class extends Model {}) extends Form {
            public function insertValues($values) { return $this->prepareInsert($values); }
            public function updateValues($values) { return $this->prepareUpdate($values); }
        };
        $field = $form->cascader(array_keys($values), '地区')->source($source)->rules('required');
        $this->assertTrue($field->getValidator($values)->passes());
        $this->assertSame($values, $form->insertValues($values));
        $this->assertSame($values, $form->updateValues($values));
        // Some cities have no district level; keep that field empty rather than inventing a name.
        $partial = (new Cascader(array_keys($values), ['地区']))->source($source);
        $leaf = ['province' => '广东省', 'city' => '东莞市', 'district' => null];
        $this->assertTrue($partial->getValidator($leaf)->passes());
        $field->fill($values);
        $html = (string) $field->render();
        $this->assertStringContainsString('name="province" value="浙江省"', $html);
        $config = Component::config($source, [], true, 3, true, false, null);
        parse_str(parse_url($config['url'], PHP_URL_QUERY), $query);
        $request = $this->request($query + ['operation' => 'resolve', 'path' => array_values($values)], 'GET', 'http://localhost/admin/_cascader_');
        $this->assertSame(array_values($values), array_column((new CascaderController())($request)->getData(true)['items'], 'value'));

        $filter = (new Grid(new class extends Model {}))->getFilter();
        $filter->disableIdFilter();
        $filter->cascader(array_keys($values), '地区')->source($source);
        $filter->setName('orders');
        $this->request(['orders_province' => '浙江省', 'orders_city' => '杭州市', 'orders_district' => '西湖区']);
        $conditions = $filter->conditions();
        $builder = (new class extends Model {})->newQuery();
        $conditions[0]['where'][0]($builder);
        $this->assertSame(array_values($values), $builder->getBindings());
    }

    public function test_named_belongs_to_region_filter_queries_the_relation_and_resets_its_fields(): void
    {
        $values = ['province' => '浙江省', 'city' => '杭州市', 'district' => '西湖区'];
        $filter = (new Grid(new CascaderShipment()))->getFilter();
        $filter->disableIdFilter();
        $field = $filter->cascader(['address.province', 'address.city', 'address.district'], '地区')
            ->source(\Encore\Admin\Cascader\RegionSource::class);
        $filter->setName('orders');
        $this->request(['orders_address' => $values, 'unrelated' => 'keep']);
        $conditions = $filter->conditions();
        $query = (new CascaderShipment())->newQuery();
        $conditions[0]['where'][0]($query);
        $sql = $query->toSql();
        $this->assertStringContainsString('exists (select * from `cascader_addresses`', $sql);
        $this->assertStringContainsString('`cascader_shipments`.`address_id` = `cascader_addresses`.`id`', $sql);
        $this->assertStringContainsString('`province` = ? and `city` = ? and `district` = ?', $sql);
        $this->assertStringNotContainsString('`address`.`province`', $sql);
        $this->assertSame(array_values($values), $query->getBindings());
        $this->assertSame(1, substr_count($sql, 'exists ('));
        $this->assertStringContainsString('name="orders_address[province]" value="浙江省"', $field->render()->render());
        parse_str(parse_url($filter->urlWithoutFilters(), PHP_URL_QUERY) ?? '', $remaining);
        $this->assertSame(['unrelated' => 'keep'], $remaining);
    }

    public function test_has_many_cascade_conditions_must_match_one_related_record(): void
    {
        $filter = (new Grid(new CascaderShipment()))->getFilter();
        $field = $filter->cascader(['addresses.province', 'addresses.city', 'addresses.district'], '地区')
            ->source(\Encore\Admin\Cascader\RegionSource::class);
        $condition = $field->condition(['addresses' => ['province' => '浙江省', 'city' => '杭州市', 'district' => '西湖区']]);
        $query = (new CascaderShipment())->newQuery();
        $condition['where'][0]($query);
        $sql = $query->toSql();
        $this->assertSame(1, substr_count($sql, 'exists ('));
        $this->assertStringContainsString('`cascader_shipments`.`id` = `cascader_addresses`.`shipment_id`', $sql);
        $this->assertStringContainsString('`province` = ? and `city` = ? and `district` = ?', $sql);
        $this->assertSame(['浙江省', '杭州市', '西湖区'], $query->getBindings());
    }

    public function test_cascades_support_nested_relations_and_mixed_local_and_related_columns(): void
    {
        $filter = (new Grid(new CascaderShipment()))->getFilter();
        $field = $filter->cascader(['province_id', 'address.region.city_id', 'address.region.district_id'], '地区')
            ->source(FixtureSource::class);
        $condition = $field->condition(['province_id' => '0', 'address' => ['region' => ['city_id' => '1', 'district_id' => '2']]]);
        $query = (new CascaderShipment())->newQuery();
        $condition['where'][0]($query);
        $sql = $query->toSql();
        $this->assertStringContainsString('where `province_id` = ? and exists (', $sql);
        $this->assertStringContainsString('exists (select * from `cascader_addresses`', $sql);
        $this->assertStringContainsString('exists (select * from `cascader_regions`', $sql);
        $this->assertStringContainsString('`city_id` = ? and `district_id` = ?', $sql);
        $this->assertSame(['0', '1', '2'], $query->getBindings());
    }

    public function test_endpoint_checks_ticket_identity_context_and_returns_nodes(): void
    {
        $config = Component::config(FixtureSource::class, [], true, 3, true, false, null);
        parse_str(parse_url($config['url'], PHP_URL_QUERY), $query);
        $request = $this->request($query + ['operation' => 'children', 'path' => ['0']], 'GET', 'http://localhost/admin/_cascader_');
        $result = (new CascaderController())($request)->getData(true);
        $this->assertSame('<b>City</b>', $result['items'][0]['label']);
        $request = $this->request($query + ['operation' => 'resolve', 'path' => ['0', '1', '2']], 'GET', 'http://localhost/admin/_cascader_');
        $this->assertCount(3, (new CascaderController())($request)->getData(true)['items']);
        $request = $this->request($query + ['operation' => 'resolve', 'path' => ['3', '1', '2']], 'GET', 'http://localhost/admin/_cascader_');
        $this->assertSame(422, (new CascaderController())($request)->getStatusCode());
        $query['settings'] = json_encode(['context' => ['empty' => true], 'depth' => 3, 'leafOnly' => true]);
        $request = $this->request($query + ['operation' => 'children'], 'GET', 'http://localhost/admin/_cascader_');
        try {
            (new CascaderController())($request);
            $this->fail('Accepted forged context.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->user->id = 'other';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new CascaderController())($request);
    }
}

class CascaderShipment extends Model
{
    protected $table = 'cascader_shipments';

    public function address() { return $this->belongsTo(CascaderAddress::class, 'address_id'); }
    public function addresses() { return $this->hasMany(CascaderAddress::class, 'shipment_id'); }
}

class CascaderAddress extends Model
{
    protected $table = 'cascader_addresses';

    public function region() { return $this->belongsTo(CascaderRegion::class, 'region_id'); }
}

class CascaderRegion extends Model
{
    protected $table = 'cascader_regions';
}
