<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\EmbeddedForm;
use Encore\Admin\Form\Field;
use Encore\Admin\Widgets\Form as WidgetForm;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Embeds extends Field
{
    /**
     * @var \Closure
     */
    protected $builder = null;

    /**
     * Create a new HasMany field instance.
     *
     * @param string $column
     * @param array  $arguments
     */
    public function __construct($column, $arguments = [])
    {
        $this->column = $column;

        if (count($arguments) == 1) {
            $this->label = $this->formatLabel();
            $this->builder = $arguments[0];
        }

        if (count($arguments) == 2) {
            list($this->label, $this->builder) = $arguments;
        }
    }

    /**
     * Prepare input data for insert or update.
     *
     * @param array $input
     *
     * @return array
     */
    public function prepare($input)
    {
        $form = $this->buildEmbeddedForm();

        return $form->setOriginal($this->original)->prepare($input);
    }

    public function sanitize($input): array
    {
        return \Encore\Admin\Form\SubmissionInput::onlyFields((array) $input, $this->buildEmbeddedForm()->fields());
    }

    /**
     * {@inheritdoc}
     */
    public function getValidator(array $input)
    {
        if (!$this->isWritable()) {
            return false;
        }
        if ($this->validator) {
            return parent::getValidator($input);
        }
        if (($container = parent::getValidator($input)) && !$container->passes()) {
            return $container;
        }
        if (!array_key_exists($this->column, $input)) {
            return $container ?: false;
        }
        if (!is_array($input[$this->column])) {
            return \validator($input, [$this->column => 'array']);
        }

        $input = Arr::only($input, $this->column);

        $validator = \validator([], []);
        $original = $this->original ?: [];
        if (is_string($original)) {
            $original = json_decode($original, true) ?: [];
        }
        \Encore\Admin\Form\NestedValidation::append($validator,
            $this->buildEmbeddedForm()->fields(), $input[$this->column], $this->column, $original);

        return $validator;
    }

    /**
     * Format validation attributes.
     *
     * @param array  $input
     * @param string $label
     * @param string $column
     *
     * @return array
     */
    protected function formatValidationAttribute($input, $label, $column)
    {
        $new = $attributes = [];

        if (is_array($column)) {
            foreach ($column as $index => $col) {
                $new[$col.$index] = $col;
            }
        }

        foreach (array_keys(Arr::dot($input)) as $key) {
            if (is_string($column)) {
                if (Str::endsWith($key, ".$column")) {
                    $attributes[$key] = $label;
                }
            } else {
                foreach ($new as $k => $val) {
                    if (Str::endsWith($key, ".$k")) {
                        $attributes[$key] = $label."[$val]";
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * Reset input key for validation.
     *
     * @param array $input
     * @param array $column $column is the column name array set
     *
     * @return void.
     */
    public function resetInputKey(array &$input, array $column)
    {
        $column = array_flip($column);

        foreach ($input[$this->column] as $key => $value) {
            if (!array_key_exists($key, $column)) {
                continue;
            }

            $newKey = $key.$column[$key];

            /*
             * set new key
             */
            Arr::set($input, "{$this->column}.$newKey", $value);
            /*
             * forget the old key and value
             */
            Arr::forget($input, "{$this->column}.$key");
        }
    }

    /**
     * Get data for Embedded form.
     *
     * Normally, data is obtained from the database.
     *
     * When the data validation errors, data is obtained from session flash.
     *
     * @return array
     */
    protected function getEmbeddedData()
    {
        if ($old = old($this->column)) {
            return $old;
        }

        if (empty($this->value)) {
            return [];
        }

        if (is_string($this->value)) {
            return json_decode($this->value, true);
        }

        return (array) $this->value;
    }

    /**
     * Build a Embedded Form and fill data.
     *
     * @return EmbeddedForm
     */
    protected function buildEmbeddedForm()
    {
        $form = new EmbeddedForm($this->getEmbeddedColumnName());

        if ($this->form instanceof WidgetForm) {
            $form->setParentWidgetForm($this->form);
        } else {
            $form->setParent($this->form);
        }

        call_user_func($this->builder, $form);

        $form->fill($this->getEmbeddedData());

        return $form;
    }

    /**
     * Determine the column name to use with the embedded form.
     *
     * @return array|string
     */
    protected function getEmbeddedColumnName()
    {
        if ($this->isNested()) {
            return $this->elementName;
        }

        return $this->column;
    }

    /**
     * Check if the field is in a nested form.
     *
     * @return bool
     */
    protected function isNested()
    {
        return !empty($this->elementName);
    }

    /**
     * Render the form.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return parent::fieldRender(['form' => $this->buildEmbeddedForm()]);
    }
}
