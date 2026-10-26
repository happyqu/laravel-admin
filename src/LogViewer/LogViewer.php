<?php

namespace Encore\Admin\LogViewer;

use Encore\Admin\Extension;
use InvalidArgumentException;
use RuntimeException;

/** File-backed Laravel log viewer adapted from laravel-admin-ext/log-viewer. */
class LogViewer extends Extension
{
    public $file;
    public $type;
    protected $directory;
    protected $pageOffset = [];

    public static $levelColors = [
        'EMERGENCY' => 'black', 'ALERT' => 'navy', 'CRITICAL' => 'maroon',
        'ERROR' => 'red', 'WARNING' => 'orange', 'NOTICE' => 'light-blue',
        'INFO' => 'aqua', 'DEBUG' => 'green',
    ];

    public function __construct(?string $file = null, ?string $type = null)
    {
        $directories = static::directories();
        $this->type = $type ?? config('admin.log_viewer.default', 'default');

        if (!isset($directories[$this->type]) || !is_string($directories[$this->type])) {
            throw new InvalidArgumentException('Unknown log directory.');
        }

        $this->directory = $directories[$this->type];
        $this->file = $file ?? $this->getLastModifiedLog();
        if ($this->file !== '' && !$this->validFilename($this->file)) {
            throw new InvalidArgumentException('Invalid log filename.');
        }
    }

    public static function directories(): array
    {
        return config('admin.log_viewer.directories', ['default' => storage_path('logs')]);
    }

    /** Register menu and permission through the existing admin:import command. */
    public static function import()
    {
        $menuModel = config('admin.database.menu_model');
        $permissionModel = config('admin.database.permissions_model');

        if (!$menuModel::where('uri', 'logs')->exists()) {
            static::createMenu('Log viewer', 'logs', 'fa-database');
        }
        if (!$permissionModel::where('slug', 'ext.log-viewer')->exists()) {
            static::createPermission('Logs', 'ext.log-viewer', '/logs*');
        }
    }

    private function validFilename(string $file): bool
    {
        return !str_contains($file, '/') && !str_contains($file, '\\')
            && !str_contains($file, "\0") && str_ends_with($file, '.log');
    }

    /** Resolve every access again so deleted/rotated files do not leave stale paths. */
    public function getFilePath(): string
    {
        if ($this->file === '' || !$this->validFilename($this->file)) {
            return '';
        }

        $directory = realpath($this->directory);
        $path = realpath($this->directory.DIRECTORY_SEPARATOR.$this->file);

        if ($directory === false || $path === false || !is_file($path)
            || dirname($path) !== $directory || is_link($this->directory.DIRECTORY_SEPARATOR.$this->file)) {
            return '';
        }

        return $path;
    }

    public function getFilesize(): int
    {
        $path = $this->getFilePath();
        if ($path === '') {
            return 0;
        }
        clearstatcache(true, $path);

        return (int) filesize($path);
    }

    public function getLogFiles(?int $count = null): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = [];
        foreach (new \DirectoryIterator($this->directory) as $entry) {
            if ($entry->isFile() && !$entry->isLink() && $this->validFilename($entry->getFilename())) {
                $files[$entry->getFilename()] = $entry->getMTime();
            }
        }
        arsort($files);
        $names = array_keys($files);

