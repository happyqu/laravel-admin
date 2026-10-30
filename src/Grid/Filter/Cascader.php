<?php

namespace Encore\Admin\Grid\Filter;

use Encore\Admin\Cascader\Component;
use Encore\Admin\Cascader\HasSource;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class Cascader extends AbstractFilter
{
    use HasSource;

    protected $view = 'admin::filter.cascader';
    protected $columns;

    public function __construct($columns, $label = '')
    {
        $this->columns = is_array($columns) ? array_values($columns) : $columns;
        if (is_array($columns) && (!$columns || count($columns) > 16 || count(array_unique($columns)) !== count($columns))) {
            throw new \InvalidArgumentException('Cascader needs 1–16 distinct columns.');
        }
        parent::__construct(is_array($columns) ? reset($columns) : $columns, $label);
        $this->selectBranches = is_array($columns);
    }

    public function getColumn()
    {
        $prefix = $this->parent->getName();
        $names = array_map(static fn ($column) => ($prefix ? $prefix.'_' : '').$column, (array) $this->columns);

        return is_array($this->columns) ? $names : $names[0];
    }

    public function getOriginalColumn()
    {
        return $this->columns;
    }

    private function inputValues(array $inputs, bool $prefixed = true): array
    {
        return array_map(function ($column) use ($inputs, $prefixed) {
            $prefix = $prefixed ? $this->parent->getName() : '';

            return Arr::get($inputs, ($prefix ? $prefix.'_' : '').$column);
        }, (array) $this->columns);
    }

    public function condition($inputs)
    {
        if ($this->ignore) {
            return;
        }
        $values = $this->inputValues($inputs, false);
        if (!array_filter($values, static fn ($id) => $id !== null && $id !== '')) {
            return;
        }
        try {
            if (!is_array($this->columns) && is_array($values[0])) {
                throw new \InvalidArgumentException('Expected a single terminal ID.');
            }
            if (!is_array($this->columns) && $this->selectBranches) {
                throw new \InvalidArgumentException('Branch filtering requires multiple columns.');
            }
            $nodes = $this->cascaderSource()->validate(is_array($this->columns) ? $values : $values[0],
                !$this->selectBranches, is_array($this->columns) ? count($values) : 0);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([(array_values((array) $this->getColumn()))[0] => trans('admin::cascader.invalid')]);
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([(array_values((array) $this->getColumn()))[0] => trans('admin::cascader.failed')]);
        }
        $conditions = [];
        $ids = array_column($nodes, 'value');
        foreach ((array) $this->columns as $index => $column) {
            $id = is_array($this->columns) ? ($ids[$index] ?? null) : end($ids);
            if ($id !== null) {
                $conditions[] = [$column, '=', $id];
            }
        }

        return ['where' => [function ($query) use ($conditions) {
            $relations = [];
            foreach ($conditions as [$column, $operator, $value]) {
                $separator = strrpos($column, '.');
                if ($separator === false) {
                    $query->where($column, $operator, $value);
                } else {
                    $relations[substr($column, 0, $separator)][] = [substr($column, $separator + 1), $operator, $value];
                }
            }
            // Keep a complete cascade on the same related record, including hasMany relations.
            foreach ($relations as $relation => $constraints) {
                $query->whereHas($relation, function ($related) use ($constraints) {
                    foreach ($constraints as [$column, $operator, $value]) {
                        $related->where($column, $operator, $value);
                    }
                });
            }
        }]];
    }

    public function render()
    {
        Component::assets();
        $columns = (array) $this->columns;

        return view($this->view, [
            'label' => $this->label,
            'cascaderNames' => array_map(fn ($column) => $this->formatName($column), $columns),
            'cascaderValues' => $this->inputValues(request()->query()),
            'cascaderConfig' => $this->cascaderConfig(is_array($this->columns), is_array($this->columns) ? count($columns) : 0),
            'cascaderDisabled' => false,
        ]);
    }
}
