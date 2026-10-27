<?php

namespace Encore\Admin\Controllers;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\GridAction;
use Encore\Admin\Actions\Response;
use Encore\Admin\Actions\RowAction;
use Encore\Admin\Widgets\Form;
use Exception;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

class HandleController extends Controller
{
    /**
     * @param Request $request
     *
     * @return $this|mixed
     */
    public function handleForm(Request $request)
    {
        $form = $this->resolveForm($request);

        abort_unless($form->passesAuthorization(), 403);

        if ($errors = $form->validate($request)) {
            return \Encore\Admin\Form\ValidationResponse::make($errors, $request);
        }

        return $form->sanitize()->handle(request());
    }

    /**
     * @param Request $request
     *
     * @throws Exception
     *
     * @return Form
     */
    protected function resolveForm(Request $request)
    {
        if (!$request->has('_form_')) {
            abort(400, 'Invalid form request.');
        }

        $formClass = $request->input('_form_');

        abort_unless(is_string($formClass) && is_subclass_of($formClass, Form::class), 400, 'Invalid form class.');
        $request->attributes->set('_admin_form_context', \Encore\Admin\Security\FormSubmission::verify($request, $formClass));

        /** @var Form $form */
        $form = app($formClass);

        if (!method_exists($form, 'handle')) {
            throw new Exception("Form method {$formClass}::handle() does not exist.");
        }

        $form->restoreSubmissionContext($request->attributes->get('_admin_form_context', []));

        return $form;
    }

    /**
     * @param Request $request
     *
     * @return $this|\Illuminate\Http\JsonResponse
     */
    public function handleAction(Request $request)
    {
        return app(\Encore\Admin\Actions\Context::class)->execute($request, fn () => $this->executeAction($request));
    }

    private function executeAction(Request $request)
    {
        [$action, $model, $token] = app(\Encore\Admin\Actions\Context::class)->resolve($request);
        $arguments = $action instanceof GridAction ? [$model] : [];

        try {
            $response = $action->validate(request())->handle(
                ...$this->resolveActionArgs(request(), ...$arguments)
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return \Encore\Admin\Form\ValidationResponse::make(new \Illuminate\Support\MessageBag($exception->errors()), $request);
        } catch (\Throwable $exception) {
            return Response::withException($exception)->send();
        }

        if ($response instanceof Response) {
            return $response->send();
        }
        return $response;
    }

    public function loadAction(Request $request)
    {
        $contexts = app(\Encore\Admin\Actions\Context::class);
        [$action, $model, $definition, $keys] = $contexts->resolve($request, true);
        $payload = $action->loadInteraction();
        $payload['ticket'] = $contexts->executionTicket($definition, $keys, $model);
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param Request               $request
     * @param Model|Collection|bool $model
     *
     * @return array
     */
    protected function resolveActionArgs(Request $request, $model = null)
    {
        $args = [$request];

        if (!empty($model)) {
            array_unshift($args, $model);
        }

        return $args;
    }

    /**
     * @param Request $request
     *
     * @return mixed|string|string[]
     */
    public function handleSelectable(Request $request)
    {
        $class = $request->get('selectable');
        $args = $request->get('args', []);

        abort_unless(is_string($class) && is_array($args) && collect($args)->every(static fn ($arg) => is_scalar($arg)), 400);
        $class = str_replace('_', '\\', $class);
        $args = \Encore\Admin\Security\ComponentSubmission::verify($request, 'selectable', $class, array_values($args));
        $class = $request->attributes->get('_admin_component_class');

        if (class_exists($class) && is_subclass_of($class, \Encore\Admin\Grid\Selectable::class)) {
            /** @var \Encore\Admin\Grid\Selectable $selectable */
            $selectable = new $class(...array_values((array) $args));

            return $selectable->render();
        }

        abort(404);
    }

    /**
     * @param Request $request
     *
     * @return mixed|string|string[]
     */
    public function handleRenderable(Request $request)
    {
        $class = $request->get('renderable');
        $key = $request->get('key');

        abort_unless(is_string($class) && ($key === null || is_scalar($key)), 400);
        $class = str_replace('_', '\\', $class);
        $payload = \Encore\Admin\Security\ComponentSubmission::verify($request, 'renderable', $class, [$key]);
        $class = $request->attributes->get('_admin_component_class');
        $key = $payload[0];

        if (class_exists($class) && is_subclass_of($class, Renderable::class)) {
            /** @var Renderable $renderable */
            $renderable = new $class();

            return $renderable->render($key);
        }

        abort(404);
    }
}
