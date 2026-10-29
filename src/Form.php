<?php

namespace Encore\Admin;

use Closure;
use Encore\Admin\Form\Builder;
use Encore\Admin\Form\Concerns\HandleCascadeFields;
use Encore\Admin\Form\Concerns\HasFields;
use Encore\Admin\Form\Concerns\HasHooks;
use Encore\Admin\Form\Field;
use Encore\Admin\Form\Layout\Layout;
use Encore\Admin\Form\Row;
use Encore\Admin\Form\Tab;
use Encore\Admin\Traits\ShouldSnakeAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Spatie\EloquentSortable\Sortable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Form.
 */
class Form implements Renderable
{
    use HasHooks;
    use HasFields;
    use HandleCascadeFields;
    use ShouldSnakeAttributes;
    /**
     * Remove flag in `has many` form.
     */
    const REMOVE_FLAG_NAME = '_remove_';

    /**
     * Eloquent model of the form.
     *
     * @var Model
     */
    protected $model;

    /**
     * @var \Illuminate\Validation\Validator
     */
    protected $validator;

    /**
     * @var Builder
     */
    protected $builder;

    /**
     * Data for save to current model from input.
     *
     * @var array
     */
    protected $updates = [];

    /**
     * Data for save to model's relations from input.
     *
     * @var array
     */
    protected $relations = [];

    /**
     * Input data.
     *
     * @var array
     */
    protected $inputs = [];

    /**
     * @var Layout
     */
    protected $layout;

    /**
     * Ignored saving fields.
     *
     * @var array
     */
    protected $ignored = [];

    /**
     * Collected field assets.
     *
     * @var array
     */
    protected static $collectedAssets = [];

    /**
     * @var Form\Tab
     */
    protected $tab = null;

    /**
     * Field rows in form.
     *
     * @var array
     */
    public $rows = [];

    /**
     * @var bool
     */
    protected $isSoftDeletes = false;

    protected bool $partialSubmission = false;

    /**
     * Create a new form instance.
     *
     * @param $model
     * @param \Closure $callback
     */
    public function __construct($model, ?Closure $callback = null)
    {
        $this->model = $model;

        $this->builder = new Builder($this);

        $this->initLayout();

        if ($callback instanceof Closure) {
            $callback($this);
        }

        $this->isSoftDeletes = in_array(SoftDeletes::class, class_uses_deep($this->model), true);

        $this->callInitCallbacks();
    }

    /**
     * @param Field $field
     *
     * @return $this
     */
    public function pushField(Field $field): self
    {
        $field->setForm($this);

        $width = $this->builder->getWidth();
        $field->setWidth($width['field'], $width['label']);

        $this->fields()->push($field);
        $this->layout->addField($field);

        return $this;
    }

    /**
     * @return Model
     */
    public function model(): Model
    {
        return $this->model;
    }

