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
foreach (['expand', 'modal'] as $type) {
    foreach (['001', 'row&2/#'] as $index => $key) {
        \Encore\Admin\Admin::$script = [];
        \Encore\Admin\Admin::$html = [];
        $token = str_repeat((string) ($index + 1), 48);
        $data = [
            'key' => $key, 'name' => 'preview-'.($index + 1), 'value' => 'Preview',
            'url' => '/admin/_handle_renderable_?renderable=App_Preview&_component_token_='.$token,
            'html' => '', 'async' => true, 'expand' => false, 'loadGrid' => false,
            'elementClass' => 'grid-expand-table', 'title' => 'Preview', 'grid' => false,
        ];
        $html = \Encore\Admin\Admin::component('admin::components.column-'.$type, $data);
        $components[$type.($index + 1)] = [
            'html' => $html, 'script' => implode("\n", \Encore\Admin\Admin::$script),
            'key' => $key, 'token' => $token,
        ];
    }
}

echo json_encode($components, JSON_THROW_ON_ERROR);
