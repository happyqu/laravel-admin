<?php

namespace Encore\Admin\Actions;

use Illuminate\Http\Request;

class CallbackRowAction extends RowAction
{
    use HandlesCallback;

    /**
     * @param mixed   $model
     * @param Request $request
     *
     * @return Response
     */
    public function handle($model, Request $request)
    {
        return $this->executeCallback($model, $request);
    }
}
