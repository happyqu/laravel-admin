<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;

class RowSelector extends AbstractDisplayer
{
    public function display()
    {
        Admin::script($this->script());

        return '<input type="checkbox" class="'.e($this->grid->getGridRowName()).'-checkbox" data-id="'.e($this->getKey()).'" autocomplete="off"/>';
    }

    protected function script()
    {
        $all = $this->grid->getSelectAllName();
        $row = $this->grid->getGridRowName();

        $selected = trans('admin.grid_items_selected');

        return <<<EOT
$('.{$row}-checkbox').on('change', function () {
    var id = $(this).data('id');

    if (this.checked) {
        $.admin.grid.select(id);
        $(this).closest('tr').addClass('table-active');
    } else {
        $.admin.grid.unselect(id);
        $(this).closest('tr').removeClass('table-active');
    }

    var selected = $.admin.grid.selected().length;
    $('.{$all}-btn').toggle(selected > 0);
    $('.{$all}-btn .selected').html("{$selected}".replace('{n}', selected));
});

EOT;
    }
}
