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

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

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

if (!in_array($path, ['/login', '/admin', '/admin/users', '/admin/form'], true)) {
    http_response_code(404);
    echo 'Not found';
    return;
}

$app = Orchestra\Testbench\Foundation\Application::create();

$app['config']->set('app.debug', true);
$app['config']->set('app.url', 'http://127.0.0.1:4175');
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
$app['config']->set('session.driver', 'array');
$app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
$app['config']->set('auth.providers.users.model', BrowserFakeUser::class);
$app['config']->set('admin.name', 'Laravel Admin');
$app['config']->set('admin.title', 'Admin');
$app['config']->set('admin.route.prefix', 'admin');
$app['config']->set('admin.auth.guard', 'admin');
$app['config']->set('admin.database.menu_model', BrowserFakeMenu::class);
$app['config']->set('admin.iframe_tabs.enable', false);

$app->register(Encore\Admin\AdminServiceProvider::class);
class_alias(Encore\Admin\Facades\Admin::class, 'Admin');

Illuminate\Support\Facades\Auth::guard('admin')->setUser(new BrowserFakeUser());

$app['router']->middleware('web')->get('/login', function () {
    return view('admin::login');
});

$app['router']->middleware('web')->get('/admin', function () {
    $content = new Encore\Admin\Layout\Content();
    $content->title('Dashboard')->description('AdminLTE 4 host smoke test');
    $content->body('<div class="card"><div class="card-header"><h3 class="card-title">Card</h3></div><div class="card-body">Rendered by the real admin layout.</div></div>');

    return $content;
});

$app['router']->middleware('web')->get('/admin/users', function () {
    $content = new Encore\Admin\Layout\Content();
    $content->title('Users')->description('Users page');
    $content->body('<div class="card"><div class="card-body">Users page body.</div></div>');

    return $content;
});

$app['router']->middleware('web')->get('/admin/form', function () {
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

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
