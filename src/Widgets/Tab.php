<?php

namespace Encore\Admin\Widgets;

use Encore\Admin\Facades\Admin;
use Illuminate\Contracts\Support\Renderable;

class Tab extends Widget implements Renderable
{
    use ContainsForms;

    const TYPE_CONTENT = 1;
    const TYPE_LINK = 2;

    /**
     * @var string
     */
    protected $view = 'admin::widgets.tab';

    /**
     * @var array
     */
    protected $data = [
        'id'       => '',
        'title'    => '',
        'tabs'     => [],
        'dropDown' => [],
        'active'   => 0,
    ];

    public function __construct()
    {
        $this->id('widget-tabs-'.uniqid());
        $this->class('nav-tabs-custom');
    }

    /**
     * Add a tab and its contents.
     *
     * @param string            $title
     * @param string|Renderable $content
     * @param bool              $active
     * @param string|null       $id
     *
     * @return $this
     */
    public function add($title, $content, $active = false, $id = null)
    {
        $this->data['tabs'][] = [
            'id'      => $id ?? mt_rand(),
            'title'   => $title,
            'content' => $content,
            'type'    => static::TYPE_CONTENT,
        ];

        if ($active) {
            $this->data['active'] = count($this->data['tabs']) - 1;
        }

        return $this;
    }

    /**
     * Add a link on tab.
     *
     * @param string $title
     * @param string $href
     * @param bool   $active
     *
     * @return $this
     */
    public function addLink($title, $href, $active = false)
    {
        $this->data['tabs'][] = [
            'id'      => mt_rand(),
            'title'   => $title,
            'href'    => $href,
            'type'    => static::TYPE_LINK,
        ];

        if ($active) {
            $this->data['active'] = count($this->data['tabs']) - 1;
        }

        return $this;
    }

    /**
     * Set title.
     *
     * @param string $title
     */
    public function title($title = '')
    {
        $this->data['title'] = $title;

        return $this;
    }

    /**
     * Set drop-down items.
     *
     * @param array $links
     *
     * @return $this
     */
    public function dropDown(array $links)
    {
        if ($links === []) {
            return $this;
        }
        if (is_array($links[0])) {
            foreach ($links as $link) {
                call_user_func([$this, 'dropDown'], $link);
            }

            return $this;
        }

        if (!isset($links[0], $links[1])) {
            throw new \InvalidArgumentException('Dropdown items require a label and URL.');
        }
        $this->data['dropDown'][] = [
            'name' => $links[0],
            'href' => $links[1],
        ];

        return $this;
    }

    /**
     * Render Tab.
     *
     * @return string
     */
    public function render()
    {
        $data = array_merge(
            $this->data,
            ['attributes' => $this->formatAttributes()]
        );

        $this->setupScript();

        return view($this->view, $data)->render();
    }

    /**
     * Setup script.
     */
    protected function setupScript()
    {
        $id = json_encode($this->id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $script = <<<SCRIPT
;(function () {
var root = $(document.getElementById({$id}));
var links = root.children('.nav-tabs').find('a[data-toggle="tab"]');
var hash = document.location.hash;
if (hash) {
    links.filter(function () { return this.hash === hash; }).tab('show');
}

// Change hash for page-reload
links.off('shown.bs.tab.adminWidgetTab').on('shown.bs.tab.adminWidgetTab', function (e) {
    if (e.target.hash && document.location.hash !== e.target.hash) {
        history.pushState(null, null, e.target.hash);
    }
});
})();
SCRIPT;
        Admin::script($script);
    }
}
