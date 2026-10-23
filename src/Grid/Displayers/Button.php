<?php

namespace Encore\Admin\Grid\Displayers;

class Button extends AbstractDisplayer
{
    public function display($style = null)
    {
        $style = collect((array) $style)->map(function ($style) {
            $style = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $style);

            return $style === '' ? '' : 'btn-'.$style;
        })->filter()->implode(' ');

        return "<span class='btn ".e($style)."'>".e($this->value).'</span>';
    }
}
