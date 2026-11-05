<?php

namespace Encore\Admin\Grid\Actions;

use Encore\Admin\Grid;
use Encore\Admin\Security\FormSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Binds a built-in write action to the resource route of its grid.
 *
 * Grid::resource() falls back to the url of the current page, which is the resource route on a
 * list page but not for grids embedded elsewhere (relation panels, dashboards, widgets). A grid
 * without a resource route must not lose its actions: the missing route is not an authorization
 * decision, so those rows keep using the generic action endpoint.
 */
trait HasResourceAuthorization
{
    protected $resourcePath;

    private bool $checkingResourceForRendering = false;

    public function passesAuthorizationForRendering(): bool
    {
        $this->checkingResourceForRendering = true;
        try {
            return parent::passesAuthorizationForRendering();
        } finally {
            $this->checkingResourceForRendering = false;
        }
    }

    public function setGrid(Grid $grid)
    {
        $this->resourcePath = $grid->resource();

        return parent::setGrid($grid);
    }

    public function resourceUrl(Model $model): string
    {
        return rtrim((string) $this->resourcePath, '/').'/'.rawurlencode((string) $model->getKey());
    }

    protected function allowsResourceOperation(Model $model, string $method): bool
    {
        if (!$this->resourceRouteExists($model, $method)) {
            // Nothing to check: the grid has no resource route, so the source page permission
            // applies on execution, exactly as it did before resource authorization existed.
            return true;
        }

        try {
            FormSubmission::authorizeSource($this->resourceUrl($model), $method, $this->checkingResourceForRendering);
        } catch (AuthorizationException $exception) {
            return false;
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [403, 404, 405], true)) {
                return false;
            }
            throw $exception;
        }

        return true;
    }

    /**
     * Whether a route registered for this request serves the method on the grid's resource path.
     *
     * Only already registered routes are inspected, so no route file is loaded here. The answer is
     * cached per method and path because every row of a grid shares one resource path.
     */
    protected function resourceRouteExists($model, string $method): bool
    {
        if (empty($this->resourcePath) || !$model instanceof Model) {
            return false;
        }

        $key = $method.' '.rtrim((string) $this->resourcePath, '/');
        $routes = request()->attributes->get('_admin_resource_routes');
        if (!$routes instanceof \ArrayObject) {
            $routes = new \ArrayObject();
            request()->attributes->set('_admin_resource_routes', $routes);
        }
        if (!isset($routes[$key])) {
            $routes[$key] = FormSubmission::hasSourceRoute($this->resourceUrl($model), $method);
        }

        return $routes[$key];
    }
}
