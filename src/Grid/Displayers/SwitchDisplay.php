<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;
use Encore\Admin\Support\HasSwitchStates;

class SwitchDisplay extends AbstractDisplayer
{
    use HasSwitchStates;

    protected function overrideStates($states)
    {
        if (empty($states)) {
            return;
        }

        $this->states($states);
    }

    public function display($states = [])
    {
        $this->overrideStates($states);

        return Admin::component('admin::grid.inline-edit.switch', [
            'class'    => 'grid-switch-'.str_replace('.', '-', $this->getName()),
            'key'      => $this->getKey(),
            'resource' => $this->getResource(),
            'name'     => $this->getPayloadName(),
            'states'   => $this->states,
            'checked'  => $this->switchState($this->getValue()) === 'on' ? 'checked' : '',
        ]);
    }
}
