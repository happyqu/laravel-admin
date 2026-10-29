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
$out = [];
function renderTagsFixture($field, $data = [], $old = null)
{
    global $app, $form;
    $app['session']->flush();
    if ($old !== null) $app['session']->flashInput($old);
    $field->setForm($form);
    if ($data) $field->fill($data);
    Encore\Admin\Admin::$script = [];
    $html = $field->render();
    return ['html' => $html, 'scripts' => Encore\Admin\Admin::$script];
}
function tagField() { return new Encore\Admin\Form\Field\Tags('labels', ['Labels']); }
$out['oldNew'] = renderTagsFixture(tagField(), ['labels' => 'saved'], ['labels' => ['new', '']]);
$out['oldCleared'] = renderTagsFixture(tagField()->default(['preset']), ['labels' => 'saved'], ['labels' => ['']]);
$out['defaultArray'] = renderTagsFixture(tagField()->default(['preset']));
$out['defaultString'] = renderTagsFixture(tagField()->default('one,two'));
$out['numericText'] = renderTagsFixture(tagField()->options(['0', '00', '01', '1']), ['labels' => '01']);
$out['duplicateLabels'] = renderTagsFixture(tagField()->pluck('name', 'id')->options([1 => 'Same', 2 => 'Same']),
    ['labels' => [['id' => 1, 'name' => 'Same']]]);
$out['oldPluck'] = renderTagsFixture(tagField()->pluck('name', 'id')->options([1 => 'Same', 2 => 'Same']),
    ['labels' => [['id' => 1, 'name' => 'Same']]], ['labels' => ['2', 'new-key', '']]);
$out['defaultPluck'] = renderTagsFixture(tagField()->pluck('name', 'id')->options([1 => 'One', 2 => 'Two'])->default([2]));
$out['comma'] = renderTagsFixture(tagField()->separators([';'])->options(['ACME,Inc']));
$out['commaPluck'] = renderTagsFixture(tagField()->pluck('name', 'id')->separators([';']));
$out['table'] = renderTagsFixture(new Encore\Admin\Form\Field\Table('rows', ['Rows', function ($row) {
    $row->tags('labels');
}]), ['rows' => [['labels' => 'first'], ['labels' => 'second']]]);
$out['embeds'] = renderTagsFixture(new Encore\Admin\Form\Field\Embeds('profile', ['Profile', function ($nested) {
    $nested->tags('labels');
}]), ['profile' => ['labels' => 'saved']], ['profile' => ['labels' => ['nested-new', '']]]);
echo json_encode($out, JSON_THROW_ON_ERROR);
