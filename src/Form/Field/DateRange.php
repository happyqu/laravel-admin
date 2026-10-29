<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;

class DateRange extends Field
{
    protected static $css = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.min.css',
        '/vendor/laravel-admin/laravel-admin/datepicker.css?v=4',
    ];

    protected static $js = [
        '/vendor/laravel-admin/laravel-admin/flatpickr-local.js?v=1',
        '/vendor/laravel-admin/laravel-admin/flatpickr-locales.js',
        '/vendor/laravel-admin/laravel-admin/datepicker.js?v=6',
    ];

    protected $format = 'YYYY-MM-DD';

    /**
     * Column name.
     *
     * @var array
     */
    protected $column = [];

    public function __construct($column, $arguments)
    {
        $this->column['start'] = $column;
        $this->column['end'] = $arguments[0];

        array_shift($arguments);
        $this->label = $this->formatLabel($arguments);
        $this->id = $this->formatId($this->column);

        $this->options(['format' => $this->format]);
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($value)
    {
        if (is_array($value)) {
            return array_map(static fn ($date) => $date === '' ? null : $date, $value);
        }

        if ($value === '') {
            $value = null;
        }

        return $value;
    }

    public function render()
    {
        $this->options['locale'] = array_key_exists('locale', $this->options) ? $this->options['locale'] : config('app.locale');

        $options = json_encode($this->options);
        $class = $this->getElementClassSelector();
        $this->script = "$.admin.datepicker.range('{$class['start']}', '{$class['end']}', {$options});";

        return parent::render();
    }
}
