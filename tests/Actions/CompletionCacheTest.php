<?php

namespace Encore\Admin\Tests\Actions;

use Encore\Admin\Actions\CompletionCache;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CompletionCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/admin-cache-prune-'.bin2hex(random_bytes(8));
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        (new Filesystem())->deleteDirectory($this->directory);
    }

    private function key(string $name): string
    {
        return 'admin-action:'.hash('sha256', $name).':completed';
    }

    public function test_file_pruning_only_reclaims_expired_identified_markers_and_dry_run_preserves_files(): void
    {
        $store = new FileStore(new Filesystem(), $this->directory);
        $cache = new Repository($store);
        $expired = $this->key('expired');
        $active = $this->key('active');
        $cache->put($expired, CompletionCache::marker($expired), 60);
        $cache->put($active, CompletionCache::marker($active), 3600);
        $cache->put('unrelated', true, 60);
        $cache->put('forged-location', CompletionCache::marker($active), 60);
        $cache->forever($this->key('legacy'), true);
        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        $pruner = new CompletionCache();
        $this->assertSame(1, $pruner->prune($store, 'unused', true));
        $this->assertFileExists($store->path($expired));
        $this->assertSame(1, $pruner->prune($store));
        $this->assertFileDoesNotExist($store->path($expired));
        $this->assertFileExists($store->path($active));
        $this->assertFileExists($store->path('unrelated'));
        $this->assertFileExists($store->path('forged-location'));
        $this->assertFileExists($store->path($this->key('legacy')));
        $this->assertSame(0, $pruner->prune($store));
    }

    public function test_database_pruning_preserves_other_namespaces_values_and_live_entries(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('pdo_sqlite is required.');
        $connection = new \Illuminate\Database\SQLiteConnection(new \PDO('sqlite::memory:'));
        $connection->getSchemaBuilder()->create('cached_values', function ($table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        $store = new DatabaseStore($connection, 'cached_values', 'admin_cache_');
        $cache = new Repository($store);
        $expired = $this->key('expired');
        $active = $this->key('active');
        $cache->put($expired, CompletionCache::marker($expired), 60);
        $cache->put($active, CompletionCache::marker($active), 3600);
        $cache->put('unrelated', true, 60);
        $cache->put($this->key('not-a-marker'), true, 60);
        $connection->table('cached_values')->insert([
            'key' => 'adminXcacheX'.$this->key('other-prefix'),
            'value' => serialize(CompletionCache::marker($this->key('other-prefix'))),
            'expiration' => Carbon::now()->getTimestamp(),
        ]);
        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        $pruner = new CompletionCache();
        $this->assertSame(1, $pruner->prune($store, 'cached_values', true));
        $this->assertSame(5, $connection->table('cached_values')->count());
        $this->assertSame(1, $pruner->prune($store, 'cached_values'));
        $this->assertSame(4, $connection->table('cached_values')->count());
        $this->assertFalse($connection->table('cached_values')->where('key', 'admin_cache_'.$expired)->exists());
        $this->assertTrue($connection->table('cached_values')->where('key', 'admin_cache_'.$active)->exists());
    }

    public function test_unknown_stores_are_rejected_without_flushing_them(): void
    {
        $store = new \Illuminate\Cache\ArrayStore();
        $store->put('business-data', 'keep', 3600);
        try {
            (new CompletionCache())->prune($store);
            $this->fail('Unknown store was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('keep', $store->get('business-data'));
        }
    }

    public function test_console_command_resolves_the_selected_store_and_honors_dry_run(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $previousFacade = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        try {
            $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
            $app->instance('config', new \Illuminate\Config\Repository([
                'cache' => ['default' => 'files', 'stores' => ['files' => ['driver' => 'file', 'path' => $this->directory]]],
            ]));
            $app->instance('files', new Filesystem());
            $app->register(\Illuminate\Cache\CacheServiceProvider::class);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
            $key = $this->key('console');
            $cache = $app['cache']->store('files');
            $cache->put($key, CompletionCache::marker($key), 60);
            $path = $cache->getStore()->path($key);
            Carbon::setTestNow(Carbon::now()->addSeconds(120));
            $command = new \Encore\Admin\Console\PruneActionCacheCommand();
            $command->setLaravel($app);
            $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
            $this->assertSame(0, $tester->execute(['--store' => 'files', '--dry-run' => true]));
            $this->assertStringContainsString('Eligible completion markers: 1', $tester->getDisplay());
            $this->assertFileExists($path);
            $this->assertSame(0, $tester->execute(['--store' => 'files']));
            $this->assertStringContainsString('Removed completion markers: 1', $tester->getDisplay());
            $this->assertFileDoesNotExist($path);
        } finally {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($previousFacade);
            \Illuminate\Container\Container::setInstance($previous);
        }
    }
}
