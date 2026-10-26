<?php

namespace Encore\Admin\Widgets;

use Illuminate\Contracts\Support\Renderable;

class InfoBox extends Widget implements Renderable
{
    /**
     * @var string
     */
    protected $view = 'admin::widgets.info-box';

    /**
     * @var array
     */
    protected $data = [];

    /**
     * InfoBox constructor.
     *
     * @param string $name
     * @param string $icon
     * @param string $color
     * @param string $link
     * @param string $info
     */
    public function __construct($name, $icon, $color, $link, $info)
    {
        $this->data = [
            'name' => $name,
            'icon' => $icon,
            'link' => $link,
            'info' => $info,
        ];

        $themes = [
            'green'      => 'success',
            'red'        => 'danger',
            'yellow'     => 'warning',
            'aqua'       => 'info',
            'blue'       => 'info',
            'light-blue' => 'info',
            'purple'     => 'primary',
            'maroon'     => 'danger',
            'navy'       => 'dark',
            'teal'       => 'info',
            'olive'      => 'success',
            'lime'       => 'success',
            'orange'     => 'warning',
            'fuchsia'    => 'danger',
            'gray'       => 'secondary',
            'black'      => 'dark',
        ];

        $theme = $themes[$color] ?? $color;

        $this->class("small-box text-bg-$theme");
    }

    /**
     * @return string
     */
    public function render()
    {
        $variables = array_merge($this->data, ['attributes' => $this->formatAttributes()]);

        return view($this->view, $variables)->render();
    }
}
