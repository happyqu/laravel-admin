<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Cascader\HasSource;
use Encore\Admin\Form\Field;
use Illuminate\Support\Arr;

class Cascader extends Field
{
    use HasSource;

    protected static $css = ['/vendor/laravel-admin/laravel-admin/cascader.css'];
    protected static $js = ['/vendor/laravel-admin/laravel-admin/cascader.js'];

    public function __construct($column, $arguments = [])
    {
        if (is_array($column)) {
            $column = array_values($column);
            if (!$column || count($column) > 16 || count(array_unique($column)) !== count($column)) {
                throw new \InvalidArgumentException('Cascader needs 1–16 distinct columns.');
            }
        }
        if (is_array($column)) {
            $this->column = array_map(fn ($name) => $this->formatColumn($name), $column);
            $this->label = $this->formatLabel($arguments);
            $this->id = $this->formatId($column);
        } else {
            parent::__construct($column, $arguments);
        }
    }

    public function getValidator(array $input)
    {
        if (!$this->isWritable()) {
            return false;
        }
        if ($this->validator) {
            return parent::getValidator($input);
        }
        $columns = (array) $this->column;
        $key = reset($columns);
        $rules = [];
        foreach ($columns as $column) {
            $rules[$column] = $this->getRules() ?: ['nullable'];
        }
        $validator = validator($input, $rules, $this->getValidationMessages(), array_fill_keys($columns, $this->label));
        $validator->after(function ($validator) use ($input, $columns, $key) {
            if ($validator->errors()->isNotEmpty() || !array_filter($columns, fn ($column) => Arr::has($input, $column))) {
                return;
            }
            try {
                // Partial submissions must never combine new ancestors with stored descendants.
                if (is_array($this->column) && !Arr::has($input, $columns)) {
                    throw new \InvalidArgumentException('Incomplete cascader submission.');
                }
                $value = is_array($this->column)
                    ? array_map(fn ($column) => Arr::get($input, $column), $columns) : Arr::get($input, $key);
                if (!is_array($this->column) && is_array($value)) {
                    throw new \InvalidArgumentException('Expected a single terminal ID.');
                }
                $this->cascaderSource()->validate($value, !$this->selectBranches, is_array($this->column) ? count($columns) : 0);
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add($key, trans('admin::cascader.invalid'));
            } catch (\Throwable $exception) {
                report($exception);
                $validator->errors()->add($key, trans('admin::cascader.failed'));
            }
        });

        return $validator;
    }

    public function prepare($value)
    {
        return is_array($value) ? array_map(fn ($id) => $id === '' ? null : $id, $value) : ($value === '' ? null : $value);
    }

    public function render()
    {
        $names = (array) ($this->elementName ?: $this->formatName($this->column));
        $values = [];
        foreach ($names as $index => $name) {
            $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
            $value = is_array($this->column) ? ($this->value()[$index] ?? null) : $this->value();
            $values[] = old($key, $value);
        }
        $this->addVariables([
            'cascaderNames' => array_values($names), 'cascaderValues' => $values,
            'cascaderConfig' => $this->cascaderConfig(is_array($this->column), is_array($this->column) ? count($names) : 0, !$this->isWritable()),
            'cascaderDisabled' => array_key_exists('disabled', $this->attributes),
        ]);
        $this->script = '$.admin.cascader.init(document);';

        return parent::render();
    }
}
