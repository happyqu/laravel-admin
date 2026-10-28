<?php

namespace Encore\Admin\Actions\Interactor;

use Encore\Admin\Actions\Action;

abstract class Interactor
{
    /**
     * @var Action
     */
    protected $action;

    /**
     * @var array
     */
    public static $elements = [
        'success', 'error', 'warning', 'info', 'question', 'confirm',
        'text', 'email', 'integer', 'ip', 'url', 'password', 'mobile',
        'textarea', 'select', 'multipleSelect', 'checkbox', 'radio',
        'file', 'image', 'date', 'datetime', 'time', 'hidden', 'multipleImage',
        'multipleFile', 'table', 'modalLarge', 'modalSmall',
    ];

    /**
     * Dialog constructor.
     *
     * @param Action $action
     */
    public function __construct(Action $action)
    {
        $this->action = $action;
    }

    /** Keep row-specific dialogs attached to the cloned action used by a Grid column. */
    public function copyFor(Action $action): static
    {
        $copy = clone $this;
        $copy->action = $action;
        return $copy;
    }

    /**
     * @return mixed
     */
    abstract public function addScript();
}
