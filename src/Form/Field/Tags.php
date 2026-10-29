<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class Tags extends Field
{
    /**
     * @var array
     */
    protected $value = [];

    /**
     * @var bool
     */
    protected $keyAsValue = false;

    /**
     * @var string
     */
    protected $visibleColumn = null;

    /**
     * @var string
     */
    protected $key = null;

    /**
     * @var \Closure
     */
    protected $saveAction = null;

    /**
     * @var array
     */
    protected $separators = [',', ';', '，', '；', ' '];

    /**
     * @var array
     */
    protected static $css = [
        '/vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css',
    ];

    /**
     * @var array
     */
    protected static $js = [
        '/vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js',
    ];

    /**
     * {@inheritdoc}
     */
    public function fill($data)
    {
        $this->value = Arr::get($data, $this->column);

        if (is_array($this->value) && $this->keyAsValue) {
            $this->value = array_column($this->value, $this->visibleColumn, $this->key);
        }

        if (is_string($this->value)) {
            $this->value = explode(',', $this->value);
        }

        $this->value = array_filter((array) $this->value,
            static fn ($item) => is_scalar($item) && (string) $item !== '');
    }

    /**
     * Set visible column and key of data.
     *
     * @param $visibleColumn
     * @param $key
     *
     * @return $this
     */
    public function pluck($visibleColumn, $key)
    {
        if (!empty($visibleColumn) && !empty($key)) {
            $this->keyAsValue = true;
        }

        $this->visibleColumn = $visibleColumn;
        $this->key = $key;

        return $this;
    }

    /**
     * Set the field options.
     *
     * @param array|Collection|Arrayable $options
     *
     * @return $this|Field
     */
    public function options($options = [])
    {
        if (!$this->keyAsValue) {
            return parent::options($options);
        }

        if ($options instanceof Collection) {
            $options = $options->pluck($this->visibleColumn, $this->key)->toArray();
        }

        if ($options instanceof Arrayable) {
            $options = $options->toArray();
        }

        $this->options = $options + $this->options;

        return $this;
    }

    /**
     * Set Tag Separators.
     *
     * @param array $separators
     *
     * @return $this
     */
    public function separators($separators = [])
    {
        if ($separators instanceof Collection or $separators instanceof Arrayable) {
            $separators = $separators->toArray();
        }
        if (!empty($separators)) {
            $this->separators = $separators;
        }

        return $this;
    }

    /**
     * Set save Action.
     *
     * @param \Closure $saveAction
     *
     * @return $this
     */
    public function saving(\Closure $saveAction)
    {
        $this->saveAction = $saveAction;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($value)
    {
        if (!is_array($value) && $value !== null && !is_scalar($value)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'Expected an array of tags.']);
        }
        foreach ((array) $value as $item) {
            if ($item !== null && !is_scalar($item)) {
                throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'Expected scalar tag values.']);
            }
            if (!$this->keyAsValue && is_scalar($item) && str_contains((string) $item, ',')) {
                throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'Tags cannot contain commas.']);
            }
        }
        $value = array_values(array_filter((array) $value,
            static fn ($item) => is_scalar($item) && (string) $item !== ''));

        if ($this->keyAsValue) {
            return is_null($this->saveAction) ? $value : ($this->saveAction)($value);
        }

        if (is_array($value) && !Arr::isAssoc($value)) {
            $value = implode(',', $value);
        }

        return $value;
    }

    public function getValidator(array $input)
    {
        if (!$this->isWritable()) {
            return false;
        }
        if ($this->validator) {
            return parent::getValidator($input);
        }
        $input = $this->sanitizeInput($input, $this->column);
        $rules = [$this->column => array_merge(['array'], (array) $this->getRules())];
        $rules[$this->column.'.*'] = [function ($attribute, $value, $fail) {
            if (!is_scalar($value)) {
                $fail('Expected scalar tag values.');
            } elseif (!$this->keyAsValue && str_contains((string) $value, ',')) {
                $fail('Tags cannot contain commas.');
            }
        }];

        return validator($input, $rules, $this->getValidationMessages(), [
            $this->column => $this->label, $this->column.'.*' => $this->label,
        ]);
    }

    /** Normalize UI values without interpreting a comma inside an individual item. */
    protected function normalizeValues($value): array
    {
        if (!is_array($value) && $value !== null && !is_scalar($value)) {
            return [];
        }
        return array_values(array_map('strval', array_filter((array) $value,
            static fn ($item) => is_scalar($item) && (string) $item !== '')));
    }

    /**
     * Get or set value for this field.
     *
     * @param mixed $value
     *
     * @return $this|array|mixed
     */
    public function value($value = null)
    {
        if (is_null($value)) {
            return empty($this->value) ? ($this->getDefault() ?? []) : $this->value;
        }

        $this->value = (array) $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        if (!$this->shouldRender()) {
            return '';
        }

        $this->setupScript();

        $missing = new \stdClass();
        $old = old($this->getErrorKey(), $missing);
        if ($this->keyAsValue) {
            $options = $this->value + $this->options;
            $selected = $old !== $missing ? $old : ($this->value ? array_keys($this->value) : $this->getDefault());
            $selected = $this->normalizeValues($selected);
            foreach ($selected as $key) {
                if (!array_key_exists($key, $options)) {
                    $options[$key] = $key;
                }
            }
        } else {
            $selected = $old !== $missing ? $old : $this->value();
            if (is_string($selected)) {
                $selected = explode(',', $selected);
            }
            $selected = $this->normalizeValues($selected);
            $options = array_unique(array_merge($this->normalizeValues($this->value),
                $selected, $this->normalizeValues($this->options)));
        }

        return parent::fieldRender([
            'options'        => $options,
            'keyAsValue'     => $this->keyAsValue,
            'selectedValues' => $selected,
        ]);
    }

    protected function sanitizeInput($input, $column)
    {
        $value = Arr::get($input, $column);
        if (is_array($value)) {
            Arr::set($input, $column, array_values(array_filter($value,
                static fn ($item) => $item !== null && (!is_scalar($item) || (string) $item !== ''))));
        }

        return $input;
    }

    protected function setupScript()
    {
        $separators = json_encode($this->separators);
        $allowComma = $this->keyAsValue ? 'true' : 'false';
        $this->script = <<<JS
$("{$this->getElementClassSelector()}")
.off('select2:selecting.adminTags')
.on('select2:selecting.adminTags', function (event) {
    var data = event.params && event.params.args && event.params.args.data;
    if (!$allowComma && data && String(data.id).indexOf(',') !== -1) {
        event.preventDefault();
    }
})
.select2({
    tags: true,
    tokenSeparators: $separators,
    createTag: function(params) {
        var term = params.term.trim();
        return term === '' || (!$allowComma && term.indexOf(',') !== -1) ? null : { id: term, text: term };
    }
});
JS;
    }
}
