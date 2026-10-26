<?php

/**
 * Minimal Testbench host used by the Playwright host smoke test.
 *
 * Run with:
 *   php -S 127.0.0.1:4175 tests/browser/host-server.php
 */

require __DIR__.'/../../vendor/autoload.php';

class BrowserFakeUser implements Illuminate\Contracts\Auth\Authenticatable
{
    public $id = 1;
    public $username = 'admin';
    public $name = 'Admin';
    public $email = 'admin@example.com';
    public $avatar = '/vendor/laravel-admin/adminlte4/img/user2-160x160.jpg';
    public $created_at = '2026-10-26 12:00:00';

    public function getAuthIdentifierName() { return 'id'; }
    public function getAuthIdentifier() { return $this->id; }
    public function getAuthPassword() { return 'secret'; }
    public function getAuthPasswordName() { return 'password'; }
    public function getRememberToken() { return null; }
    public function setRememberToken($value) {}
    public function getRememberTokenName() { return null; }

    public function visible($roles = []): bool { return true; }
    public function can($permission): bool { return true; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
        ];
    }
}

class BrowserFakeMenu
{
    public function toTree(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Dashboard',
                'icon' => 'fa-dashboard',
                'uri' => 'dashboard',
                'roles' => [],
                'permission' => null,
                'children' => [],
            ],
            [
                'id' => 2,
                'title' => 'Users',
                'icon' => 'fa-users',
                'uri' => 'users',
                'roles' => [],
                'permission' => null,
                'children' => [
                    [
                        'id' => 3,
                        'title' => 'All users',
                        'icon' => 'fa-circle-o',
                        'uri' => 'users',
                        'roles' => [],
                        'permission' => null,
                        'children' => [],
                    ],
                ],
            ],
        ];
    }
}

class BrowserDemoModel extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'browser_demos';
    protected $guarded = [];
    public $timestamps = false;
}

function browserDemoTable(): void
{
    if (Illuminate\Support\Facades\Schema::hasTable('browser_demos')) {
        return;
    }

    Illuminate\Support\Facades\Schema::create('browser_demos', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('status')->nullable();
        $table->boolean('active')->default(1);
        $table->date('date')->nullable();
    });

    BrowserDemoModel::insert([
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active', 'active' => 1, 'date' => '2026-10-26'],
        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'disabled', 'active' => 0, 'date' => '2026-10-25'],
        ['id' => 3, 'name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'active', 'active' => 1, 'date' => '2026-10-24'],
    ]);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isNavigation = $path === '/admin/nav';
$isIframe = $path === '/admin/iframe' || $path === '/admin/dashboard';

if ($path === '/favicon.ico') {
    http_response_code(204);
    return;
}

$assets = dirname(__DIR__, 2).'/resources/assets';
$prefix = '/vendor/laravel-admin/';

