<?php

namespace Encore\Admin;

use Closure;
use Encore\Admin\Tree\Tools;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Model;

class Tree implements Renderable
{
    /**
     * @var array
     */
    protected $items = [];

    /**
     * @var string
     */
    protected $elementId = 'tree-';

    /**
     * Current request path used for tree actions.
     *
     * @var string
     */
    protected $path = '';

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var \Closure
     */
    protected $queryCallback;

    /**
     * View of tree to render.
     *
     * @var string
     */
    protected $view = [
        'tree'   => 'admin::tree',
        'branch' => 'admin::tree.branch',
    ];

    /**
     * @var \Closure
     */
    protected $callback;

    /**
     * @var null
     */
    protected $branchCallback = null;

    /**
     * @var bool
     */
    public $useCreate = true;

    /**
     * @var bool
     */
    public $useSave = true;

    /**
     * @var bool
     */
    public $useRefresh = true;

    /**
     * @var array
     */
    protected $nestableOptions = [];

    /**
     * Header tools.
     *
     * @var Tools
     */
    public $tools;

    /**
     * Menu constructor.
     *
     * @param Model|null $model
     */
    public function __construct(?Model $model = null, ?\Closure $callback = null)
    {
        $this->model = $model;

        $this->path = \request()->getPathInfo();
        $this->elementId .= uniqid();

        $this->setupTools();

        if ($callback instanceof \Closure) {
            call_user_func($callback, $this);
        }

        $this->initBranchCallback();
    }

    /**
     * Setup tree tools.
     */
    public function setupTools()
    {
        $this->tools = new Tools($this);
    }

    /**
     * Initialize branch callback.
     *
     * @return void
     */
    protected function initBranchCallback()
    {
        if (is_null($this->branchCallback)) {
            $this->branchCallback = function ($branch) {
                $key = $branch[$this->model->getKeyName()];
                $title = $branch[$this->model->getTitleColumn()];

                return e($key).' - '.e($title);
            };
        }
    }

    /**
     * Set branch callback.
     *
     * @param \Closure $branchCallback
     *
     * @return $this
     */
    public function branch(\Closure $branchCallback)
    {
        $this->branchCallback = $branchCallback;

        return $this;
    }

    /**
     * Set query callback this tree.
     *
     * @return Model
     */
    public function query(\Closure $callback)
    {
        $this->queryCallback = $callback;

        return $this;
    }

    /**
     * Set nestable options.
     *
     * @param array $options
     *
     * @return $this
     */
    public function nestable($options = [])
    {
        $this->nestableOptions = array_merge($this->nestableOptions, $options);

        return $this;
    }

    /**
     * Disable create.
     *
     * @return void
     */
    public function disableCreate()
    {
        $this->useCreate = false;
    }

    /**
     * Disable save.
     *
     * @return void
     */
    public function disableSave()
    {
        $this->useSave = false;
    }

    /**
     * Disable refresh.
     *
     * @return void
     */
    public function disableRefresh()
    {
        $this->useRefresh = false;
    }

    /**
     * Save tree order from a input.
     *
     * @param string $serialize
     *
     * @return bool
     */
    public function saveOrder($serialize)
    {
        $tree = json_decode($serialize, true);

        if (json_last_error() != JSON_ERROR_NONE || !is_array($tree)) {
            throw new \InvalidArgumentException('Invalid tree order payload.');
        }

        $this->model->getConnection()->transaction(function () use ($tree) {
            $this->model->saveOrder($tree);
        });

        return true;
    }

    /**
     * Build tree grid scripts.
     *
     * @return string
     */
    protected function script()
    {
        $trans = [
            'delete_confirm'    => trans('admin.delete_confirm'),
            'save_succeeded'    => trans('admin.save_succeeded'),
            'refresh_succeeded' => trans('admin.refresh_succeeded'),
            'delete_succeeded'  => trans('admin.delete_succeeded'),
            'confirm'           => trans('admin.confirm'),
            'cancel'            => trans('admin.cancel'),
        ];

        $js = function ($value) {
            return json_encode((string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        };

        $nestableOptions = json_encode($this->nestableOptions);

        $url = url($this->path);

        return <<<SCRIPT

        $('#{$this->elementId}').nestable($nestableOptions);

        $('.tree_branch_delete').click(function() {
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
                            url: '{$url}/' + id,
                            data: {
                                _method:'delete',
                                _token:LA.token,
                            },
                            success: function (data) {
                                if (data && data.status === false) {
                                    deleting = false;
                                    resolve();
                                    swal(data.message || {$js(trans('admin.delete_failed'))}, '', 'error');
                                    return;
                                }
                                resolve(data);
                                $.admin.notifyThenNavigate((data && data.message) ? data.message : {$js($trans['delete_succeeded'])}, 'success');
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

        $('.{$this->elementId}-save').click(function () {
            var serialize = $('#{$this->elementId}').nestable('serialize');

            $.post('{$url}', {
                _token: LA.token,
                _order: JSON.stringify(serialize)
            })
            .done(function (data) {
                if (data && data.status === false) {
                    $.admin.notifyThenNavigate(data.message || {$js(trans('admin.request_failed'))}, 'error');
                    return;
                }
                $.admin.notifyThenNavigate(data && data.message ? data.message : {$js($trans['save_succeeded'])}, 'success');
            })
            .fail(function (xhr) {
                var message = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : {$js(trans('admin.request_failed'))};
                if (xhr.status === 401) {
                    message = {$js(trans('admin.authentication_expired'))};
                }
                $.admin.notifyThenNavigate(message, 'error');
            });
        });

        $('.{$this->elementId}-refresh').click(function () {
            $.admin.notifyThenNavigate({$js($trans['refresh_succeeded'])}, 'success');
        });

        $('.{$this->elementId}-tree-tools').on('click', function(e){
            var action = $(this).data('action');
            if (action === 'expand') {
                $('.dd').nestable('expandAll');
            }
            if (action === 'collapse') {
                $('.dd').nestable('collapseAll');
            }
        });


SCRIPT;
    }

    /**
     * Set view of tree.
     *
     * @param string $view
     */
    public function setView($view)
    {
        $this->view = $view;
    }

    /**
     * Return all items of the tree.
     *
     * @return array
     */
    public function getItems()
    {
        return $this->model->withQuery($this->queryCallback)->toTree();
    }

    /**
     * Variables in tree template.
     *
     * @return array
     */
    public function variables()
    {
        return [
            'id'         => $this->elementId,
            'tools'      => $this->tools->render(),
            'items'      => $this->getItems(),
            'useCreate'  => $this->useCreate,
            'useSave'    => $this->useSave,
            'useRefresh' => $this->useRefresh,
        ];
    }

    /**
     * Setup grid tools.
     *
     * @param Closure $callback
     *
     * @return void
     */
    public function tools(Closure $callback)
    {
        call_user_func($callback, $this->tools);
    }

    /**
     * Render a tree.
     *
     * @return \Illuminate\Http\JsonResponse|string
     */
    public function render()
    {
        Admin::script($this->script());

        view()->share([
            'path'           => $this->path,
            'keyName'        => $this->model->getKeyName(),
            'branchView'     => $this->view['branch'],
            'branchCallback' => $this->branchCallback,
        ]);

        return view($this->view['tree'], $this->variables())->render();
    }

    /**
     * Get the string contents of the grid view.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->render();
    }
}
