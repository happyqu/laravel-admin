<?php

namespace Encore\Admin\Grid\Column;

use Encore\Admin\Admin;
use Encore\Admin\Grid\Model;

class InputFilter extends Filter
{
    /**
     * @var string
     */
    protected $type;

    /**
     * InputFilter constructor.
     *
     * @param string $type
     */
    public function __construct($type)
    {
        $this->type = $type;
        $this->class = uniqid('column-filter-');
    }

    /**
     * Add a binding to the query.
     *
     * @param string     $value
     * @param Model|null $model
     */
    public function addBinding($value, Model $model)
    {
        if (empty($value)) {
            return;
        }

        if ($this->type == 'like') {
            $model->where($this->getColumnName(), 'like', "%{$value}%");

            return;
        }

        if (in_array($this->type, ['date', 'time'])) {
            $method = 'where'.ucfirst($this->type);
            $model->{$method}($this->getColumnName(), $value);

            return;
        }

        $model->where($this->getColumnName(), $value);
    }

    /**
     * Add script to page.
     *
     * @return void
     */
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

        $value = $this->getFilterValue();

        $active = empty($value) ? '' : 'text-warning';
        $inputType = $this->type === 'datetime' ? 'datetime-local' : (in_array($this->type, ['date', 'time'], true) ? $this->type : 'text');

        return <<<EOT
<span class="dropdown">
    <form action="{$this->getFormAction()}" style="display: inline-block;">
    <a href="javascript:void(0);" class="dropdown-toggle {$active}" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fa fa-filter"></i>
    </a>
    <ul class="dropdown-menu p-3" style="min-width: 220px;">
        <li>
            <input type="{$inputType}" name="{$this->getColumnName()}" value="{$this->getFilterValue()}" class="form-control form-control-sm {$this->class}" autocomplete="off"/>
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
