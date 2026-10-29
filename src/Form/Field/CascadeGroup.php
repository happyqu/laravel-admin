<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;

class CascadeGroup extends Field
{
    /**
     * @var array
     */
    protected $dependency;

    /**
     * @var string
     */
    protected $hide = 'hide';

    /**
     * CascadeGroup constructor.
     *
     * @param array $dependency
     */
    public function __construct(array $dependency)
    {
        $this->dependency = $dependency;
    }

    /**
     * @param Field $field
     *
     * @return bool
     */
    public function dependsOn(Field $field)
    {
        return isset($this->dependency['controller'])
            ? $this->dependency['controller'] === $field
            : $this->dependency['column'] == $field->column();
    }

    public function matchesInput(array $input): bool
    {
        $controller = $this->dependency['controller'] ?? null;
        if (!$controller) {
            return true;
        }

        return $controller->isCascadeActive($input)
            && \Encore\Admin\Form\Cascade::matches($this->dependency['operator'],
                \Illuminate\Support\Arr::get($input, $this->dependency['column']), $this->dependency['value']);
    }

    /**
     * @return int
     */
    public function index()
    {
        return $this->dependency['index'];
    }

    /**
     * @return void
     */
    public function visiable()
    {
        $this->hide = '';
    }

    /**
     * @return string
     */
    public function render()
    {
        return <<<HTML
<div class="cascade-group {$this->dependency['class']} {$this->hide}">
HTML;
    }

    /**
     * @return void
     */
    public function end()
    {
        $this->form->html('</div>')->plain();
    }
}
