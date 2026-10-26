<?php

namespace Encore\Admin\Grid\Filter;

use Encore\Admin\Admin;
use Illuminate\Support\Arr;

class Between extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::filter.between';

    /**
     * Format id.
     *
     * @param string $column
     *
     * @return array|string
     */
    public function formatId($column)
    {
        $id = str_replace('.', '_', $column);

        return ['start' => "{$id}_start", 'end' => "{$id}_end"];
    }

    /**
     * Format two field names of this filter.
     *
     * @param string $column
     *
     * @return array
     */
    protected function formatName($column)
    {
        $columns = explode('.', $column);

        if (count($columns) == 1) {
            $name = $columns[0];
        } else {
            $name = array_shift($columns);

            foreach ($columns as $column) {
                $name .= "[$column]";
            }
        }

        return ['start' => "{$name}[start]", 'end' => "{$name}[end]"];
    }

    /**
     * Get condition of this filter.
     *
     * @param array $inputs
     *
     * @return mixed
     */
    public function condition($inputs)
    {
        if ($this->ignore) {
            return;
        }

        if (!Arr::has($inputs, $this->column)) {
            return;
        }

        $this->value = Arr::get($inputs, $this->column);

        if (!is_array($this->value)) {
            return;
        }

        $value = array_filter($this->value, function ($val) {
            return $val !== '';
        });

        if (empty($value)) {
            return;
        }

        if (!isset($value['start'])) {
            return $this->buildCondition($this->column, '<=', $value['end']);
        }

        if (!isset($value['end'])) {
            return $this->buildCondition($this->column, '>=', $value['start']);
        }

        $this->query = 'whereBetween';

        return $this->buildCondition($this->column, $this->value);
    }

    /**
     * @param array $options
     *
     * @return $this
     */
    public function datetime($options = [])
    {
        $this->view = 'admin::filter.betweenDatetime';

        $this->setupDatetime($options);

        return $this;
    }

    /**
     * @param array $options
     */
    protected function setupDatetime($options = [])
    {
        $options['format'] = Arr::get($options, 'format', 'YYYY-MM-DD HH:mm:ss');

        $format = strtr($options['format'], [
            'YYYY' => 'Y', 'YY' => 'y', 'MM' => 'm', 'DD' => 'd',
            'HH' => 'H', 'mm' => 'i', 'ss' => 'S',
        ]);

        $dateOptions = [
            'dateFormat'    => $format,
            'allowInput'    => true,
            'enableTime'    => true,
            'time_24hr'     => true,
            'enableSeconds' => str_contains($format, 'S'),
            'locale'        => str_starts_with((string) config('app.locale'), 'zh') ? 'zh' : 'default',
        ];

        $startId = json_encode($this->id['start']);
        $endId = json_encode($this->id['end']);
        $dateOptions = json_encode($dateOptions);

        Admin::css('vendor/laravel-admin/flatpickr/dist/flatpickr.min.css');
        Admin::js('vendor/laravel-admin/flatpickr/dist/flatpickr.js');
        Admin::js('vendor/laravel-admin/flatpickr/dist/l10n/zh.js');

        $script = <<<EOT
(function () {
    var startElement = document.getElementById({$startId});
    var endElement = document.getElementById({$endId});

    if (!startElement || !endElement || !window.flatpickr) {
        return;
    }

    var start = flatpickr(startElement, {$dateOptions});
    var end = flatpickr(endElement, {$dateOptions});

    start.config.onChange.push(function (dates) {
        if (dates[0]) { end.set('minDate', dates[0]); }
    });

    end.config.onChange.push(function (dates) {
        if (dates[0]) { start.set('maxDate', dates[0]); }
    });
})();
EOT;

        Admin::script($script);
    }
}