if (str_starts_with($path, $prefix)) {
    $relative = rawurldecode(substr($path, strlen($prefix)));
    $file = realpath($assets.'/'.$relative);

    if ($file === false || !str_starts_with($file, realpath($assets).DIRECTORY_SEPARATOR) || !is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    header('Content-Type: '.($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return;
}

if (!in_array($path, ['/login', '/admin', '/admin/users', '/admin/form', '/admin/fields', '/admin/grid', '/admin/show', '/admin/nav', '/admin/iframe', '/admin/dashboard', '/admin/widgets'], true)) {
    http_response_code(404);
    echo 'Not found';
    return;
}

$app = Orchestra\Testbench\Foundation\Application::create();

$app['config']->set('app.debug', true);
$app['config']->set('app.url', 'http://127.0.0.1:4175');
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
$app['config']->set('session.driver', 'array');
$app['config']->set('database.default', 'testing');
$app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
$app['config']->set('auth.providers.users.model', BrowserFakeUser::class);
$app['config']->set('admin.name', 'Laravel Admin');
$app['config']->set('admin.title', 'Admin');
$app['config']->set('admin.route.prefix', 'admin');
$app['config']->set('admin.auth.guard', 'admin');
$app['config']->set('admin.database.menu_model', BrowserFakeMenu::class);
$app['config']->set('admin.iframe_tabs.enable', $isIframe);
$app['config']->set('admin.navigation.enabled', $isNavigation);

$app->register(Encore\Admin\AdminServiceProvider::class);
class_alias(Encore\Admin\Facades\Admin::class, 'Admin');

Illuminate\Support\Facades\Auth::guard('admin')->setUser(new BrowserFakeUser());

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/login', function () {
    return view('admin::login');
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin', function () {
    $content = new Encore\Admin\Layout\Content();
    $content->title('Dashboard')->description('AdminLTE 4 host smoke test');
    $content->body('<div class="card"><div class="card-header"><h3 class="card-title">Card</h3></div><div class="card-body">Rendered by the real admin layout.</div></div>');

    return $content;
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/users', function () {
    $content = new Encore\Admin\Layout\Content();
    $content->title('Users')->description('Users page');
    $content->body('<div class="card"><div class="card-body">Users page body.</div></div>');

    return $content;
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/form', function () {
    browserDemoTable();

    $model = new BrowserDemoModel([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'status' => 'active',
        'active' => 1,
        'date' => '2026-10-26',
    ]);

    $form = new Encore\Admin\Form($model);
    $form->text('name')->default('Alice');
    $form->email('email')->default('alice@example.com');
    $form->select('status')->options(['active' => 'Active', 'disabled' => 'Disabled'])->default('active');
    $form->switch('active')->default(1);
    $form->date('date')->default('2026-10-26');
    $form->setAction('/admin/form');

    $content = new Encore\Admin\Layout\Content();
    $content->title('Form')->description('Form field smoke test');
    $content->body($form);

    return $content;
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/fields', function () {
    browserDemoTable();

    $model = new BrowserDemoModel();

    $form = new Encore\Admin\Form($model);
    $form->text('name');
    $form->textarea('bio');
    $form->select('status')->options(['active' => 'Active', 'disabled' => 'Disabled']);
    $form->multipleSelect('roles')->options(['admin' => 'Admin', 'editor' => 'Editor']);
    $form->checkbox('permissions')->options(['read' => 'Read', 'write' => 'Write']);
    $form->radio('gender')->options(['m' => 'Male', 'f' => 'Female']);
    $form->switch('active');
    $form->date('date');
    $form->dateRange('start_date', 'end_date');
    $form->datetime('published_at');
    $form->time('time');
    $form->color('color');
    $form->number('number');
    $form->currency('price');
    $form->decimal('amount');
    $form->mobile('mobile');
    $form->ip('ip');
    $form->month('month');
    $form->year('year');
    $form->timezone('timezone');
    $form->hidden('hidden_field');
    $form->icon('icon');
    $form->divider('Divider');
    $form->html('<div class="alert alert-info">HTML field</div>');
    $form->email('email');
    $form->url('url');
    $form->password('password');
    $form->file('file');
    $form->tags('tags');
    $form->listbox('listbox')->options(['a' => 'A', 'b' => 'B', 'c' => 'C']);
    $form->slider('slider');
    $form->setAction('/admin/fields');

    $content = new Encore\Admin\Layout\Content();
    $content->title('Fields')->description('Field matrix smoke test');
    $content->body($form);

    return $content;
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/grid', function () {
    browserDemoTable();

    $grid = new Encore\Admin\Grid(new BrowserDemoModel());
    $grid->column('id', 'ID')->sortable();
    $grid->column('name', 'Name');
    $grid->column('email', 'Email')->editable();
    $grid->column('status', 'Status')->using(['active' => 'Active', 'disabled' => 'Disabled']);
    $grid->column('active', 'Active')->switch();
    $grid->column('date', 'Date');
    $grid->paginate(2);
    $grid->filter(function ($filter) {
        $filter->column(1 / 2, function ($column) {
            $column->like('name', 'Name');
            $column->between('active', 'Active range');
        });
        $filter->column(1 / 2, function ($column) {
            $column->equal('status', 'Status')->select(['active' => 'Active', 'disabled' => 'Disabled']);
            $column->like('email', 'Email');
        });
    });

    $content = new Encore\Admin\Layout\Content();
    $content->title('Grid')->description('Grid and filter smoke test');
    $content->body($grid);

    return $content;
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/show', function () {
    browserDemoTable();

    $model = BrowserDemoModel::find(1);
    $show = new Encore\Admin\Show($model);
    $show->field('id', 'ID');
    $show->field('name', 'Name');
    $show->field('email', 'Email');
    $show->field('status', 'Status')->using(['active' => 'Active', 'disabled' => 'Disabled']);
    $show->field('date', 'Date');

    $content = new Encore\Admin\Layout\Content();
    $content->title('Show')->description('Show page smoke test');
    $content->body($show);

    return $content;
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/nav', function () {
    $content = new Encore\Admin\Layout\Content();
    $content->title('Navigation')->description('Global navigation smoke test');
    $content->body('<div class="card"><div class="card-body">Navigation page body.</div></div>');

    return $content;
});

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/iframe', function () use ($app) {
    Encore\Admin\Facades\Admin::navbar(function ($navbar) {
        $navbar->right('<li><a class="fixture-cache-tools" href="#tools"><i class="fa fa-trash" aria-hidden="true"></i><span>Cache tools</span></a></li>');
        $navbar->right('<li><a class="fixture-apps" href="#apps" aria-label="Apps"><i class="fa fa-th" aria-hidden="true"></i></a></li>');
    });
    $controller = $app->make(Encore\Admin\IframeTabs\Http\Controllers\IframeTabsController::class);
    $content = $app->make(Encore\Admin\Layout\Content::class);

    return $controller->index($content);
})->name('iframes.index');

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/dashboard', function () {
    $content = new Encore\Admin\Layout\Content();
    $content->title('Dashboard')->description('Iframe home page');
    $content->body('<div class="card"><div class="card-body">Iframe home content.</div></div>');

    return $content;
})->name('iframes.dashboard');

$app['router']->middleware(['web', 'admin.bootstrap'])->get('/admin/widgets', function () {
    $content = new Encore\Admin\Layout\Content();
    $content->title('Widgets')->description('Widget smoke test');

    $content->body(new Encore\Admin\Widgets\Box('Box title', 'Box content', 'Box footer'));
    $content->body(new Encore\Admin\Widgets\InfoBox('Users', 'fa-users', 'blue', '/admin/users', '100'));
    $content->body(new Encore\Admin\Widgets\Alert('Alert content', 'Alert title', 'danger'));

    $tab = new Encore\Admin\Widgets\Tab();
    $tab->add('One', 'Pane one', true);
    $tab->add('Two', 'Pane two');
    $content->body($tab);

    $collapse = new Encore\Admin\Widgets\Collapse();
    $collapse->add('Section', 'Collapse content');
    $content->body($collapse);

    $carousel = new Encore\Admin\Widgets\Carousel([
        ['image' => '/vendor/laravel-admin/adminlte4/img/user2-160x160.jpg', 'caption' => 'Slide one'],
    ]);
    $content->body($carousel);

    return $content;
});

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
