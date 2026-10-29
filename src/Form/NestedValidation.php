<?php

namespace Encore\Admin\Form;

use Illuminate\Validation\Validator;

/** Keep each nested field's validation and input normalization intact. */
class NestedValidation
{
    public static function append(Validator $validator, iterable $fields, array $input, string $prefix, array $original = []): void
    {
        $validator->after(static function (Validator $validator) use ($fields, $input, $prefix, $original) {
            foreach ($fields as $field) {
                $field->setOriginal($original);
            }

            $context = Cascade::context($fields, $input, $original);
            foreach (SubmissionValidator::failed($fields, $input, null, $context) as $failed) {
                foreach ($failed->errors()->messages() as $column => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($prefix.'.'.$column, $message);
                    }
                }
            }
        });
    }
}
