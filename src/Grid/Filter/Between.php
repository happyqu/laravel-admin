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

    protected $datetimeOptions = [];

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
        $name = parent::formatName($column);

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

        $input = Arr::get($inputs, $this->column);

        if (!is_array($input)) {
            return;
        }

        $value = $this->value = array_filter([
            'start' => $input['start'] ?? null,
            'end' => $input['end'] ?? null,
        ], static fn ($bound) => is_scalar($bound) && $bound !== '');

        if (empty($value)) {
            return;
        }

        if (!isset($value['start'])) {
            return $this->buildCondition($this->column, '<=', $value['end']);
        }

        if (!isset($value['end'])) {
            return $this->buildCondition($this->column, '>=', $value['start']);
        }

        $previous = $this->query;
        $this->query = 'whereBetween';
        try {
            return $this->buildCondition($this->column, array_values($value));
        } finally {
            $this->query = $previous;
        }
    }

    /**
     * @param array $options
     *
     * @return $this
     */
    public function datetime($options = [])
    {
        $this->view = 'admin::filter.betweenDatetime';

        $this->datetimeOptions = $options;

        return $this;
    }

    /**
     * @param array $options
     */
    protected function setupDatetime($options = [])
    {
        $options['format'] = Arr::get($options, 'format', 'YYYY-MM-DD HH:mm:ss');
        $options['locale'] = Arr::get($options, 'locale', config('app.locale'));

        $startOptions = json_encode($options);
        $endOptions = json_encode($options + ['useCurrent' => false]);
        $id = $this->getId();

        $script = <<<EOT
            $('#{$id['start']}').datetimepicker($startOptions);
            $('#{$id['end']}').datetimepicker($endOptions);
            $("#{$id['start']}").on("dp.change", function (e) {
                $('#{$id['end']}').data("DateTimePicker").minDate(e.date);
            });
            $("#{$id['end']}").on("dp.change", function (e) {
                $('#{$id['start']}').data("DateTimePicker").maxDate(e.date);
            });
EOT;

        Admin::script($script);
    }

    public function render()
    {
        if ($this->view === 'admin::filter.betweenDatetime') {
            $this->setupDatetime($this->datetimeOptions);
        }

        return parent::render();
    }
}
