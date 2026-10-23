<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;

class Display extends Field
{
    /**
     * Escape the displayed value unless the caller explicitly opts out.
     *
     * @var bool
     */
    protected $escape = true;

    /**
     * Mark the display value as trusted HTML.
     *
     * @return $this
     */
    public function unescape()
    {
        $this->escape = false;

        return $this;
    }

    /**
     * Determine if the value will be HTML-escaped.
     *
     * @return bool
     */
    public function isEscaped()
    {
        return $this->escape;
    }

    /**
     * {@inheritdoc}
     */
    public function variables(): array
    {
        return array_merge(parent::variables(), ['escape' => $this->escape]);
    }
}
