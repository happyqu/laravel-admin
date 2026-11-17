<?php

namespace Encore\Admin\Actions;

class SweatAlert2
{
    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $title;

    /**
     * @param string $type
     * @param string $title
     *
     * @return $this
     */
    public function show($type, $title = '')
    {
        $this->type = $type;
        $this->title = $title;

        return $this;
    }

    /**
     * A response that only carries a redirect or refresh has no dialog to show;
     * an empty `swal` payload would make the front end open a blank alert.
     *
     * @return array
     */
    public function getOptions()
    {
        if ($this->type === null && ($this->title === null || $this->title === '')) {
            return [];
        }

        return [
            'swal' => [
                'type'  => $this->type,
                'title' => $this->title,
            ],
        ];
    }
}
