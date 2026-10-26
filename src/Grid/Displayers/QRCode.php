<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Facades\Admin;

/**
 * Class QRCode.
 */
class QRCode extends AbstractDisplayer
{
    protected function addScript()
    {
        $script = <<<'SCRIPT'
document.querySelectorAll('.grid-column-qrcode').forEach(function (element) {
    AdminUI.popover(element, {
        html: true,
        sanitize: false,
        trigger: 'focus',
        content: element.getAttribute('data-content')
    });
});
SCRIPT;

        Admin::script($script);
    }

    public function display($formatter = null, $width = 150, $height = 150)
    {
        $this->addScript();

        $content = $this->getColumn()->getOriginal();

        if ($formatter instanceof \Closure) {
            $content = call_user_func($formatter, $content, $this->row);
        }

        $img = sprintf(
            "<img src='https://api.qrserver.com/v1/create-qr-code/?size=%dx%d&amp;data=%s' style='height:%dpx;width:%dpx;'/>",
            (int) $width,
            (int) $height,
            rawurlencode((string) $content),
            (int) $height,
            (int) $width
        );

        return '<a href="javascript:void(0);" class="grid-column-qrcode text-body-secondary" data-content="'.e($img).'" tabindex="0">'
            .'<i class="fa fa-qrcode"></i></a>&nbsp;'.e($this->getValue());
    }
}
