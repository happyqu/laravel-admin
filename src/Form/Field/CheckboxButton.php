<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Admin;

class CheckboxButton extends Checkbox
{
    /**
     * @var string
     */
    protected $cascadeEvent = 'change';

    protected function addScript()
    {
        $script = <<<'SCRIPT'
$(document).off('click.adminPage.adminCheckboxButton', '.checkbox-group-toggle label').on('click.adminPage.adminCheckboxButton', '.checkbox-group-toggle label', function(e) {
    e.stopPropagation();
    e.preventDefault();

    if ($(this).find('input').is(':disabled')) return;

    if ($(this).hasClass('active')) {
        $(this).removeClass('active');
        $(this).find('input').prop('checked', false);
    } else {
        $(this).addClass('active');
        $(this).find('input').prop('checked', true);
    }

    $(this).find('input').trigger('change');
});
SCRIPT;

        Admin::script($script);
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $this->addScript();

        $this->addCascadeScript();

        $this->addVariables([
            'options' => $this->options,
            'checked' => $this->checked,
        ]);

        return parent::fieldRender();
    }
}
