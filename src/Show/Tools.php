<?php

namespace Encore\Admin\Show;

use Encore\Admin\Admin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;

class Tools implements Renderable
{
    /**
     * The panel that holds this tool.
     *
     * @var Panel
     */
    protected $panel;

    /**
     * @var string
     */
    protected $resource;

    /**
     * Default tools.
     *
     * @var array
     */
    protected $tools = ['delete', 'edit', 'list'];

    /**
     * Tools should be appends to default tools.
     *
     * @var Collection
     */
    protected $appends;

    /**
     * Tools should be prepends to default tools.
     *
     * @var Collection
     */
    protected $prepends;

    /**
     * Tools constructor.
     *
     * @param Panel $panel
     */
    public function __construct(Panel $panel)
    {
        $this->panel = $panel;

        $this->appends = new Collection();
        $this->prepends = new Collection();
    }

    /**
     * Append a tools.
     *
     * @param mixed $tool
     *
     * @return $this
     */
    public function append($tool)
    {
        $this->appends->push($tool);

        return $this;
    }

    /**
     * Prepend a tool.
     *
     * @param mixed $tool
     *
     * @return $this
     */
    public function prepend($tool)
    {
        $this->prepends->push($tool);

        return $this;
    }

    /**
     * Get resource path.
     *
     * @return string
     */
    public function getResource()
    {
        if (is_null($this->resource)) {
            $this->resource = $this->panel->getParent()->getResourcePath();
        }

        return $this->resource;
    }

    /**
     * Disable `list` tool.
     *
     * @return $this
     */
    public function disableList(bool $disable = true)
    {
        if ($disable) {
            array_delete($this->tools, 'list');
        } elseif (!in_array('list', $this->tools)) {
            array_push($this->tools, 'list');
        }

        return $this;
    }

    /**
     * Disable `delete` tool.
     *
     * @return $this
     */
    public function disableDelete(bool $disable = true)
    {
        if ($disable) {
            array_delete($this->tools, 'delete');
        } elseif (!in_array('delete', $this->tools)) {
            array_push($this->tools, 'delete');
        }

        return $this;
    }

    /**
     * Disable `edit` tool.
     *
     * @return $this
     */
    public function disableEdit(bool $disable = true)
    {
        if ($disable) {
            array_delete($this->tools, 'edit');
        } elseif (!in_array('edit', $this->tools)) {
            array_push($this->tools, 'edit');
        }

        return $this;
    }

    /**
     * Get request path for resource list.
     *
     * @return string
     */
    protected function getListPath()
    {
        return ltrim($this->getResource(), '/');
    }

    /**
     * Get request path for edit.
     *
     * @return string
     */
    protected function getEditPath()
    {
        $key = $this->panel->getParent()->getModel()->getKey();

        return $this->getListPath().'/'.$key.'/edit';
    }

    /**
     * Get request path for delete.
     *
     * @return string
     */
    protected function getDeletePath()
    {
        $key = $this->panel->getParent()->getModel()->getKey();

        return $this->getListPath().'/'.$key;
    }

    /**
     * Render `list` tool.
     *
     * @return string
     */
    protected function renderList()
    {
        $list = trans('admin.list');

        return <<<HTML
<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="{$this->getListPath()}" class="btn btn-sm btn-default" title="{$list}">
        <i class="fa fa-list"></i><span class="hidden-xs"> {$list}</span>
    </a>
</div>
HTML;
    }

    /**
     * Render `edit` tool.
     *
     * @return string
     */
    protected function renderEdit()
    {
        $edit = trans('admin.edit');

        return <<<HTML
<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="{$this->getEditPath()}" class="btn btn-sm btn-primary" title="{$edit}">
        <i class="fa fa-edit"></i><span class="hidden-xs"> {$edit}</span>
    </a>
</div>
HTML;
    }

    /**
     * Render `delete` tool.
     *
     * @return string
     */
    protected function renderDelete()
    {
        $trans = [
            'delete_confirm' => trans('admin.delete_confirm'),
            'confirm'        => trans('admin.confirm'),
            'cancel'         => trans('admin.cancel'),
            'delete'         => trans('admin.delete'),
        ];

        $js = function ($value) {
            return json_encode((string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        };

        $class = uniqid();

        $script = <<<SCRIPT

$('.{$class}-delete').unbind('click').click(function() {

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
                    url: '{$this->getDeletePath()}',
                    data: {
                        _method:'delete',
                        _token:LA.token,
                    },
                    success: function (data) {
                        if (data.status === false) {
                            deleting = false;
                            resolve();
                            swal(data.message || {$js(trans('admin.delete_failed'))}, '', 'error');
                            return;
                        }
                        resolve(data);
                        $.admin.redirect('{$this->getListPath()}');
                    },
                    error: function (xhr) {
                        deleting = false;
                        resolve();
                        var message = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
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

        $deleteLabel = e($trans['delete']);

        return <<<HTML
<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="javascript:void(0);" class="btn btn-sm btn-danger {$class}-delete" title="{$deleteLabel}">
        <i class="fa fa-trash"></i><span class="hidden-xs">  {$deleteLabel}</span>
    </a>
</div>
HTML;
    }

    /**
     * Render custom tools.
     *
     * @param Collection $tools
     *
     * @return mixed
     */
    protected function renderCustomTools($tools)
    {
        return $tools->map(function ($tool) {
            if ($tool instanceof Renderable) {
                return $tool->render();
            }

            if ($tool instanceof Htmlable) {
                return $tool->toHtml();
            }

            return (string) $tool;
        })->implode(' ');
    }

    /**
     * Render tools.
     *
     * @return string
     */
    public function render()
    {
        $output = $this->renderCustomTools($this->prepends);

        foreach ($this->tools as $tool) {
            $renderMethod = 'render'.ucfirst($tool);
            $output .= $this->$renderMethod();
        }

        return $output.$this->renderCustomTools($this->appends);
    }
}
