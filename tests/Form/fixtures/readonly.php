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


$app['request']->setLaravelSession($app['session']);

trait ReadonlyFixturePickerSetup
{
    public function setupPicker($multiple = false)
    {
        $this->picker = new class('unused', '', $multiple, ';') extends Encore\Admin\Form\Field\ValuePicker {
            protected function getLoadUrl() { return '/picker'; }
        };
        return $this;
    }
}
class ReadonlyFixtureText extends Encore\Admin\Form\Field\Text { use ReadonlyFixturePickerSetup; }
class ReadonlyFixtureTextarea extends Encore\Admin\Form\Field\Textarea { use ReadonlyFixturePickerSetup; }
class ReadonlyFixtureFile extends Encore\Admin\Form\Field\File
{
    use ReadonlyFixturePickerSetup;
    protected function initStorage() {}
    public function objectUrl($path) { return '/files/'.$path; }
}
$out = [];
foreach (['text', 'many', 'file', 'textarea'] as $type) {
    foreach (['normal', 'readonly', 'disable'] as $mode) {
        $class = $type === 'file' ? ReadonlyFixtureFile::class : ($type === 'textarea' ? ReadonlyFixtureTextarea::class : ReadonlyFixtureText::class);
        $field = (new $class('asset'))->setForm($form)->setupPicker($type === 'many')->value($type === 'many' ? 'original;other' : 'original');
        if ($type === 'textarea') $field->setView('admin::form.textarea');
        if ($mode !== 'normal') $field->{$mode}();
        Encore\Admin\Admin::$script = Encore\Admin\Admin::$html = [];
        $html = $field->render();
        $out[$type.'_'.$mode] = ['html' => $html, 'scripts' => Encore\Admin\Admin::$script, 'modals' => Encore\Admin\Admin::$html];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
