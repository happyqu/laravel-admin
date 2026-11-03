<?php

namespace Encore\Admin\Actions;

use Encore\Admin\Grid\Column;
use Illuminate\Http\Request;

abstract class RowAction extends GridAction
{
    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $row;

    /**
     * @var Column
     */
    protected $column;

    /**
     * @var string
     */
    public $selectorPrefix = '.grid-row-action-';

    /**
     * @var bool
     */
    protected $asColumn = false;

    /**
     * Link target rendered instead of the runtime action.
     *
     * @var string
     */
    protected $link = '';

    /**
     * @var string|null
     */
    protected $hrefTarget;

    /**
     * @param string      $value
     * @param string|null $target
     *
     * @return $this
     */
    public function setHref($value, $target = null)
    {
        $this->link = $value;
        $this->hrefTarget = $target;

        return $this;
    }

    /**
     * Get the effective link of this action, including the href() declared by subclasses.
     *
     * @return string
     */
    public function getHref()
    {
        return (string) $this->href();
    }

    /**
     * Get primary key value of current row.
     *
     * @return mixed
     */
    protected function getKey()
    {
        return $this->row->getKey();
    }

    /**
     * Set row model.
     *
     * @param mixed $key
     *
     * @return \Illuminate\Database\Eloquent\Model|mixed
     */
    public function row($key = null)
    {
        if (func_num_args() == 0) {
            return $this->row;
        }

        return $this->row->getAttribute($key);
    }

    /**
     * Set row model.
     *
     * @param \Illuminate\Database\Eloquent\Model $row
     *
     * @return $this
     */
    public function setRow($row)
    {
        $this->row = $row;

        return $this;
    }

    public function getRow()
    {
        return $this->row;
    }

    /**
     * @param Column $column
     *
     * @return $this
     */
    public function setColumn(Column $column)
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Show this action as a column.
     *
     * @return $this
     */
    public function asColumn()
    {
        $this->asColumn = true;

        return $this;
    }

    /**
     * @return string
     */
    public function href()
    {
        return $this->link;
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function retrieveModel(Request $request)
    {
        return $this->resolvedModel;
    }

    public function contextKeys(): array { return [$this->getKey()]; }

    public function display($value)
    {
    }

    /**
     * Render row action.
     *
     * @return string
     */
    public function render()
    {
        if (!$this->passesAuthorizationForRendering()) {
            return $this->renderAuthorizationDenied();
        }

        if ($href = $this->href()) {
            return sprintf('<a %s>%s</a>', $this->formatLinkAttributes($href), e($this->name()));
        }

        $this->addScript();

        $attributes = $this->formatAttributes();

        return sprintf(
            "<a data-_key='%s' href='javascript:void(0);' class='%s' {$attributes}>%s</a>",
            e($this->getKey()),
            e($this->getElementClass()),
            $this->asColumn ? $this->display($this->row($this->column->getName())) : e($this->name())
        );
    }

    /**
     * Build the attributes of a plain link, keeping configured attributes escaped.
     *
     * @param string $href
     *
     * @return string
     */
    protected function formatLinkAttributes($href)
    {
        $attributes = $this->attributes;
        $attributes['href'] = $href;

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

        return implode(' ', $html);
    }
}
