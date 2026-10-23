<?php

namespace Encore\Admin\Grid\Displayers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

class Label extends AbstractDisplayer
{
    public function display($style = 'success')
    {
        if ($this->value instanceof Arrayable) {
            $this->value = $this->value->toArray();
        }

        return collect((array) $this->value)->map(function ($item) use ($style) {
            if (is_array($style)) {
                $columnValue = $this->getColumn()->getOriginal();

                if (is_string($columnValue) || is_int($columnValue) || is_bool($columnValue)) {
                    $columnValue = is_bool($columnValue) ? (int) $columnValue : $columnValue;

                    $style = Arr::get($style, $columnValue, 'success');
                } else {
                    $style = Arr::get($style, $item, 'success');
                }
            }

            $style = preg_match('/^[A-Za-z0-9_-]+$/', (string) $style) ? $style : 'success';

            return "<span class='label label-{$style}'>".e($item).'</span>';
        })->implode('&nbsp;');
    }
}
