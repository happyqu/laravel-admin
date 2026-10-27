<?php

namespace Encore\Admin\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Rules;
use Encore\Admin\Show;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends AdminController
{
    /**
     * {@inheritdoc}
     */
    protected function title()
    {
        return trans('admin.administrator');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $userModel = config('admin.database.users_model');

        $grid = new Grid(new $userModel());

        $grid->column('id', 'ID')->sortable();
        $grid->column('username', trans('admin.username'));
        $grid->column('name', trans('admin.name'));
        $grid->column('roles', trans('admin.roles'))->pluck('name')->label();
        $grid->column('created_at', trans('admin.created_at'));
        $grid->column('updated_at', trans('admin.updated_at'));

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            if ($actions->getKey() == 1) {
                $actions->disableDelete();
            }

            // Editing a user no longer asks for the password; this is the
            // dedicated entry for changing it.
            $actions->add(new Grid\Actions\ResetPassword());
        });

        $grid->tools(function (Grid\Tools $tools) {
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });
        });

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        $userModel = config('admin.database.users_model');

        $show = new Show($userModel::findOrFail($id));

        $show->field('id', 'ID');
        $show->field('username', trans('admin.username'));
        $show->field('name', trans('admin.name'));
        $show->field('roles', trans('admin.roles'))->as(function ($roles) {
            return $roles->pluck('name');
        })->label();
        $show->field('permissions', trans('admin.permissions'))->as(function ($permission) {
            return $permission->pluck('name');
        })->label();
        $show->field('created_at', trans('admin.created_at'));
        $show->field('updated_at', trans('admin.updated_at'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    public function form()
    {
        $userModel = config('admin.database.users_model');
        $permissionModel = config('admin.database.permissions_model');
        $roleModel = config('admin.database.roles_model');

        $form = new Form(new $userModel());

        $form->display('id', 'ID');
        $form->text('username', trans('admin.username'))
            ->creationRules(['required', 'string', Rule::unique($userModel, 'username')])
            ->updateRules(fn (Form $form) => ['required', 'string', Rule::unique($userModel, 'username')->ignore($form->model())]);

        $form->text('name', trans('admin.name'))->rules('required');
        $form->image('avatar', trans('admin.avatar'));

        // Editing a user does not ask for the password; use the row-level
        // "reset password" action instead.
        if (! $form->isEditing()) {
            $rule = new Rules\Password();
            $form->password('password', trans('admin.password'))
                ->customFormat(static fn () => '')
                ->attribute($rule->htmlConstraints())
                ->rules(['required', 'string', $rule->rule(), 'confirmed'])
                ->help($rule->message());
            $form->password('password_confirmation', trans('admin.password_confirmation'))
                ->customFormat(static fn () => '')
                ->rules('required');
        }

        $form->ignore(['password_confirmation']);

        $form->multipleSelect('roles', trans('admin.roles'))->options($roleModel::all()->pluck('name', 'id'));
        $form->multipleSelect('permissions', trans('admin.permissions'))->options($permissionModel::all()->pluck('name', 'id'));

        $form->display('created_at', trans('admin.created_at'));
        $form->display('updated_at', trans('admin.updated_at'));

        $form->saving(function (Form $form) {
            if ($form->password !== null && $form->password !== '') {
                $form->password = Hash::make($form->password);
            } else {
                $form->password = $form->model()->password;
            }
        });

        return $form;
    }

    public function destroy($id)
    {
        $model = config('admin.database.users_model');
        abort_if($model::query()->whereKey(explode(',', (string) $id))->whereKey(1)->exists(), 403);

        return parent::destroy($id);
    }
}
