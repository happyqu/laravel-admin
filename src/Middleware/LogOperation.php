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
        if (!$this->shouldLogOperation($request)) {
            return $next($request);
        }

        // Capture before a controller sanitizes input or changes the authenticated user.
        $input = $this->redact($request->input());
        unset($input['_admin_audit']);
        $log = [
            'user_id' => Admin::user()->getAuthIdentifier(),
            'path'    => substr($request->path(), 0, 255),
            'method'  => $request->method(),
            'ip'      => $request->getClientIp(),
        ];
        $started = hrtime(true);
        $response = null;
        $failure = null;
        try {
            return $response = $next($request);
        } catch (\Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                $log['input'] = $this->encodeInput($input, $this->result($request, $response, $failure, $started));
                $this->persist($log);
            } catch (\Throwable $exception) {
                // Logging must never break the admin request, but a broken log table would
                // otherwise stay invisible.
                $this->reportLoggingFailure($exception);
            }
        }
    }

    /** Report without requiring an exception handler (for example in minimal containers). */
    protected function reportLoggingFailure(\Throwable $exception): void
    {
        try {
            if (app()->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)) {
                report($exception);
            }
        } catch (\Throwable $ignored) {
            // Nothing else may run while unwinding a response.
        }
    }

    protected function persist(array $log): void
    {
        OperationLogModel::create($log);
    }

    protected function result(Request $request, $response, ?\Throwable $failure, int $started): array
    {
        // A short-circuit response already describes the outcome; keep the status it carries.
        $isHttpResponse = $failure instanceof \Illuminate\Http\Exceptions\HttpResponseException;
        if ($isHttpResponse) {
            $response = $failure->getResponse();
        }
        $status = $response instanceof \Symfony\Component\HttpFoundation\Response ? $response->getStatusCode() : 200;
        if (!$isHttpResponse) {
            $status = match (true) {
                $failure instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $failure->getStatusCode(),
                $failure instanceof \Illuminate\Auth\Access\AuthorizationException => $failure->status() ?? 403,
                $failure instanceof \Illuminate\Validation\ValidationException => $failure->status,
                $failure instanceof \Illuminate\Auth\AuthenticationException => 401,
                (bool) $failure => 500,
                default => $status,
            };
        }

        $data = $response instanceof \Symfony\Component\HttpFoundation\JsonResponse ? json_decode($response->getContent(), true) : null;
        $businessStatus = is_array($data) && is_bool($data['status'] ?? null) ? $data['status'] : null;
        $exceptionFailure = $failure && !$isHttpResponse;
        $outcome = $exceptionFailure || $status >= 400 || $businessStatus === false ? 'failed'
            : ($businessStatus === true ? 'succeeded' : ($status >= 300 ? 'redirected' : 'completed'));
        $result = [
            'http_status' => $status,
            'outcome' => $outcome,
            'duration_ms' => round((hrtime(true) - $started) / 1000000, 2),
        ];
        if ($businessStatus !== null) $result['business_status'] = $businessStatus;
        if ($failure) $result['exception'] = get_class($failure);
        if ($route = $request->route()) {
            $result['route'] = $route->getName();
            $result['parameters'] = [];
            foreach ($route->parameters() as $name => $value) {
                $result['parameters'][$name] = $value instanceof \Illuminate\Database\Eloquent\Model
                    ? $value->getKey() : (is_scalar($value) ? $value : '[OBJECT]');
            }
        }
        if ($action = $request->attributes->get('_admin_audit_action')) {
            $result['action'] = $action;
        }

        return $this->redact($result);
    }

    protected function encodeInput(array $input, array $result): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if (strlen(json_encode($result, $flags) ?: '') > 16000) {
            $result = array_intersect_key($result, array_flip(['http_status', 'outcome', 'duration_ms', 'business_status']))
                + ['metadata_truncated' => true];
        }
        $json = json_encode(['_admin_audit' => $result] + $input, $flags);
        if (is_string($json) && strlen($json) <= 65535) {
            return $json;
        }

        // Preserve valid JSON and outcome metadata even when many small fields exceed TEXT.
        $result['input_truncated'] = true;
        $preview = mb_strcut(json_encode($input, $flags) ?: '{}', 0, 16000, 'UTF-8');

        return json_encode(['_admin_audit' => $result, '_admin_input_preview' => $preview], $flags) ?: '{}';
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
        foreach (config('admin.operation_log.except', []) as $except) {
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
