<?php

namespace Encore\Admin\Form\Field;

class DateMultiple extends Date
{
    public function render()
    {
        $this->options['mode'] = 'multiple';
        $this->options['showClear'] = true;
        $this->defaultAttribute('style', 'width: 100%');

        return parent::render();
    }
}
