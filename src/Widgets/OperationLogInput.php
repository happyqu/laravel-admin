<?php

namespace Encore\Admin\Widgets;

use Encore\Admin\Auth\Database\OperationLog;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** Load full request parameters only when the log's detail modal is opened. */
class OperationLogInput implements Renderable
{
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
        return Str::limit(preg_replace('/\s+/u', ' ', static::format($input, false)) ?? '', 100);
    }

    public function render($key = null)
    {
        $log = OperationLog::query()->select(['id', 'input'])->findOrFail($key);

        return view('admin::widgets.operation-log-input', ['input' => static::format($log->input)])->render();
    }
}
