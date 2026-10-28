<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Support\Url;

class Link extends AbstractDisplayer
{
    public function display($callback = '', $target = '_blank')
    {
        if ($callback instanceof \Closure) {
            $callback = $callback->bindTo($this->row);
            $href = call_user_func_array($callback, [$this->row]);
        } else {
            $href = $callback ?: $this->value;
        }

        $href = Url::sanitizeHref($href);
        if ($href === null) {
            return e($this->value);
        }

        $target = in_array($target, ['_blank', '_self', '_parent', '_top'], true) ? $target : '_blank';

        return "<a href='".e($href)."' target='".e($target)."'>".e($this->value)."</a>";
    }
}
