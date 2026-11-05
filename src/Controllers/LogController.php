<?php

namespace Encore\Admin\Controllers;

use Encore\Admin\Auth\Database\OperationLog;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Widgets\OperationLogInput;
use Illuminate\Support\Arr;

class LogController extends AdminController
{
    /**
     * {@inheritdoc}
     */
    protected function title()
    {
        return trans('admin.operation_log');
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new OperationLog());

        $grid->model()->with('user')->orderBy('id', 'DESC');
        $grid->paginate(20);
        $grid->perPages([20, 50, 100]);

        $grid->column('id', 'ID')->sortable();
        $grid->column('user.name', trans('admin.operation_log_user'));
        $grid->column('method', trans('admin.http.method'))->display(function ($method) {
            $color = Arr::get(OperationLog::$methodColors, $method, 'grey');

            return '<span class="badge bg-'.e($color).'">'.e($method).'</span>';
        });
        $grid->column('path', trans('admin.http.path'))->display(function ($path) {
            return '<span title="'.e($path).'" style="display:block;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'.e($path).'</span>';
        });
        $grid->column('ip')->label('primary');
        $grid->column('_result', trans('admin.operation_log_result'))->display(function () {
            return e(OperationLogInput::result($this->input));
        });
        $grid->column('input', trans('admin.operation_log_input'))->display(function ($input) {
            // display() output is rendered as html, so the request parameters must be escaped.
            return e(OperationLogInput::preview($input));
        })->modal(trans('admin.operation_log_input'), OperationLogInput::class)->width(300);

        $grid->column('created_at', trans('admin.created_at'))->sortable()->style('white-space:nowrap');

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();
            $actions->disableView();
        });

        $grid->disableCreateButton();

        $grid->filter(function (Grid\Filter $filter) {
            $userModel = config('admin.database.users_model');

            $filter->equal('user_id', trans('admin.operation_log_user'))->select($userModel::query()->orderBy('name')->pluck('name', 'id'));
            $filter->equal('method', trans('admin.http.method'))->select(array_combine(OperationLog::$methods, OperationLog::$methods));
            $filter->like('path', trans('admin.http.path'));
            $filter->equal('ip');
            $filter->between('created_at', trans('admin.created_at'))->datetime();
        });

        return $grid;
    }

    protected function form()
    {
        return new Form(new OperationLog());
    }
}
