<?php

namespace Encore\Admin\Grid\Selectable;

use Illuminate\Contracts\Support\Renderable;

class BrowserBtn implements Renderable
{
    public function render()
    {
        $text = admin_trans('admin.choose');

        $html = <<<HTML
<a href="javascript:void(0)" class="btn btn-primary btn-sm float-start select-relation">
    <i class="fa fa-folder-open"></i>
    &nbsp;&nbsp;{$text}
</a>
HTML;

        return $html;
    }
}
