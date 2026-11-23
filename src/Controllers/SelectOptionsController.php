<?php

namespace Encore\Admin\Controllers;

use Encore\Admin\Security\ComponentSubmission;
use Encore\Admin\Select\ModelOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SelectOptionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $input = validator($request->query(), [
            'parent' => 'required|string|max:255',
        ])->validate();

        $ticket = ComponentSubmission::verifyIssued($request, ModelOptions::KIND, ModelOptions::class);
        abort_unless(isset($ticket[1]), 419, 'Expired component ticket.');
        [$settings, $allowedParents] = $ticket;
        abort_unless(in_array($input['parent'], $allowedParents, true), 403);
        $source = new ModelOptions(...$settings);

        return response()->json($source->options($input['parent']));
    }
}
