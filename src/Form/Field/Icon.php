<?php

namespace Encore\Admin\Form\Field;

class Icon extends Text
{
    protected static $css = ['/vendor/laravel-admin/fontawesome-iconpicker/fontawesome-iconpicker.min.css'];

    protected static $js = ['/vendor/laravel-admin/fontawesome-iconpicker/fontawesome-iconpicker.min.js'];

    protected $default = 'fa-pencil';

    public function render()
    {
        $this->script = <<<EOT
$('{$this->getElementClassSelector()}').each(function () {
    var input = $(this);
    var component = input.closest('.input-group').find('.input-group-text');
    component.addClass('iconpicker-component');
    function preview() { component.find('i').attr('class', 'fa ' + (input.val() || 'fa-pencil') + ' fa-fw'); }
    preview();
    input.on('input.adminIconPicker change.adminIconPicker', preview);
    if (!input.is('[readonly], :disabled')) {
        input.iconpicker({placement: 'bottomLeft', hideOnSelect: true});
    }
});
EOT;

        $this->prepend('<i class="fa fa-pencil fa-fw"></i>')
            ->defaultAttribute('style', 'width: 140px');

        return parent::render();
    }
}
