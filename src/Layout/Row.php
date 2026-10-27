<?php

namespace Encore\Admin\Layout;

use Illuminate\Contracts\Support\Renderable;

class Row implements Buildable, Renderable
{
    /**
     * @var Column[]
     */
    protected $columns = [];

    /**
     * row classes.
     *
     * @var array
     */
    protected $class = [];

    /**
     * Row constructor.
     *
     * @param string $content
     */
    public function __construct($content = '')
    {
        if ($content !== '' && $content !== null) {
            $this->column(12, $content);
        }
    }

    /**
     * Add a column.
     *
     * @param int $width
     * @param $content
     */
    public function column($width, $content)
    {
        $column = new Column($content, $width);

        $this->addColumn($column);
    }

    /**
     * Add class in row.
     *
     * @param array|string $class
     */
    public function class($class)
    {
        if (is_string($class)) {
            $class = [$class];
        }

        $this->class = $class;

        return $this;
    }

    /**
     * @param Column $column
     */
    protected function addColumn(Column $column)
    {
        $this->columns[] = $column;
    }

    /**
     * Build row column.
     */
    public function build()
    {
        $this->startRow();

        foreach ($this->columns as $column) {
            $column->build();
        }

        $this->endRow();
    }

    /**
     * Start row.
     */
    protected function startRow()
    {
        $class = $this->class;
        $class[] = 'row';
        echo '<div class="'.e(implode(' ', $class)).'">';
    }

    /**
     * End column.
     */
    protected function endRow()
    {
        echo '</div>';
    }

    /**
     * Render row.
     *
     * @return string
     */
    public function render()
    {
        $level = ob_get_level();
        ob_start();
        try {
            $this->build();

            return ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
