<?php

namespace Encore\Admin\Grid\Column;

use Encore\Admin\Admin;
use Encore\Admin\Grid\Model;

class RangeFilter extends Filter
{
    /**
     * @var string
     */
    protected $type;

    /**
     * RangeFilter constructor.
     *
     * @param string $type
     */
    public function __construct($type)
    {
        $this->type = $type;
        $this->class = [
            'start' => uniqid('column-filter-start-'),
            'end'   => uniqid('column-filter-end-'),
        ];
    }

    /**
     * Add a binding to the query.
     *
     * @param mixed $value
     * @param Model $model
     */
    public function addBinding($value, Model $model)
    {
        $value = array_filter((array) $value);

        if (empty($value)) {
            return;
        }

        if (!isset($value['start'])) {
            return $model->where($this->getColumnName(), '<', $value['end']);
        } elseif (!isset($value['end'])) {
            return $model->where($this->getColumnName(), '>', $value['start']);
        } else {
            return $model->whereBetween($this->getColumnName(), array_values($value));
        }
    }

    protected function addScript()
    {
        // Native date/time inputs need no JavaScript initializer.
    }

    /**
     * Render this filter.
     *
     * @return string
     */
    public function render()
    {
        $script = <<<'SCRIPT'
$('.dropdown-menu input').click(function(e) {
    e.stopPropagation();
});
SCRIPT;

        Admin::script($script);

        $this->addScript();

        $value = array_merge(['start' => '', 'end' => ''], $this->getFilterValue([]));
        $active = empty(array_filter($value)) ? '' : 'text-warning';
        $inputType = $this->type === 'datetime' ? 'datetime-local' : (in_array($this->type, ['date', 'time'], true) ? $this->type : 'text');

        return <<<EOT
<span class="dropdown">
<form action="{$this->getFormAction()}" class="d-inline-block">
    <a href="javascript:void(0);" class="dropdown-toggle {$active}" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fa fa-filter"></i>
    </a>
    <ul class="dropdown-menu p-3" style="min-width: 220px;">
        <li>
            <input type="{$inputType}" class="form-control form-control-sm {$this->class['start']}" name="{$this->getColumnName()}[start]" value="{$value['start']}" autocomplete="off"/>
        </li>
        <li class="my-2 text-center text-body-secondary">~</li>
        <li>
            <input type="{$inputType}" class="form-control form-control-sm {$this->class['end']}" name="{$this->getColumnName()}[end]" value="{$value['end']}" autocomplete="off"/>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li class="d-flex align-items-center justify-content-between">
            <button class="btn btn-sm btn-primary column-filter-submit" data-loading-text="{$this->trans('search')}..."><i class="fa fa-search"></i>&nbsp;&nbsp;{$this->trans('search')}</button>
            <a href="{$this->getFormAction()}" class="btn btn-sm btn-outline-secondary column-filter-all"><i class="fa fa-undo"></i></a>
        </li>
    </ul>
</form>
</span>
EOT;
    }
}
