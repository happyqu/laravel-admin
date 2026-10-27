<?php

namespace Encore\Admin\Security;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;

/** Bind dynamic component endpoints to a component issued on an authorized page. */
class ComponentSubmission
{
    public static function url(string $route, array $parameters, string $kind, string $class, array $payload): string
    {
        $source = request()->attributes->get('_admin_component_source', [request()->url(), request()->method()]);
        $identity = static::identity();
        $tickets = array_filter(session('admin.component_submissions', []), static fn ($ticket) => $ticket['expires'] >= time());
        foreach ($tickets as $token => $ticket) {
            if ($ticket['source'] === $source && $ticket['identity'] === $identity
                && $ticket['kind'] === $kind && $ticket['class'] === $class && $ticket['payload'] === $payload) {
                return route($route, $parameters + ['_component_token_' => $token]);
            }
        }
        $token = bin2hex(random_bytes(24));
        $tickets[$token] = compact('source', 'identity', 'kind', 'class', 'payload') + ['expires' => time() + 7200];
        session()->put('admin.component_submissions', array_slice($tickets, -2048, null, true));

        return route($route, $parameters + ['_component_token_' => $token]);
    }

    public static function verify(Request $request, string $kind, string $class, array $payload): array
    {
        $token = $request->input('_component_token_');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{48}$/D', $token), 419, 'Invalid component ticket.');
        $ticket = session('admin.component_submissions.'.$token);
        abort_unless(is_array($ticket) && $ticket['expires'] >= time(), 419, 'Expired component ticket.');
        abort_unless($ticket['identity'] === static::identity() && Admin::user()
            && $ticket['kind'] === $kind
            && str_replace('\\', '_', $ticket['class']) === str_replace('\\', '_', $class), 403);
        $normalize = static fn ($value) => (string) (is_bool($value) ? (int) $value : $value);
        abort_unless(array_map($normalize, $ticket['payload']) === array_map($normalize, $payload), 403);
        FormSubmission::authorizeSource(...$ticket['source']);
        $request->attributes->set('_admin_component_source', $ticket['source']);
        $request->attributes->set('_admin_component_class', $ticket['class']);

        return $ticket['payload'];
    }

    private static function identity(): array
    {
        return [(string) (Admin::user()?->getAuthIdentifier() ?? ''), config('admin.auth.guard', 'admin'),
            config('admin.route.prefix'), config('admin.database.connection')];
    }
}
