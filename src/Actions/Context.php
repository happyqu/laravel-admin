<?php

namespace Encore\Admin\Actions;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Security\FormSubmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

/** Signed action parameters, without serialized objects or expiring session entries. */
class Context
{
    private array $definitions = [];

    public function execute(Request $request, callable $callback)
    {
        $token = $request->input('_action_token');
        $ticket = $this->read($token);
        $key = $this->executionKey($ticket);
        $lock = \Illuminate\Support\Facades\Cache::lock($key.':lock', 3600);
        abort_unless($lock->get(), 409, 'This action is already running.');
        try {
            abort_if(\Illuminate\Support\Facades\Cache::has($key.':completed'), 409, 'This action was already submitted.');
            $response = $callback();
            if ($response instanceof \Illuminate\Http\JsonResponse && ($response->getData(true)['status'] ?? false) === true
                && $ticket['mode'] !== 'direct') {
                // Tickets remain valid for the login session, so completion cannot expire sooner.
                \Illuminate\Support\Facades\Cache::forever($key.':completed', true);
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
        $model = $action instanceof RowAction ? $action->getRow() : $action->contextModel();
        $ticket = ['action' => $descriptor['class'], 'parameters' => $descriptor['parameters'],
            'record' => $descriptor['record'], 'keys' => array_map('strval', $action->contextKeys()),
            'source' => $source, 'mode' => $action->interaction()];
        $ticket['authorization_version'] = (string) config('admin.actions.authorization_version', '1');
        if ($rules = $action->authorizationConfiguration()) $ticket['authorization'] = $rules;
        if ($descriptor['state']) $ticket['options'] = $descriptor['state'];
        if ($versions = $this->versions($model)) $ticket['versions'] = $versions;
        $hash = hash('sha256', json_encode($ticket, JSON_THROW_ON_ERROR));

        return $this->definitions[$hash] ??= ContextToken::encode($ticket + ['id' => bin2hex(random_bytes(16))]);
    }

    public function resolve(Request $request, bool $loading = false): array
    {
        $token = $request->input('_action_token');
        $ticket = $this->read($token);
        FormSubmission::authorizeSource(...$ticket['source']);
        if (!$loading && $ticket['mode'] === 'form' && empty($ticket['loaded'])) {
            ContextToken::fail('action_open_required');
        }
        $class = $ticket['action'];
        abort_unless(is_subclass_of($class, Action::class), 400);
        $action = app($class);
        $action->restoreContext($ticket['options'] ?? []);
        $action->restoreAuthorizationConfiguration($ticket['authorization'] ?? []);
        $batch = $action instanceof BatchAction;
        $keys = !$loading && !empty($ticket['loaded']) ? $ticket['keys'] : $request->input('_key', []);
        $keys = is_array($keys) ? array_values($keys) : [$keys];
        if (!$ticket['record'] && !$ticket['keys']) $keys = [];
        abort_if(count($keys) > 1000, 422, 'Too many selected records.');
        foreach ($keys as $key) {
            abort_unless((is_string($key) || is_int($key)) && in_array((string) $key, $ticket['keys'], true),
                403, 'Record is outside this action.');
        }
        $model = null;
        if ($ticket['record']) {
            abort_unless($keys && ($batch || count($keys) === 1), 422, 'Select a record.');
            $models = $this->query($ticket['record'])->whereKey($keys)->get();
            abort_unless($models->count() === count(array_unique($keys)), 404);
            $model = $batch ? $models : $models->first();
        }
        $action->setResolvedModel($model);
        if ($action instanceof RowAction) $action->setRow($model);
        abort_unless($action->passesAuthorization($model), 403, trans('admin.deny'));
        if (!$loading && isset($ticket['versions'])) {
            $expected = array_intersect_key($ticket['versions'], array_fill_keys(array_map('strval', $keys), true));
            if ($expected !== $this->versions($model)) ContextToken::fail('action_record_changed');
        }
        $values = $request->input();
        foreach (['_action', '_model', '_class', '_cb', '_key'] as $key) unset($values[$key]);
        $values = array_replace($values, $ticket['parameters']);
        if ($keys) $values['_key'] = $batch ? $keys : $keys[0];
        $clean = \Encore\Admin\Form\SubmissionInput::replace($request, $values, $request->allFiles());
        $clean->attributes->set('_admin_component_source', $ticket['source']);
        $trusted = $ticket['parameters'];
        $trusted['_action_token'] = $token;
        if ($keys) $trusted['_key'] = $batch ? $keys : $keys[0];
        if (is_string($request->input('_token'))) $trusted['_token'] = $request->input('_token');
        $clean->attributes->set('_admin_action_parameters', $trusted);

        return [$action, $model, $token, $keys];
    }

    /** Bind the form to its selected records and the version the user actually saw. */
    public function executionTicket(string $token, array $keys, $model): string
    {
        $ticket = $this->read($token);
        foreach ($keys as $key) abort_unless(in_array((string) $key, $ticket['keys'], true), 403);
        $ticket['keys'] = array_map('strval', $keys);
        $ticket['loaded'] = true;
        $ticket['id'] = bin2hex(random_bytes(16));
        unset($ticket['versions']);
        if ($versions = $this->versions($model)) $ticket['versions'] = $versions;

        return ContextToken::encode($ticket);
    }

    public function forget(string $token): void
    {
        \Illuminate\Support\Facades\Cache::forever($this->executionKey($this->read($token)).':completed', true);
    }

    private function read($token): array
    {
        abort_unless(Admin::user(), 401);
        $ticket = ContextToken::decode($token);
        if (($ticket['authorization_version'] ?? '1') !== (string) config('admin.actions.authorization_version', '1')) {
            ContextToken::fail('action_context_invalid');
        }
        return $ticket;
    }

    private function executionKey(array $ticket): string
    {
        return 'admin-action:'.hash('sha256', $ticket['id']);
    }

    public static function modelReference(Model $model): array
    {
        return array_filter(['class' => get_class($model), 'key' => $model->getKey(),
            'connection' => $model->getConnectionName()], static fn ($value) => $value !== null);
    }

    public function query(array $reference)
    {
        $class = $reference['class'];
        abort_unless(is_subclass_of($class, Model::class), 400);
        $model = (new $class)->setConnection($reference['connection'] ?? null);
        $query = $model->newQuery()->with($reference['with'] ?? []);
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }
        return $query;
    }

    private function versions($model): array
    {
        $versions = [];
        foreach ($model instanceof \Illuminate\Support\Collection ? $model : [$model] as $record) {
            if ($record instanceof Model && $record->usesTimestamps() && $record->getRawOriginal($record->getUpdatedAtColumn()) !== null) {
                $versions[(string) $record->getKey()] = (string) $record->getRawOriginal($record->getUpdatedAtColumn());
            }
        }
        ksort($versions);
        return $versions;
    }
}
