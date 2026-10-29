<?php

namespace Encore\Admin\Form\Concerns;

use Encore\Admin\Form\Field;

trait HandleCascadeFields
{
    /**
     * @param array    $dependency
     * @param \Closure $closure
     */
    public function cascadeGroup(\Closure $closure, array $dependency)
    {
        $this->pushField($group = new Field\CascadeGroup($dependency));

        $start = count($this->fields());

        call_user_func($closure, $this);

        foreach (array_slice(collect($this->fields())->all(), $start) as $field) {
            $field->addCascadeGroup($group);
        }

        $group->end();
    }
}
