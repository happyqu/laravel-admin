<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Encore\Admin\Admin;
use Encore\Admin\Grid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

$app = new \Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new \Illuminate\Config\Repository([
    'app' => ['locale' => 'en', 'url' => 'http://localhost'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
$app->instance('files', new \Illuminate\Filesystem\Filesystem());
foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
    \Illuminate\Routing\RoutingServiceProvider::class, \Illuminate\View\ViewServiceProvider::class] as $provider) {
    $app->register($provider);
}
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');
Facade::setFacadeApplication($app);

function filterFixture(\Closure $configure, string $name = '', array $query = []): array
{
    Admin::$script = [];
    app()->instance('request', Request::create('http://localhost/admin/records', 'GET', $query));
    Facade::clearResolvedInstance('request');
    $filter = (new Grid(new class extends \Illuminate\Database\Eloquent\Model {}))->getFilter();
    $filter->disableIdFilter();
    // Dates configured before the grid name is assigned must still use scoped IDs.
    $configure($filter);
    if ($name !== '') {
        $filter->setName($name);
    }
    $filter->conditions();
    $html = $filter->render();

    return ['html' => $html, 'script' => implode("\n", Admin::$script)];
}

$fixtures['ajax'] = filterFixture(function ($filter) {
    $filter->equal('owner')->select()->ajax('/owners');
});
$fixtures['remoteZero'] = filterFixture(function ($filter) {
    $filter->equal('status')->select('/options');
}, '', ['status' => '0']);

foreach (['first', 'second'] as $name) {
    $fixtures['dates_'.$name] = filterFixture(function ($filter) {
        $filter->date('created_at');
        $filter->between('updated_at')->datetime();
    }, $name);

    $fixtures['group_'.$name] = filterFixture(function ($filter) {
        $filter->group('age', function ($group) { $group->equal('Equals')->gt('Greater'); });
    }, $name, [$name.'_age' => '30', $name.'_age_group' => '1']);
}

foreach (['', 'first', 'second'] as $name) {
    // load() captures its source and target names at configuration time.
    foreach (['single', 'multiple'] as $type) {
        $fixtures['cascade_'.$type.'_'.$name] = filterFixture(function ($filter) use ($name, $type) {
            if ($name !== '') {
                $filter->setName($name);
            }
            if ($type === 'multiple') {
                $filter->in('country')->multipleSelect(['us' => 'US', 'ca' => 'Canada'])->loadMore('city', '/cities');
            } else {
                $filter->equal('country')->select(['us' => 'US', 'ca' => 'Canada'])->load('city', '/cities');
            }
            $filter->equal('city')->select([]);
        });

        $fixtures['cascade_multitarget_'.$type.'_'.$name] = filterFixture(function ($filter) use ($name, $type) {
            if ($name !== '') {
                $filter->setName($name);
            }
            $presenter = $type === 'multiple'
                ? $filter->in('country')->multipleSelect(['us' => 'US', 'ca' => 'Canada'])
                : $filter->equal('country')->select(['us' => 'US', 'ca' => 'Canada']);
            $method = $type === 'multiple' ? 'loadMore' : 'load';
            $presenter->$method('city', '/old-cities');
            $presenter->$method('state', '/states');
            $presenter->$method('city', '/cities');
            $filter->equal('city')->select([]);
            $filter->equal('state')->select([]);
        });
    }
}

echo json_encode($fixtures, JSON_THROW_ON_ERROR);
