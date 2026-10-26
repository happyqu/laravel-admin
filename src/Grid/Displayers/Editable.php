<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;
use Illuminate\Support\Arr;

class Editable extends AbstractDisplayer
{
    /**
     * @var array
     */
    protected $arguments = [];

    /**
     * Type of editable.
     *
     * @var string
     */
    protected $type = 'text';

    /**
     * Options of editable function.
     *
     * @var array
     */
    protected $options = [
        'emptytext' => '<i class="fa fa-pencil"></i>',
    ];

    /**
     * @var array
     */
    protected $attributes = [];

    /**
     * Add options for editable.
     *
     * @param array $options
     */
    public function addOptions($options = [])
    {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * Add attributes for editable.
     *
     * @param array $attributes
     */
    public function addAttributes($attributes = [])
    {
        $this->attributes = array_merge($this->attributes, $attributes);
    }

    public function text()
    {
        $this->type = 'text';
    }

    public function textarea()
    {
        $this->type = 'textarea';
    }

    /**
     * Select type editable.
     *
     * @param array|\Closure $options
     */
    public function select($options = [])
    {
        $useClosure = false;

        if ($options instanceof \Closure) {
            $useClosure = true;
            $options = $options->call($this, $this->row);
        }

        $source = [];

        foreach ($options as $value => $text) {
            $source[] = compact('value', 'text');
        }

        $this->type = 'select';

        if ($useClosure) {
            $this->addAttributes(['data-source' => json_encode($source)]);
        } else {
            $this->addOptions(compact('source'));
        }
    }

    public function date()
    {
        $this->type = 'date';
    }

    public function datetime()
    {
        $this->type = 'datetime';
    }

    public function year()
    {
        $this->type = 'year';
    }

    public function month()
    {
        $this->type = 'month';
    }

    public function day()
    {
        $this->type = 'date';
    }

    public function time()
    {
        $this->type = 'time';
    }

    /**
     * Combodate type editable.
     *
     * @param string $format
     */
    public function combodate($format = 'YYYY-MM-DD')
    {
        if (str_contains($format, 'HH') && str_contains($format, 'YYYY')) {
            $this->type = 'datetime';
        } elseif (str_contains($format, 'HH')) {
            $this->type = 'time';
        } elseif ($format === 'YYYY') {
            $this->type = 'year';
        } elseif ($format === 'MM') {
            $this->type = 'month';
        } else {
            $this->type = 'date';
        }
    }

    /**
     * @param array $arguments
     */
    protected function buildEditableOptions(array $arguments = [])
    {
        $this->type = Arr::get($arguments, 0, 'text');

        if (method_exists($this, $this->type)) {
            call_user_func_array([$this, $this->type], array_slice($arguments, 1));
        }
    }

    /**
     * @return string
     */
    public function display()
    {
        $column = $this->getName();

        $this->buildEditableOptions(func_get_args());

        $class = 'grid-editable-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $column);
        $value = (string) ($this->value ?? '');

        $attributes = [
            'href'              => 'javascript:void(0);',
            'class'             => $class,
            'data-inline-edit'  => '1',
            'data-type'         => $this->type,
            'data-pk'           => (string) $this->getKey(),
            'data-url'          => "{$this->getResource()}/{$this->getKey()}",
            'data-name'         => $column,
            'data-value'        => $value,
            'data-original'     => $value,
        ];

        if (!empty($this->options['source'])) {
            $attributes['data-source'] = json_encode($this->options['source']);
        }

        if (!empty($this->attributes)) {
            $attributes = array_merge($attributes, $this->attributes);
        }

        $attributes = collect($attributes)->map(function ($attribute, $name) {
            $name = preg_replace('/[^A-Za-z0-9_:-]/', '', (string) $name);

            return $name === '' ? '' : $name.'="'.e($attribute).'"';
        })->filter()->implode(' ');

        $html = $value !== '' ? e($value) : ($this->options['emptytext'] ?? '');

        Admin::js('vendor/laravel-admin/laravel-admin/grid-inline-edit.js');

        return "<a $attributes><span class=\"ie-display\">{$html}</span> <i class=\"fa fa-pencil\" style=\"visibility: hidden;\"></i></a>";
    }
}
