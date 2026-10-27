<?php

namespace Encore\Admin\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\InputBag;

class SubmissionInput
{
    /** Allow only writable fields, including the definitions inside nested editors. */
    public static function onlyFields(array $values, iterable $fields): array
    {
        $allowed = [];
        foreach ($fields as $field) {
            if (!$field->isWritable()) {
                continue;
            }
            foreach ((array) $field->column() as $column) {
                if (!is_string($column) || in_array($column, ['_form_', '_form_token_', '_token', '_method'], true)
                    || !\Illuminate\Support\Arr::has($values, $column)) {
                    continue;
                }
                $value = \Illuminate\Support\Arr::get($values, $column);
                if ($field instanceof Field\HasMany || $field instanceof Field\Embeds) {
                    $value = $field->sanitize($value);
                }
                \Illuminate\Support\Arr::set($allowed, $column, $value);
            }
        }

        return $allowed;
    }

    /** Copy instead of retaining Laravel's cached converted files or original JSON bag. */
    public static function replace(Request $request, array $values, array $files): Request
    {
        $clean = Request::createFrom($request);
        $clean->query->replace([]);
        $clean->setJson(new InputBag($values));
        $clean->replace($values);
        $clean->files->replace($files);
        app()->instance('request', $clean);
        Facade::clearResolvedInstance('request');
        return $clean;
    }
}
