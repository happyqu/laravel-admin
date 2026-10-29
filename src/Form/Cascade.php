<?php

namespace Encore\Admin\Form;

use Illuminate\Support\Arr;

/** Shared server-side semantics for conditional fields. */
class Cascade
{
    /** Fill only omitted partial controls and non-writable controls from trusted definitions/data. */
    public static function context(iterable $fields, array $input, array $stored = [], bool $partial = false): array
    {
        $fields = collect($fields)->all();
        $submitted = $input;
        $restored = [];
        foreach ($fields as $field) {
            $control = method_exists($field, 'hasCascadeConditions') && $field->hasCascadeConditions();
            if ($field->isWritable() && !($control && $partial && !Arr::has($input, $field->column()))) {
                continue;
            }
            $columns = $field->column();
            $value = self::storedValue($field, $stored);
            foreach ((array) $columns as $key => $column) {
                if (!is_string($column) || $column === '') {
                    continue;
                }
                Arr::set($input, $column, is_array($columns) ? ($value[$key] ?? null) : $value);
                $restored[$column] = true;
            }
        }
        // An active writable definition may share a column with a protected one.
        foreach ($fields as $field) {
            if (!$field->isWritable() || !$field->isCascadeActive($input)) {
                continue;
            }
            foreach ((array) $field->column() as $column) {
                if (!isset($restored[$column])) {
                    continue;
                }
                if (Arr::has($submitted, $column)) {
                    Arr::set($input, $column, Arr::get($submitted, $column));
                } elseif (!(method_exists($field, 'hasCascadeConditions') && $field->hasCascadeConditions() && $partial)) {
                    Arr::forget($input, $column);
                }
            }
        }

        return $input;
    }

    protected static function storedValue(Field $field, array $stored)
    {
        if (method_exists($field, 'cascadeStoredValue')) {
            return $field->cascadeStoredValue($stored);
        }
        if (Arr::has($stored, $field->column())) {
            $copy = clone $field;
            $copy->setOriginal($stored);
            return $copy->original();
        }
        return $field->original() ?? $field->value();
    }

    /** Merge object-shaped partial values, while replacing lists and explicit empty arrays. */
    public static function mergeContext(array $base, array $values): array
    {
        foreach ($values as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key])
                ? self::mergeContext($base[$key], $value) : $value;
        }

        return $base;
    }

    public static function matches(string $operator, $actual, $expected): bool
    {
        $normalize = static fn ($value) => is_scalar($value) ? (string) $value : $value;
        $actual = is_array($actual) ? array_map($normalize, $actual) : $normalize($actual);
        if (is_array($actual) && is_array($expected) && in_array($operator, ['=', '!='], true)) {
            sort($actual);
            sort($expected);
        }

        switch ($operator) {
            case '=': return $actual == $expected;
            case '!=': return $actual != $expected;
            case '>': return $actual > $expected;
            case '<': return $actual < $expected;
            case '>=': return $actual >= $expected;
            case '<=': return $actual <= $expected;
            case 'in': return in_array($actual, $expected);
            case 'notIn': return !in_array($actual, $expected);
            case 'has': return in_array($expected, Arr::wrap($actual));
            case 'oneIn': return count(array_intersect(Arr::wrap($expected), Arr::wrap($actual))) > 0;
            case 'oneNotIn': return count(array_intersect(Arr::wrap($expected), Arr::wrap($actual))) === 0;
        }

        throw new \InvalidArgumentException("Operator [$operator] not supported.");
    }

    /** Keep values shared with an active definition, including dotted columns. */
    public static function filterInput(iterable $fields, array $input, ?array $context = null): array
    {
        $context ??= $input;
        $active = $inactive = [];
        foreach ($fields as $field) {
            $matches = $field->isCascadeActive($context);
            foreach ((array) $field->column() as $column) {
                if (!is_string($column) || $column === '') {
                    continue;
                }
                if ($matches && $field->isWritable()) {
                    $active[$column] = true;
                } elseif (!$matches) {
                    $inactive[$column] = true;
                }
            }
        }
        $original = $input;
        foreach (array_diff_key($inactive, $active) as $column => $_) {
            Arr::forget($input, $column);
            // An active child can share a container with an inactive parent field.
            foreach ($active as $child => $_) {
                if (str_starts_with($child, $column.'.') && Arr::has($original, $child)) {
                    Arr::set($input, $child, Arr::get($original, $child));
                }
            }
        }

        return $input;
    }
}
