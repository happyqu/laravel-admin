<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['locale' => 'en'], 'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
$app->instance('session', new Illuminate\Session\Store('choices', new Illuminate\Session\ArraySessionHandler(120)));
$request = Illuminate\Http\Request::create('/admin/items');
$request->setLaravelSession($app['session']);
$app->instance('request', $request);
$app->instance('files', new Illuminate\Filesystem\Filesystem());
$app->register(Illuminate\Translation\TranslationServiceProvider::class);
$app->register(Illuminate\View\ViewServiceProvider::class);
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag());
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
Encore\Admin\Facades\Admin::swap(new Encore\Admin\Admin());

$out = [];
foreach (['select', 'checkbox', 'radio', 'radioButton', 'radioCard', 'checkboxButton', 'checkboxCard', 'multipleSelect', 'listbox'] as $type) {
    $multiple = in_array($type, ['checkbox', 'checkboxButton', 'checkboxCard', 'multipleSelect', 'listbox']);
    foreach (['normal', 'readonly', 'nested', 'zero', 'checked', 'old', 'clear', 'callback'] as $mode) {
        if ($mode === 'zero' && !str_starts_with($type, 'checkbox')) continue;
        if ($mode === 'checked' && !str_starts_with($type, 'radio')) continue;
        if (in_array($mode, ['old', 'clear', 'callback']) && !in_array($type, ['select', 'multipleSelect', 'listbox'])) continue;
        $app['session']->forget('_old_input');
        Encore\Admin\Admin::$script = [];
        $form = new Encore\Admin\Form(new class extends Illuminate\Database\Eloquent\Model {});
        $field = $form->{$type}('choice')->options([0 => 'Zero', 1 => 'One', 2 => 'Two']);
        if ($mode === 'checked') $field->checked(2);
        else $field->value($multiple ? [1] : 1);
        if ($type === 'checkbox' && in_array($mode, ['normal', 'readonly'])) $field->canCheckAll();
        if ($mode === 'readonly') $field->readonly()->options(in_array($type, ['select', 'multipleSelect', 'listbox']) ? '/api/options' : [0 => 'Zero', 1 => 'One', 2 => 'Two']);
        if ($mode === 'zero') $field->value(['0']);
        if ($mode === 'nested') {
            $app['session']->flashInput(['choice' => $multiple ? [2] : 2, 'rows' => ['new_1' => ['choice' => $multiple ? [1] : 1]]]);
            $field->setElementName('rows[new_1][choice]');
        }
        if (in_array($mode, ['old', 'clear', 'callback'])) {
            $app['session']->flashInput(['choice' => $mode === 'clear' ? ($multiple ? [''] : '') : ($multiple ? [2] : 2)]);
            if ($mode === 'callback') $field->options(fn ($value) => array_intersect_key([0 => 'Zero', 1 => 'One', 2 => 'Two'], array_flip((array) $value)));
            else $field->options('/api/options');
        }
        $out[$type.'_'.$mode] = ['html' => $field->render(), 'scripts' => Encore\Admin\Admin::$script];
    }
}
foreach (['load', 'loads'] as $method) {
    Encore\Admin\Admin::$script = [];
    $field = new Encore\Admin\Form\Field\Select('country', ['Country']);
    if ($method === 'load') $field->load('city', '/api/cities');
    else $field->loads(['city', 'district'], ['/api/cities?active=1', '/api/districts']);
    $out[$method] = Encore\Admin\Admin::$script;
}
echo json_encode($out, JSON_THROW_ON_ERROR);
