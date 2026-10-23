<?php

namespace Encore\Admin\Actions;

use Illuminate\Http\Request;

abstract class BatchAction extends GridAction
{
    /**
     * @var string
     */
    public $selectorPrefix = '.grid-batch-action-';

    /**
     * variable holding additional CSS classes for the button.
     *
     * @var array
     */
    private $cssClasses = [];

    /**
     * add a single CSS class string to the CSS-Classes array.
     *
     * @param string $cssClass
     *
     * @return $this
     */
    public function addCssClass(string $cssClass)
    {
        if (empty($cssClass)) {
            return $this;
        }
        if (!is_string($cssClass)) {
            throw new \Exception(__METHOD__.': item is not a valid string');
        }
        $this->cssClasses[] = $cssClass;

        return $this;
    }

    /**
     * add multiple CSS class strings to the CSS-Classes array.
     *
     * @param array $cssClasses
     *
     * @return $this
     */
    public function addCssClasses(array $cssClasses)
    {
        if (empty($cssClasses)) {
            return $this;
        }
        if (!is_array($cssClasses)) {
            throw new \Exception(__METHOD__.': parameter is not a valid array');
        }
        foreach ($cssClasses as $item) {
            if (!is_string($item)) {
                throw new \Exception(__METHOD__.': item is not a valid string');
            }
            $this->cssClasses[] = $item;
        }

        return $this;
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

    public function contextKeys(): array
    {
        $data = $this->parent->model()->buildData();
        $key = $this->parent->model()->getOriginalModel()->getKeyName();
        return collect($data['data'] ?? $data)->pluck($key)->all();
    }

    /**
     * @return string
     */
    public function render()
    {
        $this->addScript();
        $this->attribute('data-admin-selection', $this->parent->getGridRowName().'-checkbox');

        if ($content = $this->html()) {
            return $this->attachAttributes($content);
        }

        return sprintf(
            "<a href='javascript:void(0);' class='%s %s' %s>%s</a>",
            e($this->getElementClass()),
            e((!empty($this->cssClasses)) ? implode(' ', $this->cssClasses) : ''),
            $this->formatAttributes(),
            e($this->name())
        );
    }
}
