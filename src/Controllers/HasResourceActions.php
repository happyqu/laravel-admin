<?php

namespace Encore\Admin\Controllers;

trait HasResourceActions
{
    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        return $this->form()->update($id);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return mixed
     */
    public function store()
    {
        return $this->form()->store();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (request()->has('_action_token')) {
            $contexts = app(\Encore\Admin\Actions\Context::class);

            return $contexts->execute(request(), function () use ($contexts, $id) {
                [$action, $model] = $contexts->resolve(request());
                abort_unless($action instanceof \Encore\Admin\Grid\Actions\Delete
                    && $model instanceof \Illuminate\Database\Eloquent\Model
                    && (string) $model->getKey() === (string) $id
                    && parse_url($action->resourceUrl($model), PHP_URL_PATH) === request()->getPathInfo(), 403);

                $form = $this->form();
                abort_unless(get_class($form->model()) === get_class($model)
                    && $form->model()->getConnection() === $model->getConnection(), 403);
                $response = $form->destroy($id);

                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $data = $response->getData(true);
                    if (($data['status'] ?? null) === true) {
                        return $action->response()->success($data['message'] ?? '')->refresh()->send();
                    }
                    if (($data['status'] ?? null) === false) {
                        return $action->response()->error($data['message'] ?? '')->send();
                    }
                }

                return $response;
            });
        }

        return $this->form()->destroy($id);
    }
}
