<?php

namespace Encore\Admin\Grid\Column;

use Encore\Admin\Admin;
use Encore\Admin\Grid\Model;

class CheckFilter extends Filter
{
    /**
     * @var array
     */
    protected $options;

    /**
     * CheckFilter constructor.
     *
     * @param array $options
     */
    public function __construct(array $options)
    {
        $this->options = $options;

        $this->class = [
            'all'  => uniqid('column-filter-all-'),
            'item' => uniqid('column-filter-item-'),
        ];
    }

    /**
     * Add a binding to the query.
     *
     * @param array $value
     * @param Model $model
     */
    public function addBinding($value, Model $model)
    {
        if (empty($value)) {
            return;
        }

        $model->whereIn($this->getColumnName(), $value);
    }

    /**
     * Add script to page.
     *
     * @return void
     */
    protected function addScript()
    {
        $script = <<<SCRIPT
$('.{$this->class['all']}').on('change', function () {
    $('.{$this->class['item']}').prop('checked', this.checked);
    return false;
});
SCRIPT;

        Admin::script($script);
    }

    /**
     * Render this filter.
     *
     * @return string
     */
    public function render()
    {
        $value = $this->getFilterValue([]);

        $lists = collect($this->options)->map(function ($label, $key) use ($value) {
            $checked = in_array($key, $value) ? 'checked' : '';

            return <<<HTML
<li class="form-check">
    <input type="checkbox" class="form-check-input {$this->class['item']}" id="{$this->class['item']}-{$key}"
           name="{$this->getColumnName()}[]" value="{$key}" {$checked}>
    <label class="form-check-label" for="{$this->class['item']}-{$key}">{$label}</label>
</li>
HTML;
        })->implode("\r\n");

        $this->addScript();

        $allCheck = (count($value) == count($this->options)) ? 'checked' : '';
        $active = empty($value) ? '' : 'text-warning';

        return <<<EOT
<span class="dropdown">
<form action="{$this->getFormAction()}" class="d-inline-block">
    <a href="javascript:void(0);" class="dropdown-toggle {$active}" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fa fa-filter" aria-hidden="true"></i>
    </a>
    <ul class="dropdown-menu p-3" style="min-width: 220px;">
        <li class="form-check">
            <input type="checkbox" class="form-check-input {$this->class['all']}" id="{$this->class['all']}" {$allCheck}>
            <label class="form-check-label" for="{$this->class['all']}">{$this->trans('all')}</label>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li><ul class="list-unstyled mb-0">{$lists}</ul></li>
        <li><hr class="dropdown-divider"></li>
        <li class="d-flex align-items-center justify-content-between">
            <button class="btn btn-sm btn-primary" data-loading-text="{$this->trans('search')}...">
                <i class="fa fa-search" aria-hidden="true"></i>&nbsp;&nbsp;{$this->trans('search')}
            </button>
            <a href="{$this->getFormAction()}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-undo" aria-hidden="true"></i>
            </a>
        </li>
    </ul>
</form>
</span>
EOT;
    }
}
