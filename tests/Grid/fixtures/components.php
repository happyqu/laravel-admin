<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new \Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new \Illuminate\Config\Repository([
    'app' => ['locale' => 'en'], 'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
$app->instance('request', \Illuminate\Http\Request::create('/admin/records'));
$app->instance('files', new \Illuminate\Filesystem\Filesystem());
$app->register(\Illuminate\Translation\TranslationServiceProvider::class);
$app->register(\Illuminate\View\ViewServiceProvider::class);
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');

$components = [];
foreach (['input', 'radio', 'multiple-select', 'upload'] as $type) {
    \Encore\Admin\Admin::$script = [];
    \Encore\Admin\Admin::$html = [];
    $data = [
        'trigger' => 'trigger-'.$type, 'target' => 'target-'.$type, 'name' => 'field_'.$type,
        'key' => 7, 'value' => $type === 'multiple-select' ? '["a"]' : 'old', 'display' => 'Old',
        'resource' => '/admin/records', 'options' => ['a' => 'A', 'b' => '<b>B</b>'],
        'mask' => '', 'multiple' => true,
    ];
    $html = \Encore\Admin\Admin::component('admin::grid.inline-edit.'.$type, $data);
    $components[$type] = [
        'html' => $html.implode("\n", \Encore\Admin\Admin::$html),
        'script' => implode("\n", \Encore\Admin\Admin::$script),
    ];
}
echo json_encode($components, JSON_THROW_ON_ERROR);