    /**
     * @return Builder
     */
    public function builder(): Builder
    {
        return $this->builder;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function fields()
    {
        return $this->builder()->fields();
    }

    /**
     * Generate a edit form.
     *
     * @param $id
     *
     * @return $this
     */
    public function edit($id): self
    {
        $this->builder->setMode(Builder::MODE_EDIT);
        $this->builder->setResourceId($id);

        $this->setRelationFieldSnakeAttributes();

        $this->setFieldValue($id);

        return $this;
    }

    /**
     * Use tab to split form.
     *
     * @param string  $title
     * @param Closure $content
     * @param bool    $active
     *
     * @return $this
     */
    public function tab($title, Closure $content, bool $active = false): self
    {
        $this->setTab()->append($title, $content, $active);

        return $this;
    }

    /**
     * Get Tab instance.
     *
     * @return Tab
     */
    public function getTab()
    {
        return $this->tab;
    }

    /**
     * Set Tab instance.
     *
     * @return Tab
     */
    public function setTab(): Tab
    {
        if ($this->tab === null) {
            $this->tab = new Tab($this);
        }

        return $this->tab;
    }

    /**
     * Destroy data entity and remove files.
     *
     * @param $id
     *
     * @return mixed
     */
    public function destroy($id)
    {
        try {
            if (($ret = $this->callDeleting($id)) instanceof Response) {
                return $ret;
            }

            app(Form\UploadTransaction::class)->run($this->model->getConnection(), function () use ($id) {
                collect(explode(',', $id))->filter()->each(function ($id) {
                    $builder = $this->model()->newQuery();

                    if ($this->isSoftDeletes) {
                        $builder = $builder->withTrashed();
                    }

                    $model = $builder->with($this->getRelations())->findOrFail($id);

                    if ($this->isSoftDeletes && $model->trashed()) {
                        $this->deleteFiles($model, true);
                        if ($model->forceDelete() === false) {
                            throw new \RuntimeException('Model deletion was cancelled.');
                        }

                        return;
                    }

                    $this->deleteFiles($model);
                    if ($model->delete() === false) {
                        throw new \RuntimeException('Model deletion was cancelled.');
                    }
                });
            });

            if (($ret = $this->callDeleted()) instanceof Response) {
                return $ret;
            }

            $response = [
                'status'  => true,
                'message' => trans('admin.delete_succeeded'),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            $response = [
                'status'  => false,
                'message' => config('app.debug') ? $exception->getMessage() : trans('admin.delete_failed'),
            ];
        }

        return response()->json($response);
    }

    /**
     * Remove files in record.
     *
     * @param Model $model
     * @param bool  $forceDelete
     */
    protected function deleteFiles(Model $model, $forceDelete = false)
    {
        // If it's a soft delete, the files in the data will not be deleted.
        if (!$forceDelete && $this->isSoftDeletes) {
            return;
        }

        $data = $model->toArray();

        $this->fields()->filter(function ($field) {
            return $field instanceof Field\File || $field instanceof Field\MultipleFile;
        })->each(function ($file) use ($data) {
            $file->setOriginal($data);
            if ($file instanceof Field\MultipleFile) {
                $file->destroyAll();
            } else {
                $file->destroy();
            }
        });
    }

    /**
     * Store a new record.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\Http\JsonResponse
     */
    public function store()
    {
        $this->partialSubmission = false;
        $data = \request()->all();

        // Handle validation errors.
        if ($validationMessages = $this->validationMessages($data)) {
            return $this->responseValidationError($validationMessages);
        }

        if (($response = $this->prepare($data)) instanceof Response) {
            return $response;
        }

        $response = $this->persist(function () {
            $inserts = $this->prepareInsert($this->updates);

            foreach ($inserts as $column => $value) {
                $this->model->setAttribute($column, $value);
            }

            $this->saveModel($this->model);

            $this->updateRelation($this->relations);
        });
        if ($response instanceof Response) {
            return $response;
        }

        if (($response = $this->callSaved()) instanceof Response) {
            return $response;
        }

        if ($response = $this->ajaxResponse(trans('admin.save_succeeded'))) {
            return $response;
        }

        return $this->redirectAfterStore();
    }

    /**
     * @param MessageBag $message
     *
     * @return $this|\Illuminate\Http\JsonResponse
     */
    protected function responseValidationError(MessageBag $message)
    {
        return Form\ValidationResponse::make($message);
    }

    /**
     * Get ajax response.
     *
     * @param string $message
     *
     * @return bool|\Illuminate\Http\JsonResponse
     */
    protected function ajaxResponse($message)
    {
        $request = \request();

        if ($request->expectsJson()) {
            return response()->json([
                'status'    => true,
                'message'   => $message,
                'display'   => $this->applayFieldDisplay(),
            ]);
        }

        return false;
    }

    /**
     * @return array
     */
    protected function applayFieldDisplay()
    {
        $editable = [];
        $model = null;

        /** @var Field $field */
        foreach ($this->fields() as $field) {
            if (!$field->isWritable() || !is_string($field->column())
                || !\request()->has($field->column())
                || (!$field instanceof Field\BelongsTo && !$field instanceof Field\BelongsToMany)) {
                continue;
            }
            $selectable = $field->getSelectable();
            if (!method_exists($selectable, 'display')) {
                continue;
            }
            $model ??= $this->model->fresh();
            if (!$model) {
                continue;
            }
            $newValue = $model->getAttribute($field->column());

            if ($newValue instanceof Arrayable) {
                $newValue = $newValue->toArray();
            }

            $display = $selectable::display();
            $editable[$field->column()] = $display->call($this->model, $newValue);
        }

        return $editable;
    }

    /**
     * Prepare input data for insert or update.
     *
     * @param array $data
     *
     * @return mixed
     */
    protected function prepare($data = [])
    {
        if (($response = $this->callSubmitted()) instanceof Response) {
            return $response;
        }

        $this->inputs = array_merge($this->removeIgnoredFields($data), $this->inputs);
        $this->inputs = Form\Cascade::filterInput($this->fields(), $this->inputs, $this->cascadeInputContext($this->inputs));

        if (($response = $this->callSaving()) instanceof Response) {
            return $response;
        }

        $this->inputs = Form\Cascade::filterInput($this->fields(), $this->inputs, $this->cascadeInputContext($this->inputs));

        $this->relations = $this->getRelationInputs($this->inputs);

        $this->updates = Arr::except($this->inputs, array_keys($this->relations));
    }

    /**
     * Remove ignored fields from input.
     *
     * @param array $input
     *
     * @return array
     */
    protected function removeIgnoredFields($input): array
    {
        Arr::forget($input, $this->ignored);

        return $input;
    }

    /**
     * Get inputs for relations.
     *
     * @param array $inputs
     *
     * @return array
     */
    protected function getRelationInputs($inputs = []): array
    {
        $relations = [];
        $allowed = [];
        foreach ($this->fields() as $field) {
            if (!$field->isWritable()) {
                continue;
            }
            foreach ((array) $field->column() as $column) {
                if (is_string($column) && $column !== '') {
                    $allowed[explode('.', $column, 2)[0]] = true;
                }
            }
        }
        foreach (array_intersect_key($inputs, $allowed) as $column => $value) {
            if ((method_exists($this->model, $column) ||
                method_exists($this->model, $column = Str::camel($column))) &&
                !method_exists(Model::class, $column)
            ) {
                $relation = call_user_func([$this->model, $column]);

                if ($relation instanceof Relations\Relation) {
                    $relations[$column] = $value;
                }
            }
        }

        return $relations;
    }

    /**
     * Handle update.
     *
     * @param int  $id
     * @param null $data
     *
     * @return bool|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|mixed|null|Response
     */
    public function update($id, $data = null)
    {
        $data = $data ?? request()->all();

        $isEditable = $this->isEditable($data);
        $this->partialSubmission = $isEditable || request()->isMethod('PATCH')
            || array_key_exists(Field::FILE_DELETE_FLAG, $data) || array_key_exists(Field::FILE_SORT_FLAG, $data);

        if (($data = $this->handleColumnUpdates($id, $data)) instanceof Response) {
            return $data;
        }

        /* @var Model $this ->model */
        $builder = $this->model();

        if ($this->isSoftDeletes) {
            $builder = $builder->withTrashed();
        }

        $this->model = $builder->with($this->getRelations())->findOrFail($id);

        $this->setFieldOriginalValue();

        // Handle validation errors.
        if ($validationMessages = $this->validationMessages($data)) {
            return $this->responseValidationError($validationMessages);
        }

        if (($response = $this->prepare($data)) instanceof Response) {
            return $response;
        }

        $response = $this->persist(function () {
            $updates = $this->prepareUpdate($this->updates);

            foreach ($updates as $column => $value) {
                /* @var Model $this ->model */
                $this->model->setAttribute($column, $value);
            }

            $this->saveModel($this->model);

            $this->updateRelation($this->relations);
        });
        if ($response instanceof Response) {
            return $response;
        }

        if (($result = $this->callSaved()) instanceof Response) {
            return $result;
        }

        if ($response = $this->ajaxResponse(trans('admin.update_succeeded'))) {
            return $response;
        }

        return $this->redirectAfterUpdate($id);
    }

    /**
     * Get RedirectResponse after store.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectAfterStore()
    {
        $resourcesPath = $this->resource(0);

        $key = $this->model->getKey();

        return $this->redirectAfterSaving($resourcesPath, $key);
    }

    /**
     * Get RedirectResponse after update.
     *
     * @param mixed $key
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectAfterUpdate($key)
    {
        $resourcesPath = $this->resource(-1);

        return $this->redirectAfterSaving($resourcesPath, $key);
    }

    /**
     * Get RedirectResponse after data saving.
     *
     * @param string $resourcesPath
     * @param string $key
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function redirectAfterSaving($resourcesPath, $key)
    {
        if (request('after-save') == 1) {
            // continue editing
            $url = rtrim($resourcesPath, '/')."/{$key}/edit";
        } elseif (request('after-save') == 2) {
            // continue creating
            $url = rtrim($resourcesPath, '/').'/create';
        } elseif (request('after-save') == 3) {
            // view resource
            $url = rtrim($resourcesPath, '/')."/{$key}";
        } else {
            $previous = request(Builder::PREVIOUS_URL_KEY);
            $url = $this->isSafeRedirectUrl($previous) ? $previous : $resourcesPath;
        }

        if (request()->header('X-Admin-Navigation') === 'true') {
            // AJAX navigation chooses the destination without first following a 302
            // inside an editor iframe that will immediately be closed.
            request()->session()->forget(['_iframe_id_', 'after_save']);

            return response()->json([
                'status' => true,
                'message' => trans('admin.save_succeeded'),
                'redirect' => $url,
                'after_save' => (string) request('after-save', ''),
            ]);
        }

        admin_toastr(trans('admin.save_succeeded'));

        return redirect($url);
    }

    /**
     * Only allow same-host absolute URLs and root-relative paths.
     */
    protected function isSafeRedirectUrl($url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        if (isset($parts['host'])) {
            return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                && strcasecmp((string) $parts['host'], request()->getHost()) === 0;
        }

        return str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && !str_starts_with($url, '\\');
    }

    /**
     * Check if request is from editable.
     *
     * @param array $input
     *
     * @return bool
     */
    protected function isEditable(array $input = []): bool
    {
        return array_key_exists('_editable', $input) || array_key_exists('_edit_inline', $input);
    }

    /**
     * Handle updates for single column.
     *
     * @param int   $id
     * @param array $data
     *
     * @return array|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response|Response
     */
    protected function handleColumnUpdates($id, $data)
    {
        $data = $this->handleEditable($data);

        $data = $this->handleFileDelete($data);

        $data = $this->handleFileSort($data);

        if ($this->handleOrderable($id, $data)) {
            return response([
                'status'  => true,
                'message' => trans('admin.update_succeeded'),
            ]);
        }

        return $data;
    }

    /**
     * Handle editable update.
     *
     * @param array $input
     *
     * @return array
     */
    protected function handleEditable(array $input = []): array
    {
        if (array_key_exists('_editable', $input)) {
            $name = $input['name'];
            $value = $input['value'];

            Arr::forget($input, ['pk', 'value', 'name']);
            Arr::set($input, $name, $value);
        }

        return $input;
    }

    /**
     * @param array $input
     *
     * @return array
     */
    protected function handleFileDelete(array $input = []): array
    {
        if (array_key_exists(Field::FILE_DELETE_FLAG, $input)) {
            if (!array_key_exists('key', $input) || (!is_string($input['key']) && !is_int($input['key']))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['key' => 'Invalid file key.']);
            }
            $input[Field::FILE_DELETE_FLAG] = $input['key'];
            unset($input['key']);
        }

        request()->replace($input);

        return $input;
    }

    /**
     * @param array $input
     *
     * @return array
     */
    protected function handleFileSort(array $input = []): array
    {
        if (!array_key_exists(Field::FILE_SORT_FLAG, $input)) {
            return $input;
        }

        if (!is_array($input[Field::FILE_SORT_FLAG])) {
            throw \Illuminate\Validation\ValidationException::withMessages([Field::FILE_SORT_FLAG => 'Expected an array.']);
        }
        $sorts = array_filter($input[Field::FILE_SORT_FLAG]);

        if (empty($sorts)) {
            return $input;
        }

        foreach ($sorts as $column => $order) {
            $input[$column] = $order;
        }

        request()->replace($input);

        return $input;
    }

    /**
     * Handle orderable update.
     *
     * @param int   $id
     * @param array $input
     *
     * @return bool
     */
    protected function handleOrderable($id, array $input = [])
    {
        if (array_key_exists('_orderable', $input)) {
            $model = $this->model->find($id);

            if ($model instanceof Sortable) {
                $input['_orderable'] == 1 ? $model->moveOrderUp() : $model->moveOrderDown();

                return true;
            }
        }

        return false;
    }

    /**
     * Persist database and file changes on the model connection.
     *
     * @param callable $callback
     *
     * @return Response|null
     */
    protected function persist(callable $callback)
    {
        try {
            $this->assertRelationConnections();
            app(Form\UploadTransaction::class)->run($this->model->getConnection(), $callback);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->responseValidationError(new MessageBag($exception->errors()));
        }
    }

    protected function assertRelationConnections(): void
    {
        foreach (array_keys($this->relations) as $name) {
            $relation = $this->model->$name();
            if ($relation->getRelated()->getConnection() !== $this->model->getConnection()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $name => 'Related records must use the same database connection as the form model.',
                ]);
            }
        }
    }

    protected function saveModel(Model $model): void
    {
        if ($model->save() === false) {
            throw new \RuntimeException('Model save was cancelled.');
        }
    }

    /** Save the submitted relations without bypassing model events. */
    protected function updateRelation($relationsData)
    {
        foreach ($relationsData as $name => $values) {
            if (!method_exists($this->model, $name)) {
                continue;
            }

            $relation = $this->model->$name();

            $oneToOneRelation = $relation instanceof Relations\HasOne
                || $relation instanceof Relations\MorphOne
                || $relation instanceof Relations\BelongsTo;

            $prepared = $this->prepareUpdate([$name => $values], $oneToOneRelation);

            if (empty($prepared)) {
                continue;
            }

            switch (true) {
                case $relation instanceof Relations\BelongsToMany:
                case $relation instanceof Relations\MorphToMany:
                    if (isset($prepared[$name])) {
                        $relation->sync($prepared[$name]);
                    }
                    break;
                case $relation instanceof Relations\HasOne:
                case $relation instanceof Relations\MorphOne:
                    $related = $this->model->getRelationValue($name) ?: $relation->getRelated();

                    foreach ($prepared[$name] as $column => $value) {
                        $related->setAttribute($column, $value);
                    }

                    // save child
                    if ($relation->save($related) === false) {
                        throw new \RuntimeException('Related model save was cancelled.');
                    }
                    break;
                case $relation instanceof Relations\BelongsTo:
                case $relation instanceof Relations\MorphTo:
                    $related = $this->model->getRelationValue($name) ?: $relation->getRelated();

                    foreach ($prepared[$name] as $column => $value) {
                        $related->setAttribute($column, $value);
                    }

                    // save parent
                    $this->saveModel($related);

                    // save child (self)
                    $this->saveModel($relation->associate($related));
                    break;
                case $relation instanceof Relations\HasMany:
                case $relation instanceof Relations\MorphMany:
                    /** @var Relations\HasOneOrMany $relation */
                    $relation = $this->model->$name();

                    $data = $prepared[$name];
                    if ($this->getFieldByColumn($name) instanceof Field\HasMany) {
                        $keyName = $relation->getRelated()->getKeyName();
                        $ids = array_filter(array_column($data, $keyName), static fn ($id) => $id !== null && $id !== '');
                        $children = $ids ? $relation->whereKey($ids)->get()->keyBy($keyName) : collect();
                        foreach ($data as $related) {
                            $id = Arr::get($related, $keyName);
                            if ($id !== null && $id !== '' && !$children->has($id)) {
                                throw \Illuminate\Validation\ValidationException::withMessages([$name => 'Invalid related record.']);
                            }
                            $child = $id !== null && $id !== '' ? $children->get($id) : $relation->make();

                            if (Arr::get($related, static::REMOVE_FLAG_NAME) == 1) {
                                if ($child->exists && $child->delete() === false) {
                                    throw new \RuntimeException('Related model deletion was cancelled.');
                                }
                                continue;
                            }

                            Arr::forget($related, [static::REMOVE_FLAG_NAME, $keyName]);

                            $child->fill($related);

                            $this->saveModel($child);
                        }
                    } else { //relation updated via MultipleSelect field
                        $foreignKeyName = $relation->getForeignKeyName();
                        $localKeyName = $relation->getLocalKeyName();

                        $selected = $relation->getRelated()->newQuery()->whereKey($data)->get()->keyBy($relation->getRelated()->getKeyName());
                        foreach ($data as $id) {
                            if (!$selected->has($id)) {
                                throw \Illuminate\Validation\ValidationException::withMessages([$name => 'Invalid related record.']);
                            }
                        }
                        foreach ($this->model->$name()->get() as $child) {
                            if (($ind = array_search($child->getKey(), $data)) !== false) {
                                unset($data[$ind]);
                            } else {
                                $child->$foreignKeyName = null;
                                $this->saveModel($child);
                            }
                        }

                        foreach ($data as $id) {
                            $child = $selected->get($id);
                            $child->$foreignKeyName = $this->model->$localKeyName;
                            $this->saveModel($child);
                        }
                    }
                    break;
            }
        }
    }

    /**
     * Prepare input data for update.
     *
     * @param array $updates
     * @param bool  $oneToOneRelation If column is one-to-one relation.
     *
     * @return array
     */
    protected function prepareUpdate(array $updates, $oneToOneRelation = false): array
    {
        $context = $this->cascadeInputContext($updates);
        $updates = Form\Cascade::filterInput($this->fields(), $updates, $context);
        $prepared = [];

        /** @var Field $field */
        foreach ($this->fields() as $field) {
            if (!$field->isWritable() || !$field->isCascadeActive($context)) {
                continue;
            }
            $columns = $field->column();

            // If column not in input array data, then continue.
            if (!Arr::has($updates, $columns)) {
                continue;
            }

            if ($this->isInvalidColumn($columns, $oneToOneRelation || $field->isJsonType)) {
                continue;
            }

            $value = $this->getDataByColumn($updates, $columns);

            $value = $field->prepare($value);

            if (is_array($columns)) {
                foreach ($columns as $name => $column) {
                    Arr::set($prepared, $column, $value[$name]);
                }
            } elseif (is_string($columns)) {
                Arr::set($prepared, $columns, $value);
            }
        }

        return $prepared;
    }

    /**
     * @param string|array $columns
     * @param bool         $containsDot
     *
     * @return bool
     */
    protected function isInvalidColumn($columns, $containsDot = false): bool
    {
        foreach ((array) $columns as $column) {
            if ((!$containsDot && Str::contains($column, '.')) ||
                ($containsDot && !Str::contains($column, '.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare input data for insert.
     *
     * @param $inserts
     *
     * @return array
     */
    protected function prepareInsert($inserts): array
    {
        $context = $this->cascadeInputContext($inserts);
        $inserts = Form\Cascade::filterInput($this->fields(), $inserts, $context);
        if ($this->isHasOneRelation($inserts)) {
            $inserts = Arr::dot($inserts);
        }

        $indexedFields = $inserts && (new \ReflectionMethod($this, 'getFieldByColumn'))->getDeclaringClass()->getName() === self::class
            ? $this->indexFieldsByColumn() : null;

        foreach ($inserts as $column => $value) {
            $field = $indexedFields !== null && is_string($column) && !is_numeric($column)
                ? ($indexedFields[$column] ?? null) : $this->getFieldByColumn($column);
            if ($field && !$field->isCascadeActive($context)) {
                $field = $this->fields()->first(fn ($candidate) => in_array($column, (array) $candidate->column())
                    && $candidate->isCascadeActive($context));
            }
            if ($field === null || !$field->isWritable()) {
                unset($inserts[$column]);
                continue;
            }

            $inserts[$column] = $field->prepare($value);

            // An overridden preparer can change fields or their columns during this batch.
            if ($indexedFields !== null
                && (new \ReflectionMethod($field, 'prepare'))->getDeclaringClass()->getName() !== Field::class) {
                $indexedFields = null;
            }
        }

        $prepared = [];

        foreach ($inserts as $key => $value) {
            Arr::set($prepared, $key, $value);
        }

        return $prepared;
    }

    /** Index stable string columns in declaration order; other comparisons use the normal lookup. */
    protected function indexFieldsByColumn(): ?array
    {
        $index = $defaultGetters = [];
        foreach ($this->fields() as $field) {
            $class = get_class($field);
            $defaultGetters[$class] ??= (new \ReflectionMethod($field, 'column'))->getDeclaringClass()->getName() === Field::class;
            if (!$defaultGetters[$class]) {
                return null;
            }

            $columns = $field->column();
            foreach (is_array($columns) ? $columns : [$columns] as $column) {
                if (!is_string($column) || $column === '' || is_numeric($column)) {
                    return null;
                }
                $index[$column] ??= $field;
            }
        }

        return $index;
    }

    /**
     * Is input data is has-one relation.
     *
     * @param array $inserts
     *
     * @return bool
     */
    protected function isHasOneRelation($inserts): bool
    {
        $first = current($inserts);

        if (!is_array($first)) {
            return false;
        }

        if (is_array(current($first))) {
            return false;
        }

        return Arr::isAssoc($first);
    }

    /**
     * Ignore fields to save.
     *
     * @param string|array $fields
     *
     * @return $this
     */
    public function ignore($fields): self
    {
        $this->ignored = array_merge($this->ignored, (array) $fields);

        return $this;
    }

    /**
     * @param array        $data
     * @param string|array $columns
     *
     * @return array|mixed
     */
    protected function getDataByColumn($data, $columns)
    {
        if (is_string($columns)) {
            return Arr::get($data, $columns);
        }

        if (is_array($columns)) {
            $value = [];
            foreach ($columns as $name => $column) {
                if (!Arr::has($data, $column)) {
                    continue;
                }
                $value[$name] = Arr::get($data, $column);
            }

            return $value;
        }
    }

    /**
     * Find field object by column.
     *
     * @param $column
     *
     * @return mixed
     */
    protected function getFieldByColumn($column)
    {
        return $this->fields()->first(
            function (Field $field) use ($column) {
                if (is_array($field->column())) {
                    return in_array($column, $field->column());
                }

                return $field->column() == $column;
            }
        );
    }

    /**
     * Set original data for each field.
     *
     * @return void
     */
    protected function setFieldOriginalValue()
    {
        $values = $this->model->toArray();

        $this->fields()->each(function (Field $field) use ($values) {
            $field->setOriginal($values);
        });
    }

    /**
     * Determine relational column needs to be snaked.
     *
     * @return void
     */
    protected function setRelationFieldSnakeAttributes()
    {
        $relations = $this->getRelations();

        $this->fields()->each(function (Field $field) use ($relations) {
            if ($field->getSnakeAttributes()) {
                return;
            }

            $column = $field->column();

            $column = is_array($column) ? head($column) : $column;

            list($relation) = explode('.', $column);

            if (!in_array($relation, $relations)) {
                return;
            }

            $field->setSnakeAttributes($this->model::$snakeAttributes);
        });
    }

    /**
     * Set all fields value in form.
     *
     * @param $id
     *
     * @return void
     */
    protected function setFieldValue($id)
    {
        $relations = $this->getRelations();

        $builder = $this->model();

        if ($this->isSoftDeletes) {
            $builder = $builder->withTrashed();
        }

        $this->model = $builder->with($relations)->findOrFail($id);

        $this->callEditing();

        $data = $this->model->toArray();

        $this->fields()->each(function (Field $field) use ($data) {
            if (!in_array($field->column(), $this->ignored, true)) {
                $field->fill($data);
            }
        });
    }

    /**
     * Add a fieldset to form.
     *
     * @param string  $title
     * @param Closure $setCallback
     *
     * @return Field\Fieldset
     */
    public function fieldset(string $title, Closure $setCallback)
    {
        $fieldset = new Field\Fieldset();

        $this->html($fieldset->start($title))->plain();

        $setCallback($this);

        $this->html($fieldset->end())->plain();

        return $fieldset;
    }

    /**
     * Get validation messages.
     *
     * @param array $input
     *
     * @return MessageBag|bool
     */
    public function validationMessages($input)
    {
        $failedValidators = Form\SubmissionValidator::failed($this->fields(), $input,
            fn ($field) => !$this->partialSubmission || Arr::has($input, $field->column()), $this->cascadeInputContext($input));

        $message = $this->mergeValidationMessages($failedValidators);

        return $message->any() ? $message : false;
    }

    protected function cascadeInputContext(array $input): array
    {
        $input = Form\Cascade::mergeContext($this->inputs, $input);
        $needsStored = $this->fields()->contains(fn ($field) => method_exists($field, 'hasCascadeConditions')
            && $field->hasCascadeConditions()
            && (!$field->isWritable() || ($this->partialSubmission && !Arr::has($input, $field->column()))));

        return Form\Cascade::context($this->fields(), $input, $needsStored ? $this->model()->toArray() : [], $this->partialSubmission);
    }

    /**
     * Merge validation messages from input validators.
     *
     * @param \Illuminate\Validation\Validator[] $validators
     *
     * @return MessageBag
     */
    protected function mergeValidationMessages($validators): MessageBag
    {
        $messageBag = new MessageBag();

        foreach ($validators as $validator) {
            $messageBag = $messageBag->merge($validator->messages());
        }

        return $messageBag;
    }

    /**
     * Get all relations of model from callable.
     *
     * @return array
     */
    public function getRelations(): array
    {
        $relations = $columns = [];
        $checkedRelations = [];

        /** @var Field $field */
        foreach ($this->fields() as $field) {
            $columns[] = $field->column();
        }

        foreach (Arr::flatten($columns) as $column) {
            if (Str::contains($column, '.')) {
                list($relation) = explode('.', $column);

                $checkedRelations[$relation] ??= method_exists($this->model, $relation) &&
                    !method_exists(Model::class, $relation) &&
                    $this->model->$relation() instanceof Relations\Relation;

                if ($checkedRelations[$relation]) {
                    $relations[] = $relation;
                }
            } elseif (method_exists($this->model, $column) &&
                !method_exists(Model::class, $column)
            ) {
                $relations[] = $column;
            }
        }

        return array_unique($relations);
    }

    /**
     * Set action for form.
     *
     * @param string $action
     *
     * @return $this
     */
    public function setAction($action): self
    {
        $this->builder()->setAction($action);

        return $this;
    }

    /**
     * Set field and label width in current form.
     *
     * @param int $fieldWidth
     * @param int $labelWidth
     *
     * @return $this
     */
    public function setWidth($fieldWidth = 8, $labelWidth = 2): self
    {
        $this->fields()->each(function ($field) use ($fieldWidth, $labelWidth) {
            /* @var Field $field  */
            $field->setWidth($fieldWidth, $labelWidth);
        });

        $this->builder()->setWidth($fieldWidth, $labelWidth);

        return $this;
    }

    /**
     * Set view for form.
     *
     * @param string $view
     *
     * @return $this
     */
    public function setView($view): self
    {
        $this->builder()->setView($view);

        return $this;
    }

    /**
     * Set title for form.
     *
     * @param string $title
     *
     * @return $this
     */
    public function setTitle($title = ''): self
    {
        $this->builder()->setTitle($title);

        return $this;
    }

    /**
     * Set a submit confirm.
     *
     * @param string $message
     * @param string $on
     *
     * @return $this
     */
    public function confirm(string $message, $on = null)
    {
        if ($on && !in_array($on, ['create', 'edit'])) {
            throw new \InvalidArgumentException("The second paramater `\$on` must be one of ['create', 'edit']");
        }

        if ($on == 'create' && !$this->isCreating()) {
            return;
        }

        if ($on == 'edit' && !$this->isEditing()) {
            return;
        }

        $this->builder()->confirm($message);

        return $this;
    }

    /**
     * Add a row in form.
     *
     * @param Closure $callback
     *
     * @return $this
     */
    public function row(Closure $callback): self
    {
        $this->rows[] = new Row($callback, $this);

        return $this;
    }

    /**
     * Tools setting for form.
     *
     * @param Closure $callback
     */
    public function tools(Closure $callback)
    {
        $callback->call($this, $this->builder->getTools());
    }

    /**
     * @param Closure|null $callback
     *
     * @return Form\Tools
     */
    public function header(?Closure $callback = null)
    {
        if (func_num_args() === 0) {
            return $this->builder->getTools();
        }

        $callback->call($this, $this->builder->getTools());
    }

    /**
     * Indicates if current form page is creating.
     *
     * @return bool
     */
    public function isCreating(): bool
    {
        $name = optional(\request()->route())->getName();

        return is_string($name) && Str::endsWith($name, ['.create', '.store']);
    }

    /**
     * Indicates if current form page is editing.
     *
     * @return bool
     */
    public function isEditing(): bool
    {
        $name = optional(\request()->route())->getName();

        return is_string($name) && Str::endsWith($name, ['.edit', '.update']);
    }

    /**
     * Disable form submit.
     *
     * @param bool $disable
     *
     * @return $this
     *
     * @deprecated
     */
    public function disableSubmit(bool $disable = true): self
    {
        $this->builder()->getFooter()->disableSubmit($disable);

        return $this;
    }

    /**
     * Disable form reset.
     *
     * @param bool $disable
     *
     * @return $this
     *
     * @deprecated
     */
    public function disableReset(bool $disable = true): self
    {
        $this->builder()->getFooter()->disableReset($disable);

        return $this;
    }

    /**
     * Disable View Checkbox on footer.
     *
     * @param bool $disable
     *
     * @return $this
     */
    public function disableViewCheck(bool $disable = true): self
    {
        $this->builder()->getFooter()->disableViewCheck($disable);

        return $this;
    }

    /**
     * Disable Editing Checkbox on footer.
     *
     * @param bool $disable
     *
     * @return $this
     */
    public function disableEditingCheck(bool $disable = true): self
    {
        $this->builder()->getFooter()->disableEditingCheck($disable);

        return $this;
    }

    /**
     * Disable Creating Checkbox on footer.
     *
     * @param bool $disable
     *
     * @return $this
     */
    public function disableCreatingCheck(bool $disable = true): self
    {
        $this->builder()->getFooter()->disableCreatingCheck($disable);

        return $this;
    }

    /**
     * Footer setting for form.
     *
     * @param Closure $callback
     *
     * @return \Encore\Admin\Form\Footer
     */
    public function footer(?Closure $callback = null)
    {
        if (func_num_args() === 0) {
            return $this->builder()->getFooter();
        }

        $callback($this->builder()->getFooter());
    }

    /**
     * Get current resource route url.
     *
     * @param int $slice
     *
     * @return string
     */
    public function resource($slice = -2): string
    {
        $segments = explode('/', trim(\request()->getUri(), '/'));

        if ($slice !== 0) {
            $segments = array_slice($segments, 0, $slice);
        }

        return implode('/', $segments);
    }

    /**
     * Render the form contents.
     *
     * @return string
     */
    public function render()
    {
        return $this->builder->render();
    }

    /**
     * Get or set input data.
     *
     * @param string $key
     * @param null   $value
     *
     * @return array|mixed
     */
    public function input($key, $value = null)
    {
        if (func_num_args() === 1) {
            return Arr::get($this->inputs, $key);
        }

        return Arr::set($this->inputs, $key, $value);
    }

    /**
     * Add a new layout column.
     *
     * @param int      $width
     * @param \Closure $closure
     *
     * @return $this
     */
    public function column($width, \Closure $closure): self
    {
        $width = $width < 1 ? round(12 * $width) : $width;

        $this->layout->column($width, $closure);

        return $this;
    }

    /**
     * Initialize filter layout.
     */
    protected function initLayout()
    {
        $this->layout = new Layout($this);
    }

    /**
     * Getter.
     *
     * @param string $name
     *
     * @return array|mixed
     */
    public function __get($name)
    {
        return $this->input($name);
    }

    /**
     * Setter.
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return array
     */
    public function __set($name, $value)
    {
        return Arr::set($this->inputs, $name, $value);
    }

    /**
     * __isset.
     *
     * @param string $name
     *
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->inputs[$name]);
    }

    /**
     * Generate a Field object and add to form builder if Field exists.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return Field
     */
    public function __call($method, $arguments)
    {
        if ($className = static::findFieldClass($method)) {
            $column = Arr::get($arguments, 0, ''); //[0];

            $element = new $className($column, array_slice($arguments, 1));

            $this->pushField($element);

            return $element;
        }

        admin_error('Error', "Field type [$method] does not exist.");

        return new Field\Nullable();
    }

    /**
     * @return Layout
     */
    public function getLayout(): Layout
    {
        return $this->layout;
    }
}
