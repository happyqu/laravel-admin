<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new \Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new \Illuminate\Config\Repository([
    'app' => ['locale' => 'en', 'url' => 'http://localhost'],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
    'admin' => ['upload' => ['disk' => 'admin']],
    'filesystems' => ['disks' => ['admin' => ['driver' => 'local', 'root' => sys_get_temp_dir()]]],
]));
$app->instance('session', new \Illuminate\Session\Store('fixture', new \Illuminate\Session\ArraySessionHandler(120)));
$app->instance('request', \Illuminate\Http\Request::create('/admin/items'));
$app->instance('files', new \Illuminate\Filesystem\Filesystem());
foreach ([\Illuminate\Translation\TranslationServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
    \Illuminate\Filesystem\FilesystemServiceProvider::class] as $provider) {
    $app->register($provider);
}
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app['view']->addNamespace('admin', dirname(__DIR__, 3).'/resources/views');

$make = static function () {
    return new class extends \Encore\Admin\Actions\Action {
        public function form()
        {
            $this->hidden('record_id')->value(7);
            $this->select('source', 'Source')->options([1 => 'One'])->load('target', '/options');
            $this->multipleSelect('target', 'Target')->options([2 => 'Two']);
            $this->select('readonly', 'Readonly')->options([1 => 'One'])->readonly();
            $this->text('title', 'Title');
            $this->integer('quantity', 'Quantity');
            $this->date('start', 'Start');
            $this->date('end', 'End');
            $this->checkbox('checks', 'Checks')->options([0 => 'Zero', 1 => 'One']);
            $this->radio('choice', 'Choice')->options([0 => 'Zero', 1 => 'One']);
            $this->file('attachment', 'Attachment');
            $this->multipleFile('attachments', 'Attachments');
            $this->image('image', 'Image');
            $this->multipleImage('images', 'Images');
            $this->table('rows', 'Rows', function ($form) {
                $form->select('owner', 'Owner')->options([1 => 'One']);
                $form->text('title', 'Title');
                $form->text('quantity', 'Quantity')->inputmask(['alias' => 'integer']);
            })->value([['title' => 'First', 'owner' => 1], ['title' => 'Second', 'owner' => 1]]);
        }
    };
};
echo json_encode([$make()->loadInteraction(), $make()->loadInteraction()], JSON_THROW_ON_ERROR);
