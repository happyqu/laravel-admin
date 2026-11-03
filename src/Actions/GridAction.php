<?php

namespace Encore\Admin\Actions;

use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

/**
 * Class GridAction.
 *
 * @method retrieveModel(Request $request)
 */
abstract class GridAction extends Action
{
    /**
     * @var Grid
     */
    protected $parent;

    /**
     * @var string
     */
    public $selectorPrefix = '.grid-action-';

    /**
     * @param Grid $grid
     *
     * @return $this
     */
    public function setGrid(Grid $grid)
    {
        $this->parent = $grid;

        return $this;
    }

    /**
     * Get url path of current resource.
     *
     * @return string
     */
    public function getResource()
    {
        return $this->parent->resource();
    }

    /**
     * @return mixed
     */
    protected function getModelClass()
    {
        return $this->parent ? get_class($this->parent->model()->getOriginalModel()) : '';
    }

    /**
     * @return array
     */
    public function parameters()
    {
        // A grid action may be registered before it is bound to a grid (for example in tests
        // or when the ticket is rebuilt outside a grid render).
        return $this->parent ? ['_model' => $this->getModelClass()] : [];
    }

    /**
     * Indicates if model uses soft-deletes.
     *
     * @param $modelClass
     *
     * @return bool
     */
    protected function modelUseSoftDeletes($modelClass)
    {
        return in_array(SoftDeletes::class, class_uses_deep($modelClass));
    }
}
