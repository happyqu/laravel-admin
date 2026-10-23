<?php

namespace Encore\Admin\Grid\Displayers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

class Badge extends AbstractDisplayer
{
    public function display($style = 'red')
    {
        if ($this->value instanceof Arrayable) {
            $this->value = $this->value->toArray();
        }

        return collect((array) $this->value)->map(function ($name) use ($style) {
            if (is_array($style)) {
                $style = Arr::get($style, $this->getColumn()->getOriginal(), 'red');
            }

            $style = preg_match('/^[A-Za-z0-9_-]+$/', (string) $style) ? $style : 'red';

            return "<span class='badge bg-{$style}'>".e($name).'</span>';
        })->implode('&nbsp;');
    }
}
