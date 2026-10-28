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
    use \Encore\Admin\Support\Authorization;

    public function passesAuthorizationForRendering(): bool
    {
        if ($this instanceof BatchAction) {
            // The eventual selection is not known yet; do not authorize unrelated visible rows.
            $user = Admin::user();
            return $user && $this->passesConfiguredAuthorization($user, null, true);
        }
        return $this->passesAuthorization($this instanceof RowAction ? $this->getRow() : $this->contextModel());
    }

    protected function renderAuthorizationDenied(): string
    {
        return $this->authorizationDeniedHtml($this->name());
    }

    /**
     * @param Model $model
     *
     * @return bool
     */
    public function passesAuthorization($model = null)
    {
        $user = Admin::user();
        if (!$user || !$this->passesConfiguredAuthorization($user, $model)) {
            return false;
        }

        if (method_exists($this, 'authorize')) {
            if ($this->authorize($user, $model) != true) {
                return false;
            }
        }

        if ($model instanceof Collection) {
            foreach ($model as $record) {
                if ($record && method_exists($record, 'actionAuthorize')
                    && $record->actionAuthorize($user, get_called_class()) != true) {
                    return false;
                }
            }
            return true;
        }

        if ($model && method_exists($model, 'actionAuthorize')) {
            return $model->actionAuthorize($user, get_called_class()) == true;
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
