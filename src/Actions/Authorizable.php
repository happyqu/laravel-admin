<?php

namespace Encore\Admin\Actions;

use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Action
 */
trait Authorizable
{
    /**
     * @param Model $model
     *
     * @return bool
     */
    public function passesAuthorization($model = null)
    {
        if (method_exists($this, 'authorize')) {
            if ($this->authorize(Admin::user(), $model) != true) {
                return false;
            }
        }

        if ($model instanceof Collection) {
            foreach ($model as $record) {
                if ($record && method_exists($record, 'actionAuthorize')
                    && $record->actionAuthorize(Admin::user(), get_called_class()) != true) {
                    return false;
                }
            }
            return true;
        }

        if ($model && method_exists($model, 'actionAuthorize')) {
            return $model->actionAuthorize(Admin::user(), get_called_class()) == true;
        }

        return true;
    }

    /**
     * @return mixed
     */
    public function failedAuthorization()
    {
        return $this->response()->error(__('admin.deny'))->send();
    }
}
