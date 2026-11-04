<?php

namespace Encore\Admin\Widgets;

use Encore\Admin\Auth\Database\OperationLog;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** Load full request parameters only when the log's detail modal is opened. */
class OperationLogInput implements Renderable
{
    public static function result($input): string
    {
        $data = is_string($input) ? json_decode($input, true) : $input;
        $result = is_array($data) ? ($data['_admin_audit'] ?? null) : null;
        if (!is_array($result) || !isset($result['http_status'], $result['outcome'], $result['duration_ms'])
            || !is_numeric($result['http_status']) || !is_numeric($result['duration_ms'])
            || !in_array($result['outcome'], ['failed', 'succeeded', 'redirected', 'completed'], true)) {
            return '';
        }

        return 'HTTP '.(int) $result['http_status'].' · '.trans('admin.operation_log_'.$result['outcome'])
            .' · '.(float) $result['duration_ms'].' ms';
    }

    public static function format($input, bool $pretty = true): string
    {
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            // Older logs can contain truncated JSON. Keep that content inspectable.
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $input;
            }
            $input = $decoded;
        }
        if (is_array($input)) {
            $input = Arr::except($input, ['_token', '_method', '_previous_', '_action_token', '_form_token_', '_component_token_']);
        }
        $json = json_encode($input === null || $input === [] ? (object) [] : $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | ($pretty ? JSON_PRETTY_PRINT : 0));
        return $json === false ? '{}' : $json;
    }

    public static function preview($input): string
    {
        $decoded = is_string($input) ? json_decode($input, true) : $input;
        if (is_array($decoded)) {
            unset($decoded['_admin_audit']);
            $input = $decoded;
        }

        return Str::limit(preg_replace('/\s+/u', ' ', static::format($input, false)) ?? '', 100);
    }

    public function render($key = null)
    {
        $log = OperationLog::query()->select(['id', 'input'])->findOrFail($key);

        return view('admin::widgets.operation-log-input', ['input' => static::format($log->input)])->render();
    }
}
