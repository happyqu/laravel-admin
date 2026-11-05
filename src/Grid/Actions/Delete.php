<?php

namespace Encore\Admin\Grid\Actions;

use Encore\Admin\Actions\Response;
use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;

class Delete extends RowAction
{
    use HasResourceAuthorization;

    public function authorize($user, Model $model)
    {
        return $this->allowsResourceOperation($model, 'DELETE');
    }

    public function getHandleRoute()
    {
        return $this->usesResourceEndpoint() ? $this->resourceUrl($this->getRow()) : parent::getHandleRoute();
    }

    protected function addScript()
    {
        parent::addScript();
        if ($this->usesResourceEndpoint()) {
            $this->attribute('data-admin-method', 'DELETE');
        }
    }

    /**
     * Whether the built-in deletion flow is still in use (a subclass may replace handle()).
     */
    public function usesResourceEndpoint(): bool
    {
        return $this->deletesThroughResource() && $this->resourceRouteExists($this->getRow(), 'DELETE');
    }

    /** Whether handle() is the built-in one that delegates to the resource controller. */
    public function deletesThroughResource(): bool
    {
        return (new \ReflectionMethod($this, 'handle'))->getDeclaringClass()->getName() === self::class;
    }

    /**
     * @return array|null|string
     */
    public function name()
    {
        return __('admin.delete');
    }

    /**
     * @param Model $model
     *
     * @return Response
     */
    public function handle(Model $model)
    {
        if ($this->usesResourceEndpoint()) {
            // Pages rendered against the resource route must refresh: deleting here would
            // bypass the resource controller and its deletion hooks.
            return $this->response()->error(trans('admin.action_context_invalid'));
        }

        $trans = [
            'failed'    => trans('admin.delete_failed'),
            'succeeded' => trans('admin.delete_succeeded'),
        ];

        try {
            $connection = $model->getConnection();

            $connection->transaction(function () use ($model) {
                if ($model->delete() === false) {
                    throw new \RuntimeException('Model deletion was cancelled.');
                }
            });
        } catch (\Throwable $exception) {
            $this->reportFailure($exception);

            return $this->response()->error(
                config('app.debug') ? "{$trans['failed']} : {$exception->getMessage()}" : $trans['failed']
            );
        }

        return $this->response()->success($trans['succeeded'])->refresh();
    }

    /** Report without requiring an exception handler (for example in minimal containers). */
    protected function reportFailure(\Throwable $exception): void
    {
        try {
            if (app()->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)) {
                report($exception);
            }
        } catch (\Throwable $ignored) {
            // The action still returns its error response.
        }
    }

    /**
     * @return void
     */
    public function dialog()
    {
        $this->question(trans('admin.delete_confirm'), '', ['confirmButtonColor' => '#d33']);
    }
}
