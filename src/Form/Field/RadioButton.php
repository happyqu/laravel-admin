<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Admin;

class RadioButton extends Radio
{
    /**
     * @var string
     */
    protected $cascadeEvent = 'change';

    protected function addScript()
    {
        $script = <<<'SCRIPT'
$(document).off('click.adminPage.adminRadioButton', '.radio-group-toggle label').on('click.adminPage.adminRadioButton', '.radio-group-toggle label', function() {
    if ($(this).find('input').is(':disabled')) return;
    $(this).parent().children().removeClass('active');
    $(this).addClass('active');
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
