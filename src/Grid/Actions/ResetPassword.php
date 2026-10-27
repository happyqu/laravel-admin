<?php

namespace Encore\Admin\Grid\Actions;

use Encore\Admin\Actions\Response;
use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Standalone "reset password" entry for grid rows.
 *
 * The user edit form hides the password fields while editing; this action
 * issues a fresh execution ticket on confirmation and updates only the
 * password, so administrators no longer have to retype it to save a profile.
 */
class ResetPassword extends RowAction
{
    /**
     * @return array|null|string
     */
    public function name()
    {
        return __('admin.reset_password');
    }

    /**
     * @return void
     */
    public function form()
    {
        $rule = new \Encore\Admin\Rules\Password();

        $this->password('password', trans('admin.password'))
            ->attribute('autocomplete', 'new-password')
            ->attribute($rule->htmlConstraints())
            ->required()
            ->rules(['required', $rule->rule(), 'confirmed'])
            ->help($rule->message());
        $this->password('password_confirmation', trans('admin.password_confirmation'))
            ->attribute('autocomplete', 'new-password')
            ->required()
            ->rules('required');
    }

    /**
     * @param Model $model
     *
     * @return Response
     */
    public function handle(Model $model)
    {
        $this->applyPassword($model, (string) request('password'));

        return $this->response()->success(trans('admin.update_succeeded'))->refresh();
    }

    /**
     * Persist the hashed password. Hosts may override this to maintain extra
     * columns such as a password-changed timestamp.
     *
     * @param Model  $model
     * @param string $password
     *
     * @return void
     */
    protected function applyPassword(Model $model, $password)
    {
        $model->password = Hash::make($password);
        $model->save();
    }
}
