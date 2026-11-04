<?php

namespace Encore\Admin\Console;

use Encore\Admin\Actions\CompletionCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PruneActionCacheCommand extends Command
{
    protected $signature = 'admin:prune-action-cache {--store= : Cache store name} {--dry-run : Count without deleting}';
    protected $description = 'Remove expired admin action completion markers from file or database cache';

    public function handle(CompletionCache $completionCache)
    {
        $name = $this->option('store') ?: config('cache.default');
        $configuration = config('cache.stores', [])[$name] ?? [];
        try {
            $count = $completionCache->prune(Cache::store($name)->getStore(), $configuration['table'] ?? 'cache', (bool) $this->option('dry-run'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
        $this->info(($this->option('dry-run') ? 'Eligible' : 'Removed')." completion markers: {$count}");

        return self::SUCCESS;
    }
}
