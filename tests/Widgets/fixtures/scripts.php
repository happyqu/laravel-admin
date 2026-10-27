<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new \Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new \Illuminate\Config\Repository([
    'app' => ['locale' => 'en'], 'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
$app->instance('files', new \Illuminate\Filesystem\Filesystem());
$app->register(\Illuminate\Translation\TranslationServiceProvider::class);
$app->register(\Illuminate\View\ViewServiceProvider::class);
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
\Encore\Admin\Facades\Admin::swap(new \Encore\Admin\Admin());

$output = [];
foreach (['first', 'second'] as $name) {
    \Encore\Admin\Admin::$script = [];
    $tab = new \Encore\Admin\Widgets\Tab();
    $tab->id($name.'-tabs');
    $tab->add('One', 'Content', false, $name.'-one');
    $tab->add('Two', 'Content', false, $name.'-two');
    $output[$name] = ['html' => $tab->render(), 'script' => implode("\n", \Encore\Admin\Admin::$script)];
}

\Encore\Admin\Admin::$script = [];
$form = new class extends \Encore\Admin\Widgets\Form {
    public function emit() {
        $this->attribute('id', 'confirm:form')->confirm('Save?');
        $this->addConfirmScript();
    }
};
$form->emit();
$output['confirm'] = implode("\n", \Encore\Admin\Admin::$script);
echo json_encode($output, JSON_THROW_ON_ERROR);
