<?php

namespace Encore\Admin\Form\Field;

trait PlainInput
{
    protected ?string $defaultInputValue = null;

    public function attribute($attribute, $value = null): self
    {
        if ($attribute === 'value' || (is_array($attribute) && array_key_exists('value', $attribute))) {
            $this->defaultInputValue = null;
        }

        return parent::attribute($attribute, $value);
    }
    /**
     * @var string
     */
    protected $prepend;

    /**
     * @var string
     */
    protected $append;

    /**
     * @param mixed $string
     *
     * @return $this
     */
    public function prepend($string)
    {
        if (is_null($this->prepend)) {
            $this->prepend = $string;
        }

        return $this;
    }

    /**
     * @param mixed $string
     *
     * @return $this
     */
    public function append($string)
    {
        if (is_null($this->append)) {
            $this->append = $string;
        }

        return $this;
    }

    /**
     * @return void
     */
    protected function initPlainInput()
    {
        if (empty($this->view)) {
            $this->view = 'admin::form.input';
        }
    }

    /**
     * @param string $attribute
     * @param string $value
     *
     * @return $this
     */
    protected function defaultAttribute($attribute, $value)
    {
        if (!array_key_exists($attribute, $this->attributes)
            || ($attribute === 'value' && $this->defaultInputValue !== null
                && $this->attributes[$attribute] === $this->defaultInputValue)) {
            $this->attribute($attribute, $value);
            if ($attribute === 'value') {
                $this->defaultInputValue = $this->attributes[$attribute];
            }
        }

        return $this;
    }
}
