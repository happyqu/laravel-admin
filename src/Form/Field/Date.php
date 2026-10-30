<?php

namespace Encore\Admin\Form\Field;

class Date extends Text
{
    protected static $css = [
        '/vendor/laravel-admin/flatpickr/dist/flatpickr.min.css',
        '/vendor/laravel-admin/laravel-admin/datepicker.css?v=4',
    ];

    protected static $js = [
        '/vendor/laravel-admin/laravel-admin/flatpickr-local.js?v=1',
        '/vendor/laravel-admin/laravel-admin/flatpickr-locales.js',
        '/vendor/laravel-admin/laravel-admin/datepicker.js?v=11',
    ];

    protected $format = 'YYYY-MM-DD';

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

    public function render()
    {
        $this->options['format'] = $this->format;
        $this->options['locale'] = array_key_exists('locale', $this->options) ? $this->options['locale'] : config('app.locale');
        $this->options['allowInputToggle'] = true;

        $selector = $this->getElementClassSelector();
        $selector = is_array($selector) ? implode(',', $selector) : $selector;

        // Standard fields need the input group for the calendar button; action
        // templates without that group can initialize the input itself.
        $options = json_encode($this->options);
        $this->script = <<<JS
$('{$selector}').filter('input').each(function () {
    var input = $(this);
    var group = input.parent('.input-group');
    (group.length ? group : input).adminDatepicker({$options});
});
JS;

        $this->prepend('<i class="fa fa-calendar fa-fw"></i>')
            ->defaultAttribute('autocomplete', 'off')
            ->defaultAttribute('style', 'width: 110px');

        return parent::render();
    }
}
