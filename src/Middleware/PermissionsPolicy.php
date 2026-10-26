<?php

namespace Encore\Admin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionsPolicy
{
    /**
     * Merge the configured directives into the Permissions-Policy response header.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (!$response instanceof Response) {
            return $response;
        }

        $configured = config('admin.permissions_policy', []);
        $directives = is_array($configured) ? $configured : [];

        $policy = static::merge((string) $response->headers->get('Permissions-Policy', ''), $directives);

        if ($policy !== '') {
            $response->headers->set('Permissions-Policy', $policy);
        }

        return $response;
    }

    /**
     * Append the directives that are missing from an existing Permissions-Policy value.
     *
     * A directive that the application (or a reverse proxy) already sent is kept
     * untouched, so this middleware never overrides a deliberate decision.
     */
    public static function merge(string $existing, array $directives): string
    {
        $existing = trim($existing);
        $missing = [];

        foreach ($directives as $feature => $allowlist) {
            if (!is_string($feature) || !is_string($allowlist) || trim($allowlist) === '') {
                continue;
            }

            if (preg_match('/(^|,)\s*'.preg_quote($feature, '/').'\s*=/i', $existing)) {
                continue;
            }

            $missing[] = trim($feature).'='.trim($allowlist);
        }

        if ($missing === []) {
            return $existing;
        }

        return $existing === ''
            ? implode(', ', $missing)
            : $existing.', '.implode(', ', $missing);
    }
}
