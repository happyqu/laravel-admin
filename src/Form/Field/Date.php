<?php

namespace Encore\Admin\Form\Field;

class Date extends Text
{
    protected static $css = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.min.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.js',
        '/vendor/laravel-admin/flatpickr/dist/l10n/zh.js',
    ];

    protected $format = 'YYYY-MM-DD';

    protected $icon = 'fa-calendar';

    protected $nativeInput = false;

    public function format($format)
    {
        $this->format = $format;

        return $this;
    }

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
            if (in_array($key, [
                'minDate', 'maxDate', 'defaultDate', 'disable', 'enable', 'inline', 'static',
                'position', 'showMonths', 'weekNumbers', 'minuteIncrement', 'hourIncrement',
                'clickOpens', 'allowInput', 'time_24hr', 'enableTime', 'noCalendar',
                'enableSeconds', 'defaultHour', 'defaultMinute', 'defaultSeconds',
            ], true)) {
                $options[$key] = $value;
            }
        }

        if (isset($this->options['defaultTime']) && is_string($this->options['defaultTime'])) {
            $time = array_map('intval', explode(':', $this->options['defaultTime']));
            $options['defaultHour'] = $time[0] ?? 12;
            $options['defaultMinute'] = $time[1] ?? 0;
        }

        return $options;
    }

    public function render()
    {
        if ($this->nativeInput) {
            $this->attribute('type', $this->format === 'Y-m' ? 'month' : 'number');

            if ($this->format === 'Y') {
                $this->attribute('min', 1900);
                $this->attribute('max', 2100);
            }

            return parent::render();
        }

        $options = json_encode($this->flatpickrOptions());

        $this->script = <<<SCRIPT
(function () {
    var element = document.querySelector('{$this->getElementClassSelector()}');
    if (element && window.flatpickr) {
        window.flatpickr(element, {$options});
    }
})();
SCRIPT;

        $this->prepend('<i class="fa '.$this->icon.' fa-fw"></i>')
            ->defaultAttribute('style', 'width: 110px');

        return parent::render();
    }
}
