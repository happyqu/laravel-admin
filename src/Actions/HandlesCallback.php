<?php

namespace Encore\Admin\Actions;

use Illuminate\Http\Request;

/** An action backed by a server-configured public static callback. */
trait HandlesCallback
{
    /**
     * @var string|null
     */
    protected $callbackClass;

    /**
     * @var string|null
     */
    protected $callbackMethod;

    /**
     * @param string $class
     * @param string $method
     *
     * @return $this
     */
    public function setCallback($class, $method)
    {
        $this->callbackClass = $class;
        $this->callbackMethod = $method;

        return $this;
    }

    /**
     * @param mixed   $model
     * @param Request $request
     *
     * @return Response
     */
    protected function executeCallback($model, Request $request)
    {
        if (!$this->callbackClass || !$this->callbackMethod) {
            throw new \LogicException('No callback has been configured for this action.');
        }

        if (!class_exists($this->callbackClass) || !method_exists($this->callbackClass, $this->callbackMethod)) {
            throw new \LogicException('The configured action callback does not exist.');
        }

        $method = new \ReflectionMethod($this->callbackClass, $this->callbackMethod);
        if (!$method->isPublic() || !$method->isStatic()) {
            throw new \LogicException('Action callbacks must be public static methods.');
        }

        // Use the restored, signed configuration; never dispatch from request parameters.
        $result = $method->invoke(null, $model, $request);

        return $result instanceof Response ? $result : $this->callbackResponse($result);
    }

    /**
     * Override to customize messages and follow-up commands.
     *
     * @param mixed $result
     *
     * @return Response
     */
    protected function callbackResponse($result)
    {
        if (is_string($result)) {
            return $this->response()->error($result);
        }

        if ($result === false) {
            return $this->response()->error(trans('admin.action_failed'));
        }

        return $this->response()->success();
    }
}
