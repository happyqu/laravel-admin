<?php

namespace Encore\Admin\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

trait ModelTree
{
    /**
     * @var array
     */
    protected static $branchOrder = [];

    /**
     * @var string
     */
    protected $parentColumn = 'parent_id';

    /**
     * @var string
     */
    protected $titleColumn = 'title';

    /**
     * @var string
     */
    protected $orderColumn = 'order';

    /**
     * @var \Closure
     */
    protected $queryCallback;

    /**
     * Get children of current node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children()
    {
        return $this->hasMany(static::class, $this->parentColumn);
    }

    /**
     * Get parent of current node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(static::class, $this->parentColumn);
    }

    /**
     * @return string
     */
    public function getParentColumn()
    {
        return $this->parentColumn;
    }

    /**
     * Set parent column.
     *
     * @param string $column
     */
    public function setParentColumn($column)
    {
        $this->parentColumn = $column;
    }

    /**
     * Get title column.
     *
     * @return string
     */
    public function getTitleColumn()
    {
        return $this->titleColumn;
    }

    /**
     * Set title column.
     *
     * @param string $column
     */
    public function setTitleColumn($column)
    {
        $this->titleColumn = $column;
    }

    /**
     * Get order column name.
     *
     * @return string
     */
    public function getOrderColumn()
    {
        return $this->orderColumn;
    }

    /**
     * Set order column.
     *
     * @param string $column
     */
    public function setOrderColumn($column)
    {
        $this->orderColumn = $column;
    }

    /**
     * Set query callback to model.
     *
     * @param \Closure|null $query
     *
     * @return $this
     */
    public function withQuery(?\Closure $query = null)
    {
        $this->queryCallback = $query;

        return $this;
    }

    /**
     * Format data to tree like array.
     *
     * @return array
     */
    public function toTree()
    {
        return $this->buildNestedArray();
    }

    /**
     * Build Nested array.
     *
     * @param array $nodes
     * @param int   $parentId
     *
     * @return array
     */
    protected function buildNestedArray(array $nodes = [], $parentId = 0)
    {
        if (empty($nodes)) {
            $nodes = $this->allNodes();
        }

        $rootKey = $this->treeNodeKey($parentId);
        if ($rootKey === null) {
            return $this->buildTreeWithoutIndex($nodes, $parentId);
        }
        $groups = [];
        foreach ($nodes as $node) {
            $parentKey = $this->treeNodeKey($node[$this->parentColumn]);
            $nodeKey = $this->treeNodeKey($node[$this->getKeyName()]);
            if ($parentKey === null || $nodeKey === null) {
                // Preserve loose comparisons for noncanonical or unusual identifiers.
                return $this->buildTreeWithoutIndex($nodes, $parentId);
            }
            $groups[$parentKey][] = $node;
        }

        $ancestors = [$rootKey => true];

        return $this->buildTreeFromIndex($groups, $rootKey, $ancestors);
    }

