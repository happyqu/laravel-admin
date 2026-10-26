<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;

class DateRange extends Field
{
    protected static $css = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.min.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.js',
        '/vendor/laravel-admin/flatpickr/dist/l10n/zh.js',
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
        if ($value === '') {
            $value = null;
        }

        return $value;
    }

    protected function flatpickrFormat(string $format): string
    {
        return strtr($format, [
            'YYYY' => 'Y',
            'YY'   => 'y',
            'MMMM' => 'F',
            'MMM'  => 'M',
            'MM'   => 'm',
            'M'    => 'n',
            'DDDD' => 'l',
            'DDD'  => 'D',
            'DD'   => 'd',
            'D'    => 'j',
            'HH'   => 'H',
            'H'    => 'G',
            'hh'   => 'h',
            'h'    => 'g',
            'mm'   => 'i',
            'ss'   => 'S',
        ]);
    }

    protected function flatpickrOptions(): array
    {
        $format = $this->flatpickrFormat($this->format);
        $options = [
            'dateFormat' => $format,
            'allowInput' => true,
            'locale'     => str_starts_with((string) config('app.locale'), 'zh') ? 'zh' : 'default',
        ];

        if (str_contains($format, 'H') || str_contains($format, 'h')) {
            $options['enableTime'] = true;
            $options['time_24hr'] = true;
        }

        if (str_contains($format, 'S')) {
            $options['enableSeconds'] = true;
        }

        if (!str_contains($format, 'Y') && !str_contains($format, 'm') && !str_contains($format, 'd')) {
            $options['noCalendar'] = true;
        }

        foreach ($this->options as $key => $value) {
            if (in_array($key, ['minDate', 'maxDate', 'defaultDate', 'disable', 'enable', 'position'], true)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    public function render()
    {
        $startOptions = json_encode($this->flatpickrOptions());
        $endOptions = json_encode($this->flatpickrOptions());

        $class = $this->getElementClassSelector();

        $this->script = <<<EOT
(function () {
    var startElement = document.querySelector('{$class['start']}');
    var endElement = document.querySelector('{$class['end']}');

    if (!startElement || !endElement || !window.flatpickr) {
        return;
    }

    var start = window.flatpickr(startElement, {$startOptions});
    var end = window.flatpickr(endElement, {$endOptions});

    start.config.onChange.push(function (dates) {
        if (dates[0]) {
            end.set('minDate', dates[0]);
        }
    });

    end.config.onChange.push(function (dates) {
        if (dates[0]) {
            start.set('maxDate', dates[0]);
        }
    });
})();
EOT;

        return parent::render();
    }
}
