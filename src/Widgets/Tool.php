<?php

namespace Encore\Admin\Widgets;

use Encore\Admin\Actions\GridAction;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Support\Authorization;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Model;

/** Base for custom tools, or an authorization wrapper around an existing tool/HTML fragment. */
class Tool implements Renderable
{
    use Authorization;

    protected $content;

    protected $authorizationModel;

    public function __construct($content = '')
    {
        $this->content = $content;
    }

    /** Bind a record for a record-level Policy; otherwise ability() checks a global Gate. */
    public function forModel(Model $model)
    {
        $this->authorizationModel = $model;
        return $this;
    }

    /** Forward the container context through wrappers before checking or rendering the tool. */
    public function setGrid(Grid $grid)
    {
        if ($this->content instanceof self || $this->content instanceof GridAction) {
            $this->content->setGrid($grid);
        }
        return $this;
    }

    public function passesAuthorization(): bool
    {
        // Keep unconfigured tools usable, while retaining the rules of any wrapped Tool.
        if ($this->authorizationRules || method_exists($this, 'authorize')) {
            $user = Admin::user();
            if (!$user || !$this->passesConfiguredAuthorization($user, $this->authorizationModel)
                || (method_exists($this, 'authorize') && $this->authorize($user, $this->authorizationModel) != true)) {
                return false;
            }
        }

        return !$this->content instanceof self || $this->content->passesAuthorization();
    }

    /** Containers call this before render(), so unauthorized tools cannot register scripts. */
    public function renderAuthorized()
    {
        if (!$this->passesAuthorization()) {
            return $this->authorizationDeniedHtml();
        }
        return $this->render();
    }

    public function render()
    {
        if ($this->content instanceof self) return $this->content->renderAuthorized();
        if ($this->content instanceof Renderable) return $this->content->render();
        if ($this->content instanceof Htmlable) return $this->content->toHtml();
        return (string) $this->content;
    }

    public function __toString()
    {
        return (string) $this->renderAuthorized();
    }
}
