<?php

namespace Encore\Admin\Grid\Actions;

use Encore\Admin\Grid;
use Encore\Admin\Security\FormSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** The resource path is restored from the signed action context, never request input. */
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
        if (!$this->resourcePath) {
            return false;
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
}