        return $count === null ? $names : array_slice($names, 0, $count);
    }

    public function getLastModifiedLog(): string
    {
        return $this->getLogFiles(1)[0] ?? '';
    }

    public function deleteLogFile(): bool
    {
        $path = $this->getFilePath();

        return $path !== '' && unlink($path);
    }

    public function deleteAll(): int
    {
        $deleted = 0;
        foreach ($this->getLogFiles() as $file) {
            $viewer = new static($file, $this->type);
            if (!$viewer->deleteLogFile()) {
                throw new RuntimeException('Unable to delete log file.');
            }
            $deleted++;
        }

        return $deleted;
    }

    public function getPrevPageUrl()
    {
        return isset($this->pageOffset['end']) && $this->pageOffset['end'] < $this->getFilesize()
            ? $this->pageUrl($this->pageOffset['end']) : false;
    }

    public function getNextPageUrl()
    {
        return !empty($this->pageOffset['start']) ? $this->pageUrl(-$this->pageOffset['start']) : false;
    }

    private function pageUrl(int $offset): string
    {
        return route('log-viewer-file', ['file' => $this->file, 'type' => $this->type, 'offset' => $offset]);
    }

    private function maxBytes(): int
    {
        return max(4096, min(4 * 1024 * 1024, (int) config('admin.log_viewer.max_bytes', 1024 * 1024)));
    }

    private function read(int $start, int $length): string
    {
        $path = $this->getFilePath();
        if ($path === '' || $length <= 0) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open log file.');
        }
        try {
            if (fseek($handle, $start) !== 0) {
                throw new RuntimeException('Unable to seek log file.');
            }
            $raw = fread($handle, $length);
            if ($raw === false) {
                throw new RuntimeException('Unable to read log file.');
            }

            return $raw;
        } finally {
            fclose($handle);
        }
    }

    private function headers(string $raw): array
    {
        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[T ][^\]\r\n]+)\] ([\w.-]+)\.([A-Z]+):[ \t]*/m', $raw, $matches, PREG_OFFSET_CAPTURE);

        return $matches;
    }

    /** Positive offsets read newer entries; negative offsets read older entries. */
    public function fetch($seek = 0, $lines = 20, $buffer = 4096): array
    {
        $seek = (int) $seek;
        $lines = max(1, min(200, (int) $lines));
        $size = $this->getFilesize();
        $boundary = $seek === 0 ? $size : min(abs($seek), $size);
        $start = $seek > 0 ? $boundary : max(0, $boundary - $this->maxBytes());
        $end = $seek > 0 ? min($size, $start + $this->maxBytes()) : $boundary;
        $raw = $this->read($start, $end - $start);
        $headers = $this->headers($raw)[0];

        if ($seek > 0 && count($headers) > $lines) {
            $raw = substr($raw, 0, $headers[$lines][1]);
        } elseif ($seek <= 0 && count($headers) > $lines) {
            $skip = $headers[count($headers) - $lines][1];
            $raw = substr($raw, $skip);
            $start += $skip;
        }
        $this->pageOffset = ['start' => $start, 'end' => $start + strlen($raw)];

        return $this->parseLog($raw);
    }

    public function tail($seek): array
    {
        $size = $this->getFilesize();
        $start = max(0, (int) $seek);
        if ($start > $size) {
            $start = 0; // The log was truncated or rotated.
        }
        $raw = $this->read($start, min($this->maxBytes(), $size - $start));
        // Keep the unfinished line even when the buffer reaches the read limit.
        // A single oversized line without any newline must still make progress.
        $newline = strrpos($raw, "\n");
        if ($newline !== false) {
            $raw = substr($raw, 0, $newline + 1);
        } elseif (strlen($raw) < $this->maxBytes()) {
            $raw = '';
        }
        $logs = array_map(function ($log) {
            return view('admin::log-viewer.row', ['log' => $log, 'highlight' => true])->render();
        }, $this->parseLog($raw));

        return [$start + strlen($raw), $logs];
    }

    protected function parseLog($raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $matches = $this->headers($raw);
        $logs = [];
        foreach ($matches[0] as $index => [$header, $offset]) {
            $end = $matches[0][$index + 1][1] ?? strlen($raw);
            $body = trim(substr($raw, $offset + strlen($header), $end - $offset - strlen($header)));
            [$info, $trace] = array_pad(explode("\n", $body, 2), 2, '');
            $logs[] = [
                'time' => $matches[1][$index][0], 'env' => $matches[2][$index][0],
                'level' => $matches[3][$index][0], 'info' => $info, 'trace' => trim($trace),
            ];
        }

        return array_reverse($logs);
    }
}
