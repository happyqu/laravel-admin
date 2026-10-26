<?php

/**
 * Minimal Testbench host used by the Playwright login smoke test.
 *
 * Run with:
 *   php -S 127.0.0.1:4174 tests/browser/login-server.php
 */

require __DIR__.'/../../vendor/autoload.php';

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
        'js'  => 'application/javascript; charset=utf-8',
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

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: '.($types[$extension] ?? 'application/octet-stream'));
    readfile($file);
    return;
}

if ($path !== '/login' && $path !== '/') {
    http_response_code(404);
    echo 'Not found';
    return;
}

$app = Orchestra\Testbench\Foundation\Application::create();

$app['config']->set('app.debug', true);
$app['config']->set('app.url', 'http://127.0.0.1:4174');
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
$app['config']->set('session.driver', 'array');
$app['config']->set('admin.name', 'Laravel Admin');
$app['config']->set('admin.title', 'Admin');
$app['config']->set('admin.route.prefix', 'admin');
$app['config']->set('admin.auth.guard', 'admin');

$app->register(Encore\Admin\AdminServiceProvider::class);
class_alias(Encore\Admin\Facades\Admin::class, 'Admin');

$app['router']->middleware('web')->get('/login', function () {
    return view('admin::login');
});

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
