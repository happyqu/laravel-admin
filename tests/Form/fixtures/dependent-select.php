<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Encore\Admin\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class DependentSelectCity extends Model
{
    protected $table = 'cities';
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;

    protected static function booted(): void
    {
        static::addGlobalScope('visible', fn (Builder $builder) => $builder->where('visible', true));
    }
}

$connection = new class(static fn () => null, 'test') extends MySqlConnection {
    public array $queries = [];

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->queries[] = [$query, $bindings];
        $ids = array_slice($bindings, 1, -1);
        $rows = [
            ['code' => 'a', 'parent_id' => '0', 'name' => 'Default A', 'city_name' => '<b>Alpha</b>', 'visible' => true],
            ['code' => 'b', 'parent_id' => '0', 'name' => 'Default B', 'city_name' => 'Beta', 'visible' => true],
            ['code' => 'c', 'parent_id' => '1', 'name' => 'Default C', 'city_name' => 'Gamma', 'visible' => true],
            ['code' => 'd', 'parent_id' => '0', 'name' => 'Hidden', 'city_name' => 'Hidden', 'visible' => false],
        ];

        return array_values(array_filter($rows, static fn ($row) => $row['parent_id'] === (string) $bindings[0]
            && $row['visible'] && (!$ids || in_array($row['code'], $ids, true))));
    }
};
$resolver = new ConnectionResolver(['test' => $connection]);
$resolver->setDefaultConnection('test');
Model::setConnectionResolver($resolver);

$app = new Application(dirname(__DIR__, 3));
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['locale' => 'en', 'url' => 'http://localhost'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
    'admin' => ['route' => ['prefix' => 'admin', 'middleware' => []], 'log_viewer' => ['enabled' => false]],
]));
$app->instance('files', new Illuminate\Filesystem\Filesystem());
$app->instance('session', new Illuminate\Session\Store('dependent-select', new Illuminate\Session\ArraySessionHandler(120)));
foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\Validation\ValidationServiceProvider::class, Illuminate\Routing\RoutingServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class] as $provider) {
    $app->register($provider);
}
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag());
Facade::setFacadeApplication($app);
$admin = new class extends Admin {
    public function user()
    {
        return new class {
            public function getAuthIdentifier() { return '1'; }
            public function isRole($role) { return $role === 'administrator'; }
        };
    }
};
Encore\Admin\Facades\Admin::swap($admin);
$admin->routes();
$app['router']->get('admin/records', fn () => 'Records');
$app['router']->getRoutes()->refreshNameLookups();

function requestFor(string $url = 'http://localhost/admin/records', array $parameters = []): Request
{
    $request = Request::create($url, 'GET', $parameters, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session'));
    app()->instance('request', $request);
    Facade::clearResolvedInstance('request');

    return $request;
}

function capture(callable $render): array
{
    global $connection;
    $connection->queries = Admin::$script = Admin::$js = [];
    $html = $render();

    return ['html' => $html, 'scripts' => Admin::$script, 'assets' => Admin::$js, 'queries' => $connection->queries];
}

function renderForm(?string $province, ?string $city, bool $useDefaults = false, array|string $provinces = [0 => 'First', 1 => 'Second'], ?array $allowedParents = null): string
{
    $form = new Form(new class extends Model {});
    $parent = $form->select($useDefaults ? 'parent_id' : 'province_id')->options($provinces);
    $child = $form->select('city_id')->config('minimumResultsForSearch', 99);
    if ($useDefaults) {
        $child->optionsBy('parent_id', DependentSelectCity::class);
    } else {
        $child->optionsBy('province_id', DependentSelectCity::class, 'parent_id', 'city_name', $allowedParents);
    }

    return '<form><div class="fields-group">'.$parent->default($province)->render().$child->default($city)->render().'</div></form>';
}

/** Dependent options declared inside embeds() resolve the parent from the same embed. */
function renderEmbeddedForm(?string $province, ?string $city): string
{
    $form = new Form(new class extends Model {});
    $form->embeds('address', function ($field) use ($province, $city) {
        $field->select('province_id')->options([0 => 'First', 1 => 'Second'])->default($province);
        $field->select('city_id')->optionsBy('province_id', DependentSelectCity::class, 'parent_id', 'city_name')->default($city);
    });

    return '<form data-admin-form>'.$form->render().'</form>';
}

function renderFilter(string $name, array $params, array|string|Closure $provinces = [0 => 'First', 1 => 'Second'], ?array $allowedParents = null): array
{
    requestFor(parameters: $params);

    return capture(function () use ($name, $provinces, $allowedParents) {
        $filter = (new Grid(new class extends Model {}))->getFilter()->disableIdFilter();
        $filter->column(6, fn ($filter) => $filter->equal('province_id')->select($provinces));
        $filter->column(6, fn ($filter) => $filter->equal('city_id')->select()
            ->optionsBy('province_id', DependentSelectCity::class, 'parent_id', 'city_name', $allowedParents));
        $filter->setName($name);
        $filter->conditions();

        return $filter->render();
    });
}

function queryOptions(string $url, array $parameters): array
{
    global $connection;
    $connection->queries = [];
    try {
        $response = app('router')->dispatch(requestFor($url, $parameters));
        $result = ['status' => $response->getStatusCode(), 'items' => $response->getData(true)];
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        $result = ['status' => $exception->getStatusCode()];
    } catch (Illuminate\Validation\ValidationException $exception) {
        $result = ['status' => $exception->status];
    }

    return $result + ['queries' => $connection->queries];
}

function optionConfig(array $fixture): array
{
    preg_match('/data-admin-select-options-by="([^"]+)"/', $fixture['html'], $match);

    return json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR) ?? [];
}

