<?php

namespace Encore\Admin\Grid;

use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class Model
{
    /**
     * Eloquent model instance of the grid model.
     *
     * @var EloquentModel
     */
    protected $model;

    /**
     * @var EloquentModel
     */
    protected $originalModel;

    /**
     * Array of queries of the eloquent model.
     *
     * @var \Illuminate\Support\Collection
     */
    protected $queries;

    /**
     * Sort parameters of the model.
     *
     * @var array
     */
    protected $sort;

    /**
     * @var array
     */
    protected $data = [];

    /** Whether data loading and its collection callback completed, including empty results. */
    protected $dataLoaded = false;

    /*
     * 20 items per page as default.
     *
     * @var int
     */
    protected $perPage = 20;

    /**
     * If the model use pagination.
     *
     * @var bool
     */
    protected $usePaginate = true;

    /**
     * The query string variable used to store the per-page.
     *
     * @var string
     */
    protected $perPageName = 'per_page';

    /**
     * The query string variable used to store the sort.
     *
     * @var string
     */
    protected $sortName = '_sort';

    /**
     * Collection callback.
     *
     * @var \Closure
     */
    protected $collectionCallback;

    /**
     * @var Grid
     */
    protected $grid;

    /**
     * @var Relation
     */
    protected $relation;

    /**
     * Create a new grid model instance.
     *
     * @param EloquentModel $model
     * @param Grid          $grid
     */
    public function __construct(EloquentModel $model, ?Grid $grid = null)
    {
        $this->model = $model;

        $this->originalModel = $model;

        $this->grid = $grid;

        $this->queries = collect();
    }

    /**
     * @return EloquentModel
     */
    public function getOriginalModel()
    {
        return $this->originalModel;
    }

    /**
     * Get the eloquent model of the grid model.
     *
     * @return EloquentModel
     */
    public function eloquent()
    {
        return $this->model;
    }

    /**
     * Enable or disable pagination.
     *
     * @param bool $use
     */
    public function usePaginate($use = true)
    {
        $this->usePaginate = $use;
    }

    /**
     * Get the query string variable used to store the per-page.
     *
     * @return string
     */
    public function getPerPageName()
    {
        return $this->perPageName;
    }

    /**
     * Set the query string variable used to store the per-page.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setPerPageName($name)
    {
        $this->perPageName = $name;

        return $this;
    }

    /**
     * Get per-page number.
     *
     * @return int
     */
    public function getPerPage()
    {
        return $this->perPage;
    }

    /**
     * Set per-page number.
     *
     * @param int $perPage
     *
     * @return $this
     */
    public function setPerPage($perPage)
    {
        $this->perPage = $perPage;

        $this->__call('paginate', [$perPage]);

        return $this;
    }

    /**
     * Get the query string variable used to store the sort.
     *
     * @return string
     */
    public function getSortName()
    {
        return $this->sortName;
    }

    /**
     * Set the query string variable used to store the sort.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setSortName($name)
    {
        $this->sortName = $name;

        return $this;
    }

    /**
     * Set parent grid instance.
     *
     * @param Grid $grid
     *
     * @return $this
     */
    public function setGrid(Grid $grid)
    {
        $this->grid = $grid;

        return $this;
    }

    /**
     * Get parent gird instance.
     *
     * @return Grid
     */
    public function getGrid()
    {
        return $this->grid;
    }

    /**
     * @param Relation $relation
     *
     * @return $this
     */
    public function setRelation(Relation $relation)
    {
        $this->relation = $relation;

        return $this;
    }

    /**
     * @return Relation
     */
    public function getRelation()
    {
        return $this->relation;
    }

    /**
     * Get constraints.
     *
     * @return array|bool
     */
    public function getConstraints()
    {
        if ($this->relation instanceof HasMany) {
            return [
                $this->relation->getForeignKeyName() => $this->relation->getParentKey(),
            ];
        }

        return false;
    }

    /**
     * Set collection callback.
     *
     * @param \Closure $callback
     *
     * @return $this
     */
    public function collection(?\Closure $callback = null)
    {
        $this->collectionCallback = $callback;

        return $this;
    }

    /**
     * Build.
     *
     * @param bool $toArray
     *
     * @return array|Collection|mixed
     */
    public function buildData($toArray = true)
    {
        if (!$this->dataLoaded) {
            $collection = $this->get();

            if ($this->collectionCallback) {
                $collection = call_user_func($this->collectionCallback, $collection);
            }

            $this->data = $collection;

            $this->dataLoaded = true;
        }

        return $toArray ? $this->data->toArray() : $this->data;
    }

    /**
     * @param callable $callback
     * @param int      $count
     *
     * @return bool
     */
    public function chunk($callback, $count = 100)
    {
        if ($this->usePaginate) {
            return $this->buildData(false)->chunk($count)->each($callback);
        }

        return $this->getQueryBuilder()->chunk($count, $callback);
    }

    /**
     * Add conditions to grid model.
     *
     * @param array $conditions
     *
     * @return $this
     */
    public function addConditions(array $conditions)
    {
        foreach ($conditions as $condition) {
            call_user_func_array([$this, key($condition)], current($condition));
        }

        return $this;
    }

    /**
     * Get table of the model.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->model->getTable();
    }

    /**
     * @throws \Exception
     *
     * @return Collection
     */
    protected function get()
    {
        if ($this->model instanceof LengthAwarePaginator) {
            return $this->model;
        }

        if ($this->relation) {
            $this->model = $this->relation;
        }

        $this->setSort();
        $this->setPaginate();

        $this->queries->unique()->each(function ($query) {
            $this->model = call_user_func_array([$this->model, $query['method']], $query['arguments']);
        });

        if ($this->model instanceof Collection) {
            return $this->model;
        }

        if ($this->model instanceof LengthAwarePaginator) {
            $this->handleInvalidPage($this->model);

            return $this->model->getCollection();
        }

        throw new \Exception('Grid query error');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder|EloquentModel
     */
    public function getQueryBuilder()
    {
        $this->setSort();

        $queryBuilder = $this->relation
            ? clone $this->relation->getQuery()
            : $this->originalModel->newQuery();

        $this->queries->reject(function ($query) {
            return in_array($query['method'], ['get', 'paginate']);
        })->each(function ($query) use (&$queryBuilder) {
            $queryBuilder = $queryBuilder->{$query['method']}(...$query['arguments']);
        });

        return $queryBuilder;
    }

    /**
     * If current page is greater than last page, then redirect to last page.
     *
     * @param LengthAwarePaginator $paginator
     *
     * @return void
     */
    protected function handleInvalidPage(LengthAwarePaginator $paginator)
    {
        if ($paginator->lastPage() && $paginator->currentPage() > $paginator->lastPage()) {
            $lastPageUrl = Request::fullUrlWithQuery([
                $paginator->getPageName() => $paginator->lastPage(),
            ]);

            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                redirect($lastPageUrl)
            );
        }
    }

    /**
     * Set the grid paginate.
     *
     * @return void
     */
    protected function setPaginate()
    {
        $paginate = $this->findQueryByMethod('paginate');

        $this->queries = $this->queries->reject(function ($query) {
            return $query['method'] == 'paginate';
        });

        if (!$this->usePaginate) {
            $query = [
                'method'    => 'get',
                'arguments' => [],
            ];
        } else {
            $query = [
                'method'    => 'paginate',
                'arguments' => $this->resolvePerPage($paginate),
            ];
        }

        $this->queries->push($query);
    }

    /**
     * Resolve perPage for pagination.
     *
     * @param array|null $paginate
     *
     * @return array
     */
    protected function resolvePerPage($paginate)
    {
        $perPage = request($this->perPageName);
        $perPage = is_numeric($perPage) ? (int) $perPage : 0;

        if ($perPage >= 1 && $perPage <= 500) {
            if (is_array($paginate)) {
                $paginate['arguments'][0] = $perPage;

                return $paginate['arguments'];
            }

            $this->perPage = $perPage;
        }

        if (isset($paginate['arguments'][0])) {
            return $paginate['arguments'];
        }

        if ($name = $this->grid?->getName()) {
            return [$this->perPage, ['*'], "{$name}_page"];
        }

        return [$this->perPage];
    }

    /**
     * Find query by method name.
     *
     * @param $method
     *
     * @return static
     */
    protected function findQueryByMethod($method)
    {
        return $this->queries->first(function ($query) use ($method) {
            return $query['method'] == $method;
        });
    }

    /**
     * Set the grid sort.
     *
     * @return void
     */
    protected function setSort()
    {
        if (!$this->grid) {
            return;
        }

        $sort = \request($this->sortName, []);
        if (!is_array($sort)) {
            return;
        }

        $columnName = $sort['column'] ?? null;
        $type = $sort['type'] ?? '';
        if (!is_string($type)) {
            return;
        }
        $type = strtolower($type);

        if (!is_string($columnName) || $columnName === '' || !in_array($type, ['asc', 'desc'], true)) {
            return;
        }

        $sortable = $this->grid->getSortableColumns();
        if (!array_key_exists($columnName, $sortable)) {
            return;
        }

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $columnName)) {
            return;
        }

        $cast = $sortable[$columnName];
        if ($cast !== null && !preg_match('/^[A-Za-z][A-Za-z0-9 ]{0,30}$/', $cast)) {
            return;
        }

        $sort = ['column' => $columnName, 'type' => $type, 'cast' => $cast];
        if ($this->sort === $sort) {
            return;
        }
        $this->sort = $sort;

        $columnNameContainsDots = Str::contains($columnName, '.');
        $isRelation = $this->hasEagerLoadedRelation(Str::camel(Str::before($columnName, '.')));

        if ($columnNameContainsDots === true && $isRelation) {
            $this->setRelationSort($columnName);

            return;
        }

        $this->resetOrderBy();

        if ($columnNameContainsDots === true) {
            $explodedCols = explode('.', $columnName);
            $col = array_shift($explodedCols);

            if (!preg_match('/^[A-Za-z0-9_]+$/', $col) || $explodedCols === []) {
                return;
            }

            foreach ($explodedCols as $part) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $part)) {
                    return;
                }
            }

            $parts = implode('.', $explodedCols);
            $driver = $this->model->getConnection()->getDriverName();

            $columnName = match ($driver) {
                'pgsql' => "({$col}::jsonb #>> '{".implode(',', $explodedCols)."}')",
                'sqlite' => "json_extract({$col}, '$.{$parts}')",
                'sqlsrv' => "JSON_VALUE({$col}, '$.{$parts}')",
                default => "JSON_EXTRACT({$col}, '$.{$parts}')",
            };
        }

        if ($cast !== null && $cast !== '') {
            $column = "CAST({$columnName} AS {$cast}) {$type}";
            $method = 'orderByRaw';
            $arguments = [$column];
        } else {
            $column = $columnNameContainsDots ? new Expression($columnName) : $columnName;
            $method = 'orderBy';
            $arguments = [$column, $type];
        }

        $this->queries->push([
            'method'    => $method,
            'arguments' => $arguments,
        ]);
    }

    /**
     * Set relation sort.
     *
     * @param string $column
     *
     * @return void
     */
    protected function setRelationSort($column)
    {
        list($relationName, $relationColumn) = explode('.', $column);
        // relationship should be camel case
        $relationName = Str::camel($relationName);

        if ($this->hasEagerLoadedRelation($relationName)) {
            $relation = $this->originalModel->$relationName();
            $join = $this->joinParameters($relation);
            $relatedTable = $relation->getRelated()->getTable();

            if ($relatedTable === $this->originalModel->getTable()) {
                $alias = 'admin_sort_'.substr(sha1($relationName), 0, 12);
                $join[0] = $relatedTable.' as '.$alias;
                $join[3] = $alias.'.'.($relation instanceof BelongsTo
                    ? $relation->getOwnerKeyName() : $relation->getForeignKeyName());
                $relatedTable = $alias;
            }

            $this->queries->push([
                'method'    => 'select',
                'arguments' => [$this->originalModel->getTable().'.*'],
            ]);

            $this->queries->push([
                'method'    => 'leftJoin',
                'arguments' => $join,
            ]);

            $this->resetOrderBy();

            $this->queries->push([
                'method'    => 'orderBy',
                'arguments' => [
                    $relatedTable.'.'.$relationColumn,
                    $this->sort['type'],
                ],
            ]);
        }
    }

    /**
     * Reset orderBy query.
     *
     * @return void
     */
    public function resetOrderBy()
    {
        $this->queries = $this->queries->reject(function ($query) {
            return in_array($query['method'], ['orderBy', 'orderByDesc', 'orderByRaw'], true);
        });
    }

    protected function hasEagerLoadedRelation(string $name): bool
    {
        foreach ($this->queries as $query) {
            if ($query['method'] !== 'with') {
                continue;
            }
            foreach ($query['arguments'] as $argument) {
                foreach (is_array($argument) ? $argument : [$argument] as $key => $relation) {
                    $relation = is_string($key) ? $key : $relation;
                    if (is_string($relation) && Str::before(Str::before($relation, ':'), '.') === $name) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Build join parameters for related model.
     *
     * `HasOne` and `BelongsTo` relation has different join parameters.
     *
     * @param Relation $relation
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function joinParameters(Relation $relation)
    {
        $relatedTable = $relation->getRelated()->getTable();

        if ($relation instanceof BelongsTo) {
            return [
                $relatedTable,
                $relation->getQualifiedForeignKeyName(),
                '=',
                $relation->getQualifiedOwnerKeyName(),
            ];
        }

        if ($relation instanceof HasOne) {
            return [
                $relatedTable,
                $relation->getQualifiedParentKeyName(),
                '=',
                $relation->getQualifiedForeignKeyName(),
            ];
        }

        throw new \Exception('Related sortable only support `HasOne` and `BelongsTo` relation.');
    }

    /**
     * @param string $method
     * @param array  $arguments
     *
     * @return $this
     */
    public function __call($method, $arguments)
    {
        $this->queries->push([
            'method'    => $method,
            'arguments' => $arguments,
        ]);

        return $this;
    }

    /**
     * @param $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        $data = $this->buildData();

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }
}
