<?php
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = new Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['locale' => 'en'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
$app->instance('files', new Illuminate\Filesystem\Filesystem());
$app->instance('session', new Illuminate\Session\Store('json-fields', new Illuminate\Session\ArraySessionHandler(120)));
$app->instance('request', Illuminate\Http\Request::create('/admin/items', 'POST'));
$app->register(Illuminate\Translation\TranslationServiceProvider::class);
$app->register(Illuminate\Validation\ValidationServiceProvider::class);
$app->register(Illuminate\View\ViewServiceProvider::class);
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag());
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$form = new Encore\Admin\Form(new class extends Illuminate\Database\Eloquent\Model {});

$out = [];
function fixture($field, $data)
{
    global $form;
    $field->setForm($form)->fill($data);
    Encore\Admin\Admin::$script = [];
    Encore\Admin\Admin::$html = [];
    $html = $field->render();
    return ['html' => $html, 'scripts' => Encore\Admin\Admin::$script, 'modals' => Encore\Admin\Admin::$html];
}
$out['table'] = fixture(new Encore\Admin\Form\Field\Table('rows', ['Rows', function ($f) {
    $f->text('title');
}]), ['rows' => ['new_1' => ['title' => 'Saved']]]);
$out['nested'] = fixture(new Encore\Admin\Form\Field\Table('parents', ['Parents', function ($f) {
    $f->table('children', function ($g) {
        $g->embeds('details', function ($h) {
            $h->date('day');
            $h->dateRange('starts_at', 'ends_at');
            $h->list('tags');
        });
    });
}]), ['parents' => [
    ['children' => [['details' => ['day' => '2026-10-29', 'tags' => ['one']]]]],
    ['children' => [['details' => ['day' => '2026-11-10', 'tags' => ['two']]]]],
]]);
$out['embeds'] = fixture(new Encore\Admin\Form\Field\Embeds('profile', ['Profile', function ($f) {
    $f->embeds('nested', function ($g) {
        $g->date('day');
        $g->dateRange('starts_at', 'ends_at');
    });
}]), ['profile' => ['nested' => ['day' => '2026-10-29']]]);

$out['scopedEmbeds'] = fixture((new Encore\Admin\Form\Field\Embeds('profile', ['Profile', function ($f) {
    $f->embeds('nested', function ($g) {
        $g->date('day');
        $g->dateRange('starts_at', 'ends_at');
    });
}]))->scopeElement('action-modal'), ['profile' => ['nested' => ['day' => '2026-11-10']]]);
foreach (['default', 'table', 'tab'] as $mode) {
    $field = new class('records', ['Records', function ($f) {
        $f->table('children', function ($g) {
            $g->date('day');
            $g->dateRange('starts_at', 'ends_at');
            $g->select('province')->options(['1' => 'Province'])->load('city', '/api/cities');
            $g->select('city');
        });
    }]) extends Encore\Admin\Form\Field\HasMany {
        protected function getKeyName() { return 'id'; }
        protected function buildRelatedForms() { return []; }
    };
    $out['relation_'.$mode] = fixture($field->mode($mode), []);
}
$out['selectLoad'] = fixture(new Encore\Admin\Form\Field\Table('locations', ['Locations', function ($f) {
    $f->select('province')->options(['1' => 'Province'])->load('city', '/api/cities');
    $f->select('city');
    $f->select('region')->options(['1' => 'Region'])->loads(['district', 'town'], ['/api/districts', '/api/towns']);
    $f->select('district');
    $f->select('town');
}]), ['locations' => [['province' => '1', 'region' => '1']]]);

class JsonFixturePicker extends Encore\Admin\Form\Field\Text
{
    public function setupPicker()
    {
        $this->picker = new class('unused') extends Encore\Admin\Form\Field\ValuePicker {
            protected function getLoadUrl() { return '/picker'; }
        };
        return $this;
    }
}
Encore\Admin\Form::extend('jsonFixturePicker', JsonFixturePicker::class);
foreach (['page', 'action'] as $context) {
    $field = new Encore\Admin\Form\Field\Embeds('profile', ['Profile', function ($f) {
        $f->jsonFixturePicker('asset')->setupPicker();
    }]);
    if ($context === 'action') {
        $field->scopeElement('action-modal');
    }
    $out['picker_'.$context] = fixture($field, []);
}

echo json_encode($out, JSON_THROW_ON_ERROR);
