<?php

namespace Encore\Admin\Layout;

use Encore\Admin\Grid;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;

class Column implements Buildable
{
    /**
     * grid system prefix width.
     *
     * @var array
     */
    protected $width = [];

    /**
     * @var array
     */
    protected $contents = [];

    /**
     * Column constructor.
     *
     * @param $content
     * @param int $width
     */
    public function __construct($content, $width = 12)
    {
        $this->width = $this->normalizeWidth($width);

        if ($content instanceof \Closure) {
            call_user_func($content, $this);
        } else {
            $this->append($content);
        }
    }

    protected function normalizeWidth($width): array
    {
        if ($width === null || $width === []) {
            return ['md' => 12];
        }
        $widths = is_numeric($width) ? ['md' => $width] : $width;
        if (!is_array($widths)) {
            throw new \InvalidArgumentException('Column width must be a number or breakpoint map.');
        }
        foreach ($widths as $breakpoint => &$size) {
            if (!is_string($breakpoint) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $breakpoint) || !is_numeric($size)) {
                throw new \InvalidArgumentException('Invalid column breakpoint or width.');
            }
            $size = (float) $size;
            $size = $size > 0 && $size < 1 ? round(12 * $size) : $size;
            $minimum = preg_match('/-(offset|push|pull)$/', $breakpoint) ? 0 : 1;
            if (!is_finite($size) || $size < $minimum || $size > 12 || floor($size) !== $size) {
                throw new \InvalidArgumentException('Column width must be an integer between 1 and 12.');
            }
            $size = (int) $size;
        }

        return $widths;
    }

    /**
     * Append content to column.
     *
     * @param $content
     *
     * @return $this
     */
    public function append($content)
    {
        $this->contents[] = $content;

        return $this;
    }

    /**
     * Add a row for column.
     *
     * @param $content
     *
     * @return Column
     */
    public function row($content)
    {
        if (!$content instanceof \Closure) {
            $row = new Row($content);
        } else {
            $row = new Row();

            call_user_func($content, $row);
        }

        return $this->append($row);
    }

    /**
     * Build column html.
     */
    public function build()
    {
        $this->startColumn();

        foreach ($this->contents as $content) {
            if ($content instanceof Renderable || $content instanceof Grid) {
                echo $content->render();
            } elseif ($content instanceof Buildable) {
                $content->build();
            } elseif ($content instanceof Htmlable) {
                echo $content->toHtml();
            } else {
                echo (string) $content;
            }
        }

        $this->endColumn();
    }

    /**
     * Start column.
     */
    protected function startColumn()
    {
        // get class name using width array
        $classnName = collect($this->width)->map(function ($value, $key) {
            return "col-$key-$value";
        })->implode(' ');

        echo '<div class="'.e($classnName).'">';
    }

    /**
     * End column.
     */
    protected function endColumn()
    {
        echo '</div>';
    }
}
