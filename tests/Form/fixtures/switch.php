<?php
require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['locale' => 'en'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
$app->instance('files', new Illuminate\Filesystem\Filesystem());
$app->instance('session', new Illuminate\Session\Store('switch-fields', new Illuminate\Session\ArraySessionHandler(120)));
$request = Illuminate\Http\Request::create('/admin/items', 'POST');
$request->setLaravelSession($app['session']);
$app->instance('request', $request);
$app->register(Illuminate\Translation\TranslationServiceProvider::class);
$app->register(Illuminate\View\ViewServiceProvider::class);
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag());
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$form = new Encore\Admin\Form(new class extends Illuminate\Database\Eloquent\Model {});
$out = [];
foreach (['default', 'custom', 'old', 'disabled'] as $mode) {
    $app['session']->flush();
    $field = (new Encore\Admin\Form\Field\SwitchField('enabled'))->setForm($form);
    if ($mode === 'custom') {
        $field->values('enabled', 'disabled')->texts("It's open\n</script>", '关闭')
            ->colors('success', 'danger')->value('enabled');
    }
    if ($mode === 'old') {
        $field->setElementName('rows[new_1][enabled]')->setErrorKey('rows.new_1.enabled')->value(0);
        $app['session']->flashInput(['rows' => ['new_1' => ['enabled' => 'on']]]);
    }
    if ($mode === 'disabled') {
        $field->value(1)->disable();
    }
    Encore\Admin\Admin::$script = [];
    $out[$mode] = ['html' => $field->render(), 'scripts' => Encore\Admin\Admin::$script];
}
echo json_encode($out, JSON_THROW_ON_ERROR);
