<?php

namespace Encore\Admin\Grid\Filter\Presenter;

use Encore\Admin\Facades\Admin;

class MultipleSelect extends Select
{
    /**
     * Load options for other select when change.
     *
     * @param string $target
     * @param string $resourceUrl
     * @param string $idField
     * @param string $textField
     *
     * @return $this
     */
    public function loadMore($target, $resourceUrl, $idField = 'id', $textField = 'text'): self
    {
        $column = $this->filter->getColumn();
        $event = 'change.adminPage.filterCascade'.md5($this->getTargetClass($target));

        $script = <<<EOT

$(document).off('{$event}', ".{$this->getClass($column)}");
$(document).on('{$event}', ".{$this->getClass($column)}", function () {
    var target = $(this).closest('form').find(".{$this->getTargetClass($target)}");
     var ids = $(this).find("option:selected").map(function(index,elem) {
            return $(elem).val();
        }).get().join(',');
    $.get("$resourceUrl", {q: ids}, function (data) {
        target.find("option").remove();
        $.each(data, function (i, item) {
            $(target).append($('<option>', {
                value: item.$idField,
                text : item.$textField
            }));
        });
        
        $(target).trigger('change');
    });
});
EOT;

        Admin::script($script);

        return $this;
    }
}