    /** Canonical integer IDs and UUID/string IDs can be indexed without changing equality. */
    protected function treeNodeKey($value): ?string
    {
        if (is_int($value)) {
            return 'i:'.$value;
        }
        if (is_string($value) && $value !== '') {
            if (!is_numeric($value)) {
                return 's:'.$value;
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            return $integer === false ? null : 'i:'.$integer;
        }

        return null;
    }

    protected function buildTreeFromIndex(array $groups, string $parentKey, array &$ancestors): array
    {
        $branch = [];
        foreach ($groups[$parentKey] ?? [] as $node) {
            $key = $this->treeNodeKey($node[$this->getKeyName()]);
            if (isset($ancestors[$key])) {
                continue;
            }
            $ancestors[$key] = true;
            $children = $this->buildTreeFromIndex($groups, $key, $ancestors);
            unset($ancestors[$key]);
            if ($children) {
                $node['children'] = $children;
            }
            $branch[] = $node;
        }

        return $branch;
    }

    protected function buildTreeWithoutIndex(array $nodes, $parentId, array $ancestors = []): array
    {
        $branch = [];
        $ancestors[] = $parentId;
        foreach ($nodes as $node) {
            if ($node[$this->parentColumn] == $parentId) {
                if (in_array($node[$this->getKeyName()], $ancestors)) {
                    continue;
                }
                $children = $this->buildTreeWithoutIndex($nodes, $node[$this->getKeyName()], $ancestors);

                if ($children) {
                    $node['children'] = $children;
                }

                $branch[] = $node;
            }
        }

        return $branch;
    }

    /**
     * Get all elements.
     *
     * @return mixed
     */
    public function allNodes()
    {
        $orderColumn = DB::getQueryGrammar()->wrap($this->orderColumn);
        $byOrder = $orderColumn.' = 0,'.$orderColumn;

        $self = new static();

        if ($this->queryCallback instanceof \Closure) {
            $self = call_user_func($this->queryCallback, $self);
        }

        return $self->orderByRaw($byOrder)->get()->toArray();
    }

    /**
     * Set the order of branches in the tree.
     *
     * @param array $order
     *
     * @return void
     */
    protected static function setBranchOrder(array $order)
    {
        static::$branchOrder = array_flip(Arr::flatten($order));

        static::$branchOrder = array_map(function ($item) {
            return ++$item;
        }, static::$branchOrder);
    }

    /**
     * Save tree order from a tree like array.
     *
     * @param array $tree
     * @param int   $parentId
     */
    public static function saveOrder($tree = [], $parentId = 0)
    {
        if ($parentId === 0) {
            static::setBranchOrder($tree);
        }

        foreach ($tree as $branch) {
            if (!is_array($branch) || !isset($branch['id'])) {
                throw new \InvalidArgumentException('Invalid tree order payload.');
            }

            $node = static::findOrFail($branch['id']);

            $node->{$node->getParentColumn()} = $parentId;
            $node->{$node->getOrderColumn()} = static::$branchOrder[$branch['id']];
            $node->save();

            if (isset($branch['children']) && is_array($branch['children'])) {
                static::saveOrder($branch['children'], $branch['id']);
            }
        }
    }

    /**
     * Get options for Select field in form.
     *
     * @param \Closure|null $closure
     * @param string        $rootText
     *
     * @return array
     */
    public static function selectOptions(?\Closure $closure = null, $rootText = 'ROOT')
    {
        $options = (new static())->withQuery($closure)->buildSelectOptions();

        return collect($options)->prepend($rootText, 0)->all();
    }

    /**
     * Build options of select field in form.
     *
     * @param array  $nodes
     * @param int    $parentId
     * @param string $prefix
     * @param string $space
     *
     * @return array
     */
    protected function buildSelectOptions(array $nodes = [], $parentId = 0, $prefix = '', $space = '&nbsp;')
    {
        $prefix = $prefix ?: '┝'.$space;

        if (empty($nodes)) {
            $nodes = $this->allNodes();
        }

        $index = [];
        foreach ($nodes as $node) {
            $index[(string) $node[$this->parentColumn]][] = $node;
        }

        return $this->buildSelectOptionsFromIndex($index, (string) $parentId, $prefix, $space);
    }

    /**
     * Build select options from a parent-indexed node map.
     */
    private function buildSelectOptionsFromIndex(array $index, string $parentId, string $prefix, string $space): array
    {
        $options = [];

        foreach ($index[$parentId] ?? [] as $node) {
            $node[$this->titleColumn] = $prefix.$space.$node[$this->titleColumn];

            $childrenPrefix = str_replace('┝', str_repeat($space, 6), $prefix).'┝'.str_replace(['┝', $space], '', $prefix);

            $children = $this->buildSelectOptionsFromIndex(
                $index,
                (string) $node[$this->getKeyName()],
                $childrenPrefix,
                $space
            );

            $options[$node[$this->getKeyName()]] = $node[$this->titleColumn];

            if ($children) {
                $options += $children;
            }
        }

        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            $children = $this->newQuery()
                ->where($this->parentColumn, $this->getKey())
                ->get();

            foreach ($children as $child) {
                $child->delete();
            }

            return parent::delete();
        });
    }

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (Model $branch) {
            $parentColumn = $branch->getParentColumn();

            if (Request::has($parentColumn) && Request::input($parentColumn) == $branch->getKey()) {
                throw new \Exception(trans('admin.parent_select_error'));
            }

            return $branch;
        });
    }
}
