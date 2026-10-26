<?php

namespace Encore\Admin\Tests\LogViewer;

use Encore\Admin\Admin;
use Encore\Admin\LogViewer\LogViewer;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class LogViewerTest extends TestCase
{
    private $app;
    private $previousContainer;
    private string $directory;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->directory = sys_get_temp_dir().'/admin-log-viewer-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/compiled', 0777, true);
        mkdir($this->directory.'/other');
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => [], 'compiled' => $this->directory.'/compiled'],
            'admin' => [
                'route' => ['prefix' => 'admin', 'middleware' => ['web', 'admin']],
                'log_viewer' => ['directories' => ['default' => $this->directory, 'other' => $this->directory.'/other']],
            ],
        ]));
        $this->app->instance('request', Request::create('http://localhost/admin/logs'));
        $this->app->instance('files', new Filesystem());
        $this->app->instance('session', new Store('logs', new ArraySessionHandler(120)));
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $this->app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $this->app->register(\Illuminate\View\ViewServiceProvider::class);
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $this->app['translator']->addLines(['admin.delete_confirm' => 'Confirm delete?'], 'en');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        (new Admin())->routes();
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->directory.'/'.$name, $contents);
    }

    private function entry(int $number, string $message = 'message'): string
    {
        return sprintf('[2026-10-26 12:00:%02d] local.INFO: %s-%d', $number, $message, $number)."\n";
    }

    public function test_empty_directory_and_empty_file_are_readable(): void
    {
        $viewer = new LogViewer();
        $this->assertSame('', $viewer->file);
        $this->assertSame([], $viewer->fetch());
        $this->assertSame([0, []], $viewer->tail(0));
        $this->write('empty.log', '');
        $viewer = new LogViewer('empty.log');
        $this->assertSame(0, $viewer->getFilesize());
        $this->assertSame([], $viewer->fetch());
    }

    public function test_pagination_round_trips_without_losing_entries_or_directory(): void
    {
        $raw = '';
        for ($i = 0; $i < 45; $i++) {
            $raw .= $this->entry($i);
        }
        $this->write('other/laravel.log', $raw);
        $viewer = new LogViewer('laravel.log', 'other');
        $this->assertSame('message-44', $viewer->fetch()[0]['info']);
        $next = $viewer->getNextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('other', $query['type']);
        $older = $viewer->fetch($query['offset']);
        $this->assertCount(20, $older);
        $this->assertSame('message-24', $older[0]['info']);
        $this->assertSame('message-5', $older[19]['info']);
        parse_str(parse_url($viewer->getPrevPageUrl(), PHP_URL_QUERY), $query);
        $newer = $viewer->fetch($query['offset']);
        $this->assertSame('message-44', $newer[0]['info']);
        $this->assertSame('message-25', $newer[19]['info']);
    }

    public function test_live_rows_escape_message_and_trace_and_detect_truncation(): void
    {
        $this->write('laravel.log', $this->entry(0));
        $viewer = new LogViewer('laravel.log');
        $offset = $viewer->getFilesize();
        file_put_contents($this->directory.'/laravel.log', $this->entry(1, '<script>alert(1)</script>')."<img src=x onerror=alert(2)>\n", FILE_APPEND);
        [$pos, $rows] = $viewer->tail($offset);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('&lt;script&gt;', $rows[0]);
        $this->assertStringContainsString('&lt;img', $rows[0]);
        $this->assertStringNotContainsString('<script>', $rows[0]);
        $this->assertGreaterThan($offset, $pos);
        $this->write('laravel.log', $this->entry(2));
        [$pos, $rows] = $viewer->tail($pos);
        $this->assertCount(1, $rows);
        $this->assertSame($viewer->getFilesize(), $pos);
    }

    public function test_partial_live_line_is_kept_until_complete(): void
    {
        $line = rtrim($this->entry(0), "\n");
        $this->write('laravel.log', $line);
        $viewer = new LogViewer('laravel.log');
        $this->assertSame([0, []], $viewer->tail(0));
        file_put_contents($this->directory.'/laravel.log', "\n", FILE_APPEND);
        [$pos, $rows] = $viewer->tail(0);
        $this->assertSame(strlen($line) + 1, $pos);
        $this->assertCount(1, $rows);
    }

    public function test_live_read_limit_preserves_records_crossing_the_boundary(): void
    {
        $this->app['config']->set('admin.log_viewer.max_bytes', 4096);

        // End exactly on a newline, inside the next header, or inside its message.
        foreach ([0, 6, 40] as $partialBytes) {
            $first = rtrim($this->entry(0), "\n");
            $first .= str_repeat('a', 4096 - $partialBytes - strlen($first) - 1)."\n";
            $second = $this->entry(1, 'second-entry');
            $third = $this->entry(2, 'third-entry');
            $this->write('laravel.log', $first.$second.$third);
            $viewer = new LogViewer('laravel.log');

            [$pos, $firstRows] = $viewer->tail(0);
            $this->assertSame(strlen($first), $pos);
            $this->assertCount(1, $firstRows);

            [$end, $nextRows] = $viewer->tail($pos);
            $this->assertSame(strlen($first.$second.$third), $end);
            $this->assertCount(2, $nextRows);
            $html = implode('', array_merge($firstRows, $nextRows));
            $this->assertSame(1, substr_count($html, 'second-entry-1'));
            $this->assertSame(1, substr_count($html, 'third-entry-2'));
            $this->assertSame([$end, []], $viewer->tail($end));
        }
    }

    public function test_bulk_delete_handles_more_than_fifty_files_and_keeps_other_files(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->write($i.'.log', 'log');
        }
        $this->write('keep.txt', 'keep');
        $this->write('other/keep.log', 'other directory');
        $this->assertSame(55, (new LogViewer())->deleteAll());
        $this->assertFileExists($this->directory.'/keep.txt');
        $this->assertFileExists($this->directory.'/other/keep.log');
        $this->assertSame([], (new LogViewer())->getLogFiles());
    }

    public function test_path_traversal_and_unknown_directories_are_rejected(): void
    {
        foreach (['../outside.log', '..\\outside.log', 'file.txt', "bad\0.log"] as $file) {
            try {
                new LogViewer($file);
                $this->fail('Accepted an invalid filename.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Invalid log filename.', $exception->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new LogViewer(null, '../other');
    }

    public function test_reads_are_bounded_and_missing_files_return_empty_results(): void
    {
        $this->app['config']->set('admin.log_viewer.max_bytes', 4096);
        $this->write('laravel.log', $this->entry(0, str_repeat('x', 10000)));
        $viewer = new LogViewer('laravel.log');
        [$pos] = $viewer->tail(0);
        $this->assertSame(4096, $pos);
        $this->assertSame([], $viewer->fetch()); // Latest window contains only part of the oversized entry.
        unlink($this->directory.'/laravel.log');
        $this->assertSame([], $viewer->fetch());
        $this->assertSame([0, []], $viewer->tail($pos));
    }

    public function test_symlinked_logs_cannot_be_read_or_deleted(): void
    {
        $this->write('other/private.log', $this->entry(0));
        if (!@symlink($this->directory.'/other/private.log', $this->directory.'/linked.log')) {
            $this->markTestSkipped('Creating symlinks is not permitted on this system.');
        }
        $viewer = new LogViewer('linked.log');
        $this->assertSame('', $viewer->getFilePath());
        $this->assertSame([], $viewer->fetch());
        $this->assertFalse($viewer->deleteLogFile());
        $this->assertNotContains('linked.log', $viewer->getLogFiles());
        $this->assertFileExists($this->directory.'/other/private.log');
    }

    public function test_log_routes_require_admin_middleware_and_delete_http_method(): void
    {
        $routes = $this->app['router']->getRoutes();
        foreach (['log-viewer-delete', 'log-viewer-deleteall'] as $name) {
            $route = $routes->getByName($name);
            $this->assertSame(['DELETE'], $route->methods());
            $this->assertContains('web', $route->middleware());
            $this->assertContains('admin', $route->middleware());
        }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class);
        $routes->match(Request::create('/admin/logs/laravel.log/delete', 'GET'));
    }

    public function test_empty_page_renders_without_pjax_and_delete_forms_include_csrf(): void
    {
        $html = $this->app['view']->make('admin::log-viewer.logs', [
            'logs' => [], 'file' => 'laravel.log', 'logFiles' => ['laravel.log'], 'type' => 'default',
            'directories' => ['default', 'other'], 'end' => 0, 'tailPath' => '',
            'prevUrl' => false, 'nextUrl' => false, 'size' => '0 B',
        ])->render();

        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('value="DELETE"', $html);
        $this->assertStringContainsString('log-empty', $html);
        $this->assertStringNotContainsString('pjax', $html);
    }
}
