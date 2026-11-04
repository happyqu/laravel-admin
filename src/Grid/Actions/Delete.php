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

    protected function usesResourceEndpoint(): bool
    {
        return $this->resourcePath
            && (new \ReflectionMethod($this, 'handle'))->getDeclaringClass()->getName() === self::class;
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
        // Old open pages must refresh; deleting here would bypass the resource controller.
        return $this->response()->error(trans('admin.action_context_invalid'));
    }

    /**
     * @return void
     */
    public function dialog()
    {
        $this->question(trans('admin.delete_confirm'), '', ['confirmButtonColor' => '#d33']);
    }
}