function optionUrl(array $fixture): string
{
    return optionConfig($fixture)['url'];
}

requestFor();
$out = [
    'fresh' => capture(fn () => renderForm(null, null)),
    'edit' => capture(fn () => renderForm('0', 'b')),
    'mismatch' => capture(fn () => renderForm('1', 'b')),
    'defaults' => capture(fn () => renderForm('0', 'a', true)),
    'restrictedForm' => capture(fn () => renderForm('1', 'c', provinces: [0 => 'First'])),
    'asyncForm' => capture(fn () => renderForm('0', 'b', provinces: '/api/provinces', allowedParents: [0, 1])),
    'embeddedForm' => capture(fn () => renderEmbeddedForm('0', 'b')),
    // Parent options arrive over AJAX, so no parent ID is queryable server-side yet.
    'unscopedForm' => capture(fn () => renderForm('0', 'b', provinces: '/api/provinces')),
    'firstFilter' => renderFilter('first', ['first_province_id' => '0', 'first_city_id' => 'b']),
    'secondFilter' => renderFilter('second', []),
    'restrictedFilter' => renderFilter('restricted', ['restricted_province_id' => '1', 'restricted_city_id' => 'c'], fn () => [0 => 'First']),
    'asyncFilter' => renderFilter('async', ['async_province_id' => '0', 'async_city_id' => 'b'], '/api/provinces', [0, 1]),
];
requestFor();
$app['session']->flashInput(['province_id' => '1', 'city_id' => 'c']);
$out['oldInput'] = capture(fn () => renderForm('0', 'b'));
$app['session']->forget('_old_input');

$out['embeddedConfig'] = optionConfig($out['embeddedForm']);
$out['unscopedConfig'] = optionConfig($out['unscopedForm']);
$out['unscopedHtml'] = $out['unscopedForm']['html'];
$out['unscopedQueries'] = $out['unscopedForm']['queries'];
// Keep the endpoint probe optional so a missing URL fails an assertion instead of this fixture.
$out['embeddedEndpoint'] = [];
if (is_string($out['embeddedConfig']['url'] ?? null)) {
    $out['embeddedEndpoint'] = [
        'first' => queryOptions($out['embeddedConfig']['url'], ['parent' => '0']),
        'second' => queryOptions($out['embeddedConfig']['url'], ['parent' => '1']),
        'outside' => queryOptions($out['embeddedConfig']['url'], ['parent' => '2']),
    ];
}

try {
    new Encore\Admin\Select\DependentOptions('province_id', [0 => 'First', 1 => 'Second'], 'parent_id', 'name', null);
    $out['flatGroups'] = 'accepted';
} catch (InvalidArgumentException $exception) {
    $out['flatGroups'] = $exception->getMessage();
}

$url = optionUrl($out['fresh']);
$out['endpoint'] = [
    'first' => queryOptions($url, ['parent' => '0']),
    'second' => queryOptions($url, ['parent' => '1']),
    'tampered' => queryOptions($url, ['parent' => '0', 'settings' => '["invalid","parent_id","name"]']),
    'invalidParent' => queryOptions($url, ['parent' => ['0']]),
    'missingTicket' => queryOptions(strtok($url, '?'), ['parent' => '0']),
    'outsideFormScope' => queryOptions(optionUrl($out['restrictedForm']), ['parent' => '1']),
    'outsideFilterScope' => queryOptions(optionUrl($out['restrictedFilter']), ['parent' => '1']),
];

echo json_encode($out, JSON_THROW_ON_ERROR);
