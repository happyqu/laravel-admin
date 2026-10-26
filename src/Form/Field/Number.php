<?php

namespace Encore\Admin\Form\Field;

class Number extends Text
{
    public function render()
    {
        $this->default($this->default);
        $this->attribute('type', 'number');
        $this->defaultAttribute('style', 'width: 100px');

        return parent::render();
    }

    /**
     * Set min value of number field.
     *
     * @param int $value
     *
     * @return $this
     */
    public function min($value)
    {
        $this->attribute('min', $value);

        return $this;
    }

    /**
     * Set max value of number field.
     *
     * @param int $value
     *
     * @return $this
     */
    public function max($value)
    {
        $this->attribute('max', $value);

        return $this;
    }
}
