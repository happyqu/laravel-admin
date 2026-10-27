<?php

namespace Encore\Admin\Actions;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Security\FormSubmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

/** Only opaque tickets leave the server. Neither PHP objects nor query results enter the session. */
class Context
{
    private array $definitions = [];

    public function execute(Request $request, callable $callback)
    {
        $token = $request->input('_action_token');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{48}$/D', $token), 419, 'Invalid action ticket.');
        $key = 'admin-action:'.hash('sha256', $token);
        $lock = \Illuminate\Support\Facades\Cache::lock($key.':lock', 3600);
        abort_unless($lock->get(), 409, 'This action is already running.');
        try {
            abort_if(\Illuminate\Support\Facades\Cache::has($key.':completed'), 409, 'This action was already submitted.');
            $response = $callback();
            if ($response instanceof \Illuminate\Http\JsonResponse && ($response->getData(true)['status'] ?? false) === true
                && session('admin.action_contexts.'.$token.'.phase') === 'execute') {
                \Illuminate\Support\Facades\Cache::put($key.':completed', true, 7200);
                $this->forget($token);
            }
            return $response;
        } finally {
            $lock->release();
        }
    }

    public function register(Action $action): string
    {
        $descriptor = $action->contextDescriptor();
        $source = request()->attributes->get('_admin_component_source', [request()->url(), request()->method()]);
        $definition = ['descriptor' => $descriptor, 'identity' => $this->identity(),
            'source' => $source[0], 'source_method' => $source[1], 'mode' => $action->interaction()];
        $hash = hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
        $tickets = $this->tickets();
        $token = $this->definitions[$hash] ?? null;
        if (!$token || !isset($tickets[$token])) {
            $token = bin2hex(random_bytes(24));
            $this->definitions[$hash] = $token;
            $tickets[$token] = $definition + ['phase' => 'definition', 'expires' => time() + 7200, 'keys' => []];
        }
        foreach ($action->contextKeys() as $key) {
            $tickets[$token]['keys'][(string) $key] = true;
        }
        $this->store($tickets);
        return $token;
    }

    public function resolve(Request $request, bool $loading = false): array
    {
        $token = $request->input('_action_token');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{48}$/D', $token), 419, 'Invalid action ticket.');
        $tickets = $this->tickets();
        $ticket = $tickets[$token] ?? null;
        abort_unless($ticket && $ticket['expires'] >= time(), 419, 'Expired action ticket.');
        abort_unless(Admin::user() && $ticket['identity'] === $this->identity(), 403);
        $definition = $ticket['phase'] === 'execute' ? ($tickets[$ticket['definition']] ?? null) : $ticket;
        abort_unless($definition && $definition['expires'] >= time(), 419);
        FormSubmission::authorizeSource($definition['source'], $definition['source_method']);
        abort_if($loading && $ticket['phase'] !== 'definition', 400);
        abort_if(!$loading && $definition['mode'] !== 'direct' && $ticket['phase'] !== 'execute', 419, 'Open the action first.');
        $descriptor = $definition['descriptor'];
        $keys = $ticket['phase'] === 'execute' ? $ticket['selected'] : $request->input('_key', []);
        $keys = is_array($keys) ? array_values($keys) : [$keys];

        // Toolbar actions render a placeholder key (usually 0) but have no
        // record context. Ignore it instead of rejecting a valid form load or
        // execution with "Record is outside this action".
        $recordScoped = !empty($descriptor['record']) || !empty($descriptor['batch']);
        if (!$recordScoped && empty($definition['keys'])) {
            $keys = [];
        }

        abort_if(count($keys) > 1000, 422, 'Too many selected records.');
        foreach ($keys as $key) {
            abort_unless((is_string($key) || is_int($key)) && isset($definition['keys'][(string) $key]), 403, 'Record is outside this action.');
        }
        $class = $descriptor['class'];
        abort_unless(is_subclass_of($class, Action::class), 400);
        $action = app($class);
        $action->restoreContext($descriptor['state']);
        $model = null;
        if ($descriptor['record']) {
            abort_unless($keys && ($descriptor['batch'] || count($keys) === 1), 422, 'Select a record.');
            $record = $descriptor['record'];
            $query = $this->query($record);
            $models = $query->whereKey($keys)->get();
            abort_unless($models->count() === count(array_unique($keys)), 404);
            $model = $descriptor['batch'] ? $models : $models->first();
        } else {
            $model = $action->contextModel();
        }
        $action->setResolvedModel($model);
        if ($action instanceof RowAction) {
            $action->setRow($model);
        }
        abort_unless($action->passesAuthorization($model), 403, trans('admin.deny'));
        if (!$loading && $ticket['phase'] === 'execute') {
            abort_unless($ticket['versions'] === $this->versions($model), 409, 'The record changed. Reopen the action.');
        }
        // Execution controls and configured parameters always come from the registered definition.
        $values = $request->input();
        foreach (['_action', '_model', '_class', '_cb', '_key'] as $key) {
            unset($values[$key]);
        }
        $values = array_replace($values, $descriptor['parameters']);
        if ($keys) {
            $values['_key'] = $descriptor['batch'] ? $keys : $keys[0];
        }
        $request = \Encore\Admin\Form\SubmissionInput::replace($request, $values, $request->allFiles());
        return [$action, $model, $token, $keys];
    }

    public function executionTicket(string $definition, array $keys, $model, int $lifetime = 1800): string
    {
        $tickets = $this->tickets();
        $token = bin2hex(random_bytes(24));
        $tickets[$token] = ['phase' => 'execute', 'definition' => $definition, 'selected' => $keys,
            'versions' => $this->versions($model), 'identity' => $this->identity(),
            'expires' => min(time() + $lifetime, $tickets[$definition]['expires'])];
        $this->store($tickets);
        return $token;
    }

    public function forget(string $token): void
    {
        if (session('admin.action_contexts.'.$token.'.phase') === 'execute') {
            session()->forget('admin.action_contexts.'.$token);
        }
    }

    private function identity(): array
    {
        return [(string) (Admin::user()?->getAuthIdentifier() ?? ''), config('admin.auth.guard', 'admin'),
            config('admin.route.prefix'), config('admin.database.connection')];
    }

    private function tickets(): array
    {
        return array_filter(session('admin.action_contexts', []), static fn ($ticket) => $ticket['expires'] >= time());
    }

    private function store(array $tickets): void
    {
        session()->put('admin.action_contexts', array_slice($tickets, -512, null, true));
    }

    public static function modelReference(Model $model): array
    {
        return ['class' => get_class($model), 'key' => $model->getKey(), 'connection' => $model->getConnectionName(),
            'with' => $model->getQueueableRelations()];
    }

    public function query(array $reference)
    {
        $class = $reference['class'];
        abort_unless(is_subclass_of($class, Model::class), 400);
        $model = (new $class)->setConnection($reference['connection']);
        $query = $model->newQuery()->with($reference['with']);
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }
        return $query;
    }

    private function versions($model): array
    {
        $versions = [];
        foreach ($model instanceof \Illuminate\Database\Eloquent\Collection ? $model : [$model] as $record) {
            if ($record instanceof Model && $record->usesTimestamps() && $record->getRawOriginal($record->getUpdatedAtColumn()) !== null) {
                $versions[get_class($record).':'.$record->getKey()] = (string) $record->getRawOriginal($record->getUpdatedAtColumn());
            }
        }
        ksort($versions);
        return $versions;
    }
}
