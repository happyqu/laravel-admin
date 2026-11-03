<?php

namespace Encore\Admin\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class CallbackBatchAction extends BatchAction
{
    use HandlesCallback;

    /**
     * @param Collection $collection
     * @param Request    $request
     *
     * @return Response
     */
    public function handle(Collection $collection, Request $request = null)
    {
        // Fall back to the current request for hosts that call handle() directly.
        return $this->executeCallback($collection, $request ?? request());
    }
}
