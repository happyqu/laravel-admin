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
$resolver = new Illuminate\Database\ConnectionResolver([
    'fixture' => new Illuminate\Database\MySqlConnection(static fn () => null, 'fixture'),
]);
$resolver->setDefaultConnection('fixture');
Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
class HasManyFixtureParent extends Illuminate\Database\Eloquent\Model
{
    public function children() { return $this->hasMany(HasManyFixtureChild::class, 'parent_id'); }
}
class HasManyFixtureChild extends Illuminate\Database\Eloquent\Model {}
Encore\Admin\Form::extend('editor', Encore\Admin\Form\Field\Editor::class);
$stored = [['id' => 7, 'title' => 'First', 'body' => 'One'], ['id' => 8, 'title' => 'Second', 'body' => 'Two']];
$old = [
    'new_1' => ['id' => '', 'title' => 'Unsaved one', 'body' => 'One', '_remove_' => 0],
    'new_2' => ['id' => '', 'title' => 'Unsaved two', 'body' => 'Two', '_remove_' => 0],
];
$out = [];
foreach (['default', 'table', 'tab'] as $mode) {
    foreach (['saved', 'old', 'disabled', 'oldDisabled'] as $state) {
        $app['session']->flush();
        if ($state === 'old' || $state === 'oldDisabled') {
            $app['session']->flashInput(['children' => $old]);
        }
        $form = new Encore\Admin\Form((new HasManyFixtureParent())->forceFill(['id' => 1]));
        $field = $form->hasMany('children', function ($row) {
            $row->text('title')->rules('required');
            $row->editor('body');
            $row->select('country')->options(['1' => 'Country'])->load('city', '/api/cities');
            $row->select('city');
        })->mode($mode);
        $field->fill(['children' => $stored]);
        if ($state === 'disabled' || $state === 'oldDisabled') $field->disableDelete();
        Encore\Admin\Admin::$script = [];
        $html = $field->render();
        $out[$mode.'_'.$state] = ['html' => $html, 'scripts' => Encore\Admin\Admin::$script];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
