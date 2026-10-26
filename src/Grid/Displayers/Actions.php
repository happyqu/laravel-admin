<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;

class Actions extends AbstractDisplayer
{
    /**
     * @var array
     */
    protected $appends = [];

    /**
     * @var array
     */
    protected $prepends = [];

    /**
     * Default actions.
     *
     * @var array
     */
    protected $actions = ['view', 'edit', 'delete'];

    /**
     * @var string
     */
    protected $resource;

    /**
     * Disable all actions.
     *
     * @var bool
     */
    protected $disableAll = false;

    /**
     * diy translate.
     *
     * @var array
     */
    protected $trans = [];

    /**
     * Append a action.
     *
     * @param $action
     *
     * @return $this
     */
    public function append($action)
    {
        array_push($this->appends, $action);

        return $this;
    }

    /**
     * Prepend a action.
     *
     * @param $action
     *
     * @return $this
     */
    public function prepend($action)
    {
        array_unshift($this->prepends, $action);

        return $this;
    }

    /**
     * Get route key name of current row.
     *
     * @return mixed
     */
    public function getRouteKey()
    {
        return $this->row->{$this->row->getRouteKeyName()};
    }

    /**
     * Disable view action.
     *
     * @return $this
     */
    public function disableView(bool $disable = true)
    {
        if ($disable) {
            array_delete($this->actions, 'view');
        } elseif (!in_array('view', $this->actions)) {
            array_push($this->actions, 'view');
        }

        return $this;
    }

    /**
     * Disable delete.
     *
     * @return $this.
     */
    public function disableDelete(bool $disable = true)
    {
        if ($disable) {
            array_delete($this->actions, 'delete');
        } elseif (!in_array('delete', $this->actions)) {
            array_push($this->actions, 'delete');
        }

        return $this;
    }

    /**
     * Disable edit.
     *
     * @return $this.
     */
    public function disableEdit(bool $disable = true)
    {
        if ($disable) {
            array_delete($this->actions, 'edit');
        } elseif (!in_array('edit', $this->actions)) {
            array_push($this->actions, 'edit');
        }

        return $this;
    }

    /**
     * Disable all actions.
     *
     * @return $this
     */
    public function disableAll()
    {
        $this->disableAll = true;

        return $this;
    }

    /**
     * Set resource of current resource.
     *
     * @param $resource
     *
     * @return $this
     */
    public function setResource($resource)
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Get resource of current resource.
     *
     * @return string
     */
    public function getResource()
    {
        return $this->resource ?: parent::getResource();
    }

    /**
     * {@inheritdoc}
     */
    public function display($callback = null)
    {
        if ($callback instanceof \Closure) {
            $callback->call($this, $this);
        }

        if ($this->disableAll) {
            return '';
        }

        $actions = $this->prepends;

        foreach ($this->actions as $action) {
            $method = 'render'.ucfirst($action);
            array_push($actions, $this->{$method}());
        }

        $actions = array_merge($actions, $this->appends);

        return implode('', $actions);
    }

    /**
     * Render view action.
     *
     * @return string
     */
    protected function renderView()
    {
        return '<a href="'.e($this->getResource()).'/'.e($this->getRouteKey()).'" class="'.e($this->grid->getGridRowName()).'-view">'
            .'<i class="fa fa-eye"></i></a>';
    }

    /**
     * Render edit action.
     *
     * @return string
     */
    protected function renderEdit()
    {
        return '<a href="'.e($this->getResource()).'/'.e($this->getRouteKey()).'/edit" class="'.e($this->grid->getGridRowName()).'-edit">'
            .'<i class="fa fa-edit"></i></a>';
    }

    /**
     * Render delete action.
     *
     * @return string
     */
    protected function renderDelete()
    {
        $this->setupDeleteScript();

        return '<a href="javascript:void(0);" data-id="'.e($this->getKey()).'" class="'.e($this->grid->getGridRowName()).'-delete">'
            .'<i class="fa fa-trash"></i></a>';
    }

    protected function setupDeleteScript()
    {
        $trans = array_merge([
            'delete_confirm' => trans('admin.delete_confirm'),
            'confirm'        => trans('admin.confirm'),
            'cancel'         => trans('admin.cancel'),
        ], $this->trans);

        $js = function ($value) {
            return json_encode((string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        };

        $script = <<<SCRIPT

$('.{$this->grid->getGridRowName()}-delete').unbind('click').click(function() {

    var id = $(this).data('id');
    var deleting = false;

    swal({
        title: {$js($trans['delete_confirm'])},
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#DD6B55",
        confirmButtonText: {$js($trans['confirm'])},
        showLoaderOnConfirm: true,
        cancelButtonText: {$js($trans['cancel'])},
        preConfirm: function() {
            return new Promise(function(resolve) {
                deleting = true;
                $.ajax({
                    method: 'post',
                    url: '{$this->getResource()}/' + id,
                    data: {
                        _method:'delete',
                        _token:LA.token,
                    },
                    success: function (data) {
                        if ($.admin.isResponseError(data)) {
                            deleting = false;
                            resolve();
                            swal($.admin.responseMessage(data) || {$js(trans('admin.delete_failed'))}, '', 'error');
                            return;
                        }
                        resolve(data);
                        $.admin.notifyThenNavigate($.admin.responseMessage(data) || {$js(trans('admin.delete_succeeded'))}, 'success');
                    },
                    error: function (xhr) {
                        deleting = false;
                        resolve();
                        var message = ($.admin.responseMessage(xhr.responseJSON))
                            ? $.admin.responseMessage(xhr.responseJSON)
                            : {$js(trans('admin.request_failed'))};
                        if (xhr.status === 401) {
                            message = {$js(trans('admin.authentication_expired'))};
                        }
                        swal(message, '', 'error');
                    }
                });
            });
        },
        allowOutsideClick: function () { return !deleting; },
        allowEscapeKey: function () { return !deleting; }
    });
});

SCRIPT;

        Admin::script($script);
    }

    /**
     * diy delete translate.
     *
     * @param $tans
     */
    public function setTrans($tans)
    {
        $this->trans = $tans;
    }
}
