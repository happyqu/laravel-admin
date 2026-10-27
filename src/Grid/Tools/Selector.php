<?php

namespace Encore\Admin\Grid\Tools;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Selector implements Renderable
{
    /**
     * @var array|Collection
     */
    protected $selectors = [];

    /**
     * @var array
     */
    protected static $selected;

    /**
     * Selector constructor.
     */
    public function __construct()
    {
        $this->selectors = new Collection();
    }

    /**
     * @param string         $column
     * @param string|array   $label
     * @param array|\Closure $options
     * @param null|\Closure  $query
     *
     * @return $this
     */
    public function select($column, $label, $options = [], $query = null)
    {
        return $this->addSelector($column, $label, $options, $query);
    }

    /**
     * @param string        $column
     * @param string        $label
     * @param array         $options
     * @param null|\Closure $query
     *
     * @return $this
     */
    public function selectOne($column, $label, $options = [], $query = null)
    {
        return $this->addSelector($column, $label, $options, $query, 'one');
    }

    /**
     * @param string $column
     * @param string $label
     * @param array  $options
     * @param null   $query
     * @param string $type
     *
     * @return $this
     */
    protected function addSelector($column, $label, $options = [], $query = null, $type = 'many')
    {
        if (is_array($label)) {
            if ($options instanceof \Closure) {
                $query = $options;
            }

            $options = $label;
            $label = __(Str::title($column));
        }

        $this->selectors[$column] = compact('label', 'options', 'type', 'query');

        return $this;
    }

    /**
     * Get all selectors.
     *
     * @return array|Collection
     */
    public function getSelectors()
    {
        return $this->selectors;
    }

    /**
     * @return array
     */
    public static function parseSelected()
    {
        $selected = request('_selector', []);

        if (!is_array($selected)) {
            return [];
        }

        $selected = array_filter($selected, static fn ($value) => is_string($value) || is_numeric($value));

        foreach ($selected as &$value) {
            $value = explode(',', $value);
        }

        return $selected;
    }

    /**
     * @param string $column
     * @param mixed  $value
     * @param bool   $add
     *
     * @return string
     */
    public static function url($column, $value = null, $add = false)
    {
        $query = request()->query();
        $selected = static::parseSelected();

        $options = Arr::get($selected, $column, []);

        if (is_null($value)) {
            Arr::forget($query, "_selector.{$column}");

            return request()->url().($query ? '?'.http_build_query($query) : '');
        }

        if (in_array($value, $options)) {
            array_delete($options, $value);
        } else {
            if ($add) {
                $options = [];
            }

            array_push($options, $value);
        }

        if (!empty($options)) {
            Arr::set($query, "_selector.{$column}", implode(',', $options));
        } else {
            Arr::forget($query, "_selector.{$column}");
        }

        return request()->url().($query ? '?'.http_build_query($query) : '');
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        return view('admin::grid.selector', [
            'selectors' => $this->selectors,
            'selected'  => static::parseSelected(),
        ]);
    }
}
