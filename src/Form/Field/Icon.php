<?php

namespace Encore\Admin\Form\Field;

class Icon extends Text
{
    protected $default = 'fa-pencil';

    public function render()
    {
        $this->script = <<<EOT
$('{$this->getElementClassSelector()}').on('input', function () {
    $(this).closest('.input-group').find('.input-group-text i').attr('class', 'fa ' + (this.value || 'fa-pencil') + ' fa-fw');
});
EOT;

        $this->prepend('<i class="fa fa-pencil fa-fw"></i>')
            ->defaultAttribute('style', 'width: 140px');

        return parent::render();
    }
}
