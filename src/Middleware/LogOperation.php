<?php

namespace Encore\Admin\Middleware;

use Encore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LogOperation
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if ($this->shouldLogOperation($request)) {
            $input = json_encode(
                $this->redact($request->input()),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

            $log = [
                'user_id' => Admin::user()->id,
                'path'    => substr($request->path(), 0, 255),
                'method'  => $request->method(),
                'ip'      => $request->getClientIp(),
                'input'   => is_string($input) ? substr($input, 0, 65535) : '{}',
            ];

            try {
                OperationLogModel::create($log);
            } catch (\Throwable $exception) {
                // Logging must never break the admin request.
            }
        }

        return $next($request);
    }

    /**
     * Remove credentials and oversized values before storing request input.
     */
    protected function redact(array $input): array
    {
        $redacted = [];

        foreach ($input as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'authorization')
                || str_ends_with($normalizedKey, '_key')
                || $normalizedKey === 'key') {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);
            } elseif (is_object($value)) {
                $redacted[$key] = '[OBJECT]';
            } elseif (is_string($value) && strlen($value) > 2000) {
                $redacted[$key] = substr($value, 0, 2000).'...[TRUNCATED]';
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    protected function shouldLogOperation(Request $request)
    {
        return config('admin.operation_log.enable')
            && !$this->inExceptArray($request)
            && !$this->isInternalEndpoint($request)
            && $this->inAllowedMethods($request->method())
            && Admin::user();
    }

    /**
     * Read-only component loaders are plumbing. Action execution remains logged
     * because it can perform business writes, including deletes.
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function isInternalEndpoint(Request $request)
    {
        return $request->is(
            '*_load_action_',
            '*_handle_selectable_',
            '*_handle_renderable_'
        );
    }

    /**
     * Whether requests using this method are allowed to be logged.
     *
     * @param string $method
     *
     * @return bool
     */
    protected function inAllowedMethods($method)
    {
        $allowedMethods = collect(config('admin.operation_log.allowed_methods'))->filter();

        if ($allowedMethods->isEmpty()) {
            return true;
        }

        return $allowedMethods->map(function ($method) {
            return strtoupper($method);
        })->contains($method);
    }

    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function inExceptArray($request)
    {
        foreach (config('admin.operation_log.except') as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            $methods = [];

            if (Str::contains($except, ':')) {
                list($methods, $except) = explode(':', $except);
                $methods = explode(',', $methods);
            }

            $methods = array_map('strtoupper', $methods);

            if ($request->is($except) &&
                (empty($methods) || in_array($request->method(), $methods))) {
                return true;
            }
        }

        return false;
    }
}
