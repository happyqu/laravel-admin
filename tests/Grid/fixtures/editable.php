<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new \Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new \Illuminate\Config\Repository(['app' => ['locale' => 'en']]));
$app->instance('request', \Illuminate\Http\Request::create('/admin/records'));
$app->instance('files', new \Illuminate\Filesystem\Filesystem());
$app->register(\Illuminate\Translation\TranslationServiceProvider::class);

$components = [];
foreach (['title', 'first', 'second', 'dynamic'] as $name) {
    \Encore\Admin\Admin::$script = [];
    $row = new class extends \Illuminate\Database\Eloquent\Model {};
    $row->id = 7;
    $grid = (new \Encore\Admin\Grid($row))->setResource('/admin/'.$name);
    $columnName = $name === 'title' ? 'title' : 'status';
    $column = $grid->column($columnName, $columnName);
    if ($name === 'title') {
        $column->editable();
    } elseif ($name === 'dynamic') {
        $column->editable('select', function ($row) { return [1 => 'Row '.$row->id]; });
    } else {
        $column->editable('select', [1 => $name.' label']);
    }
    $rows = collect([$row, (clone $row)->forceFill(['id' => 8])]);
    \Encore\Admin\Grid\Column::setOriginalGridModels($rows);
    $data = $column->fill([
        [$columnName => $name === 'title' ? 'old' : 1],
        [$columnName => $name === 'title' ? 'other' : 1],
    ]);
    $components[$name] = [
        'html' => implode("\n", array_column($data, $columnName)),
        'script' => implode("\n", array_unique(\Encore\Admin\Admin::$script)),
    ];
}

echo json_encode($components, JSON_THROW_ON_ERROR);
