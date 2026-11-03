<?php

namespace Encore\Admin\Actions;

/** A configurable button using the shared action runtime. */
class ButtonAction extends CallbackAction
{
    /**
     * @var string
     */
    protected $style = 'btn-default';

    /**
     * @var string
     */
    protected $icon = '';

    /**
     * @var string
     */
    protected $href = '';

    /**
     * @var string|null
     */
    protected $hrefTarget;

    /**
     * @param string $style
     *
     * @return $this
     */
    public function setButtonStyle($style)
    {
        $this->style = $style;

        return $this;
    }

    /**
     * @param string $icon
     *
     * @return $this
     */
    public function setIcon($icon)
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * @return string
     */
    public function getIcon()
    {
        return $this->icon;
    }

    /**
     * @param string      $href
     * @param string|null $target
     *
     * @return $this
     */
    public function setHref($href, $target = null)
    {
        $this->href = $href;
        $this->hrefTarget = $target;

        return $this;
    }

    /**
     * @return string
     */
    public function getHref()
    {
        return $this->href;
    }

    public function html()
    {
        $attributes = $this->attributes;
        $attributes['data-_key'] = $this->contextKeys()[0] ?? '';
        $attributes['href'] = $this->getHref() ?: 'javascript:void(0);';
        $attributes['class'] = trim('btn btn-sm '.$this->style.' '.$this->getElementClass());

        if ($this->hrefTarget !== null) {
            $attributes['target'] = $this->hrefTarget;
            if ($this->hrefTarget === '_blank') {
                $attributes['rel'] = trim(($attributes['rel'] ?? '').' noopener noreferrer');
            }
        }

        $html = [];
        foreach ($attributes as $name => $value) {
            $html[] = $name.'="'.e($value).'"';
        }

        $icon = $this->icon !== '' ? '<i class="fa '.e($this->icon).'"></i> ' : '';

        return '<a '.implode(' ', $html).'>'.$icon.e($this->name()).'</a>';
    }

    public function render()
    {
        if (!$this->passesAuthorizationForRendering()) {
            return $this->renderAuthorizationDenied();
        }

        // Links navigate on their own; only runtime actions need the shared action handler.
        if (!$this->getHref()) {
            $this->addScript();
        }

        return $this->attachAttributes($this->html());
    }
}
