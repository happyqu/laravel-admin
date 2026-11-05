<?php

namespace Encore\Admin\Actions;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Support\Carbon;

/** Reclaim expired completion markers without flushing a shared cache. */
class CompletionCache
{
    public static function marker(string $key): array
    {
        return ['type' => 'laravel-admin-action-completion', 'key' => $key];
    }

    private function markerKey($value): ?string
    {
        if (!is_array($value) || ($value['type'] ?? null) !== 'laravel-admin-action-completion'
            || !is_string($value['key'] ?? null)
            || !preg_match('/^admin-action:[a-f0-9]{64}:completed$/D', $value['key'])) {
            return null;
        }

        return $value['key'];
    }

    public function prune($store, string $table = 'cache', bool $dryRun = false): int
    {
        if ($store instanceof FileStore) return $this->pruneFiles($store, $dryRun);
        if ($store instanceof DatabaseStore) return $this->pruneDatabase($store, $table, $dryRun);
        if ($this->expiresAutomatically($store)) return 0;

        throw new \InvalidArgumentException('Manual pruning supports Laravel file and database cache stores only.');
    }

    /** Stores with a built-in TTL drop expired markers on their own; nothing to reclaim. */
    private function expiresAutomatically($store): bool
    {
        return $store instanceof \Illuminate\Cache\RedisStore
            || $store instanceof \Illuminate\Cache\MemcachedStore
            || $store instanceof \Illuminate\Cache\DynamoDbStore
            || $store instanceof \Illuminate\Cache\ApcStore;
    }

    private function pruneFiles(FileStore $store, bool $dryRun): int
    {
        $directory = realpath($store->getDirectory());
        if ($directory === false) return 0;

        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink() || !preg_match('/^[a-f0-9]{40}$/D', $file->getFilename())) continue;
            $contents = @file_get_contents($file->getPathname(), false, null, 0, 4097);
            if (!is_string($contents) || strlen($contents) > 4096 || !preg_match('/^[0-9]{10}/', $contents)
                || (int) substr($contents, 0, 10) > Carbon::now()->getTimestamp()) continue;
            $key = $this->markerKey(@unserialize(substr($contents, 10), ['allowed_classes' => false]));
            if ($key === null || realpath($store->path($key)) !== $file->getRealPath()) continue;

            if ($dryRun) {
                $count++;
                continue;
            }
            // FileStore rechecks expiration itself, so a concurrently refreshed entry survives.
            $store->get($key);
            clearstatcache(true, $file->getPathname());
            if (!is_file($file->getPathname())) $count++;
        }

        return $count;
    }

    private function pruneDatabase(DatabaseStore $store, string $table, bool $dryRun): int
    {
        $count = 0;
        $prefix = $store->getPrefix();
        $query = $store->getConnection()->table($table)->where('expiration', '<=', Carbon::now()->getTimestamp())
            ->where('key', 'like', $prefix.'admin-action:%:completed');
        foreach ($query->lazyById(500, 'key') as $row) {
            if (!is_string($row->value) || strlen($row->value) > 4096) continue;
            $key = $this->markerKey(@unserialize($row->value, ['allowed_classes' => false]));
            if ($key === null || $row->key !== $prefix.$key) continue;
            if ($dryRun) {
                $count++;
                continue;
            }
            $count += $store->getConnection()->table($table)->where('key', $row->key)
                ->where('expiration', '<=', Carbon::now()->getTimestamp())->where('value', $row->value)->delete();
        }

        return $count;
    }
}
