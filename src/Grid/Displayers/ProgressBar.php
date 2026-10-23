<?php

namespace Encore\Admin\Grid\Displayers;

class ProgressBar extends AbstractDisplayer
{
    public function display($style = 'primary', $size = 'sm', $max = 100)
    {
        $style = collect((array) $style)->map(function ($style) {
            $style = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $style);

            return $style === '' ? '' : 'progress-bar-'.$style;
        })->filter()->implode(' ');

        $size = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $size);
        $this->value = (int) $this->value;
        $max = (int) $max;

        return '<div class="row" style="min-width: 100px;">'
            .'<span class="col-sm-3" style="color:#777;">'.$this->value.'%</span>'
            .'<div class="progress progress-'.e($size).' col-sm-9" style="padding-left: 0;padding-right: 0;width: 100px;">'
            .'<div class="progress-bar '.e($style).'" role="progressbar" aria-valuenow="'.$this->value.'" aria-valuemin="0" aria-valuemax="'.$max.'" style="width: '.$this->value.'%"></div>'
            .'</div></div>';
    }
}
