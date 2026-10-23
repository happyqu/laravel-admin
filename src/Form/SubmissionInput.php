<?php

namespace Encore\Admin\Form;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\InputBag;

class SubmissionInput
{
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
