<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;

class Datetime extends Date
{
    public function display($format = 'YYYY-MM-DD HH:mm:ss')
    {
        $value = $this->formatValue($format, true);

        return Admin::component('admin::grid.inline-edit.datetime', [
            'key'      => $this->getKey(),
            'value'    => $value,
            'display'  => $value,
            'name'     => $this->getPayloadName(),
            'resource' => $this->getResource(),
            'trigger'  => "ie-trigger-{$this->getClassName()}",
            'target'   => "ie-template-{$this->getClassName()}",

            'format'   => $format,
            'locale'   => config('app.locale'),
        ]);
    }
}
