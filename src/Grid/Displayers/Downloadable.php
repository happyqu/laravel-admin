<?php

namespace Encore\Admin\Grid\Displayers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Storage;

class Downloadable extends AbstractDisplayer
{
    public function display($server = '')
    {
        if ($this->value instanceof Arrayable) {
            $this->value = $this->value->toArray();
        }

        return collect((array) $this->value)->filter()->map(function ($value) use ($server) {
            if (empty($value)) {
                return '';
            }

            if (url()->isValidUrl($value)) {
                $src = $value;
            } elseif ($server) {
                $src = rtrim($server, '/').'/'.ltrim($value, '/');
            } else {
                $src = Storage::disk(config('admin.upload.disk'))->url($value);
            }

            $name = basename($value);

            return "<a href='".e($src)."' download='".e($name)."' target='_blank' class='text-muted'>"
                .'<i class="fa fa-download"></i> '.e($name).'</a>';
        })->implode('<br>');
    }
}
