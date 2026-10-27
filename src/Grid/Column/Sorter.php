<?php

namespace Encore\Admin\Grid\Column;

use Illuminate\Contracts\Support\Renderable;

class Sorter implements Renderable
{
    /**
     * Sort arguments.
     *
     * @var array
     */
    protected $sort;

    /**
     * Cast Name.
     *
     * @var array
     */
    protected $cast;

    /**
     * @var string
     */
    protected $sortName;

    /**
     * @var string
     */
    protected $columnName;

    /**
     * Sorter constructor.
     *
     * @param string $sortName
     * @param string $columnName
     * @param string $cast
     */
    public function __construct($sortName, $columnName, $cast)
    {
        $this->sortName = $sortName;
        $this->columnName = $columnName;
        $this->cast = $cast;
    }

    /**
     * Determine if this column is currently sorted.
     *
     * @return bool
     */
    protected function isSorted()
    {
        $this->sort = \request()->get($this->sortName);

        if (!is_array($this->sort) || empty($this->sort)) {
            return false;
        }

        return isset($this->sort['column']) && $this->sort['column'] == $this->columnName;
    }

    /**
     * @return string
     */
    public function render()
    {
        $icon = 'fa-sort';
        $type = 'desc';

        if ($this->isSorted()) {
            $sortType = $this->sort['type'] ?? '';

            if (!in_array($sortType, ['asc', 'desc'], true)) {
                $sortType = 'desc';
            }

            $type = $sortType === 'desc' ? 'asc' : 'desc';
            $icon .= "-amount-{$sortType}";
        }

        // The cast is intentionally not transported through the request. It is
        // looked up server-side from the registered sortable column.
        $sort = ['column' => $this->columnName, 'type' => $type];

        $query = \request()->all();
        $query = array_merge($query, [$this->sortName => $sort]);

        $url = url()->current().'?'.http_build_query($query);

        return '<a class="fa fa-fw '.e($icon).'" href="'.e($url).'"></a>';
    }
}
