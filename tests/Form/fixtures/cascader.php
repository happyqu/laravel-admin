<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = new Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://localhost'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
$app->instance('files', new Illuminate\Filesystem\Filesystem());
$app->instance('session', new Illuminate\Session\Store('cascader', new Illuminate\Session\ArraySessionHandler(120)));
$app->instance('request', Illuminate\Http\Request::create('http://localhost/admin/items'));
foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class, Illuminate\Routing\RoutingServiceProvider::class] as $provider) {
    $app->register($provider);
}
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag());
$app['translator']->addNamespace('admin', dirname(__DIR__, 3).'/resources/lang');
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
Encore\Admin\Facades\Admin::swap(new class extends Encore\Admin\Admin {
    public function user() { return null; }
});
$app['router']->get('admin/_cascader_', Encore\Admin\Controllers\CascaderController::class)->name('admin.cascader');
$app['router']->getRoutes()->refreshNameLookups();
$out = [];
foreach (['empty', 'edit', 'single', 'readonly', 'disabled', 'branch'] as $type) {
    $field = (new Encore\Admin\Form\Field\Cascader($type === 'single' ? 'district_id' : ['province_id', 'city_id', 'district_id'], ['Region']))
        ->source(Encore\Admin\Tests\Cascader\FixtureSource::class);
    if ($type === 'edit' || $type === 'readonly' || $type === 'disabled') $field->value(['0', '1', '2']);
    if ($type === 'single') $field->value('2');
    if ($type === 'readonly') $field->readonly();
    if ($type === 'disabled') $field->disable();
    if ($type === 'branch') $field->checkStrictly()->value(['0', '', '']);
    $out[$type] = (string) $field->render();
}
$field = (new Encore\Admin\Form\Field\Cascader(['province', 'city', 'district'], ['地区']))
    ->source(Encore\Admin\Cascader\RegionSource::class);
$out['china'] = (string) $field->render();
$source = new Encore\Admin\Cascader\RegionSource();
$out['chinaNodes'] = [$source->children([]), $source->children(['浙江省']), $source->children(['浙江省', '杭州市'])];
$out['chinaCitiesWithoutDistricts'] = $source->children(['广东省']);
$out['chinaTree'] = $source->tree();
$out['staticTree'] = (new Encore\Admin\Tests\Cascader\FixtureSource())->tree();
echo json_encode($out, JSON_THROW_ON_ERROR);
