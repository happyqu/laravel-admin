<?php

namespace Encore\Admin\Form;

use Illuminate\Validation\Validator;

class SubmissionValidator
{
    /** Batch ordinary rules; custom validators and field-specific input transformations stay isolated. */
    public static function failed(iterable $fields, array $input, ?callable $include = null, ?array $context = null): array
    {
        $fields = collect($fields)->all();
        $context ??= Cascade::context($fields, $input);
        // Conditional validation rules must see the same trusted controls as when().
        $input = Cascade::filterInput($fields, Cascade::mergeContext($input, $context), $context);
        $failed = $rules = $attributes = [];
        $flush = static function () use (&$failed, &$rules, &$attributes, $input) {
            if ($rules) {
                $validator = validator($input, $rules, [], $attributes);
                if (!$validator->passes()) {
                    $failed[] = $validator;
                }
                $rules = $attributes = [];
            }
        };
        foreach ($fields as $field) {
            if (!$field->isWritable() || !$field->isCascadeActive($context) || ($include && !$include($field))) {
                continue;
            }
            $definition = $field->batchValidationDefinition();
            if ($definition !== null) {
                [$column, $fieldRules, $label] = $definition;
                if (array_key_exists($column, $rules)) {
                    $flush();
                }
                if ($fieldRules) {
                    $rules[$column] = $fieldRules;
                    $attributes[$column] = $label;
                }
                continue;
            }
            $flush();
            $validator = $field->getValidator($input);
            if ($validator instanceof Validator && !$validator->passes()) {
                $failed[] = $validator;
            }
        }
        $flush();
        return $failed;
    }
}
