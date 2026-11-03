<?php

namespace Encore\Admin\Actions;

use Illuminate\Http\Request;

class CallbackAction extends Action
{
    use HandlesCallback;

    protected $model;

    public function setModel($model)
    {
        $this->model = $model;

        return $this;
    }

    public function retrieveModel(Request $request)
    {
        return $this->contextModel();
    }

    public function setResolvedModel($model): void
    {
        parent::setResolvedModel($model);
        $this->model = $model;
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function handle(Request $request)
    {
        return $this->executeCallback($this->retrieveModel($request), $request);
    }
}
