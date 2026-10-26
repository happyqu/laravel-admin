<?php

namespace Encore\Admin\Form\Field;

class Time extends Date
{
    protected $format = 'HH:mm:ss';

    protected $icon = 'fa-clock-o';

    public function render()
    {
        $this->defaultAttribute('style', 'width: 150px');

        return parent::render();
    }
}
