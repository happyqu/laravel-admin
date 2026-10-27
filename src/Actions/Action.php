<?php

namespace Encore\Admin\Actions;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;
use Encore\Admin\Support\Html;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

/**
 * @method $this                success($title, $text = '', $options = [])
 * @method $this                error($title, $text = '', $options = [])
 * @method $this                warning($title, $text = '', $options = [])
 * @method $this                info($title, $text = '', $options = [])
 * @method $this                question($title, $text = '', $options = [])
 * @method $this                confirm($title, $text = '', $options = [])
 * @method Field\Text           text($column, $label = '')
 * @method Field\Email          email($column, $label = '')
 * @method Field\Integer        integer($column, $label = '')
 * @method Field\Ip             ip($column, $label = '')
 * @method Field\Url            url($column, $label = '')
 * @method Field\Password       password($column, $label = '')
 * @method Field\Mobile         mobile($column, $label = '')
 * @method Field\Textarea       textarea($column, $label = '')
 * @method Field\Select         select($column, $label = '')
 * @method Field\MultipleSelect multipleSelect($column, $label = '')
 * @method Field\Checkbox       checkbox($column, $label = '')
 * @method Field\Radio          radio($column, $label = '')
 * @method Field\File           file($column, $label = '')
 * @method Field\Image          image($column, $label = '')
 * @method Field\MultipleFile   multipleFile($column, $label = '')
 * @method Field\MultipleImage  multipleImage($column, $label = '')
 * @method Field\Date           date($column, $label = '')
 * @method Field\Datetime       datetime($column, $label = '')
 * @method Field\Time           time($column, $label = '')
 * @method Field\Hidden         hidden($column, $label = '')
 * @method $this                modalLarge()
 * @method $this                modalSmall()
 */
abstract class Action implements Renderable
{
    use Authorizable;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var string
     */
    protected $selector;

    /**
     * @var string
     */
    public $event = 'click';

    /**
     * @var string
     */
    protected $method = 'POST';

    /**
     * @var array
     */
    protected $attributes = [];

    /**
     * @var string
     */
    public $selectorPrefix = '.action-';

    /**
     * @var Interactor\Interactor
     */
    protected $interactor;

    protected $resolvedModel;

    /** Non-secret runtime options explicitly required to rebuild this action. */
    protected $contextProperties = [];

    /**
     * @var array
     */

    /**
     * @var string
     */
    public $name;

    /**
     * Action constructor.
     */
    public function __construct()
    {
        $this->initInteractor();
    }

    /**
     * @throws \Exception
     */
    protected function initInteractor()
    {
        if ($hasForm = method_exists($this, 'form')) {
            $this->interactor = new Interactor\Form($this);
        }

        if ($hasDialog = method_exists($this, 'dialog')) {
            $this->interactor = new Interactor\Dialog($this);
        }

        if ($hasForm && $hasDialog) {
            throw new \Exception('Can only define one of the methods in `form` and `dialog`');
        }
    }

    /**
     * Get batch action title.
     *
     * @return string
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * @param string $prefix
     *
     * @return mixed|string
     */
    public function selector($prefix)
    {
        if (is_null($this->selector)) {
            return static::makeSelector(get_called_class(), $prefix);
        }

        return $this->selector;
    }

    /**
     * @param string $class
     * @param string $prefix
     *
     * @return string
     */
    public static function makeSelector($class, $prefix)
    {
        return $prefix.substr(hash('sha256', $class), 0, 16);
    }

    /**
     * @param string $name
     * @param string $value
     *
     * @return $this
     */
    public function attribute($name, $value)
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    /**
     * Format the field attributes.
     *
     * @return string
     */
    protected function formatAttributes()
    {
        $html = [];

        foreach ($this->attributes as $name => $value) {
            $html[] = $name.'="'.e($value).'"';
        }

        return implode(' ', $html);
    }

    /**
     * @return string
     */
    protected function getElementClass()
    {
        return ltrim($this->selector($this->selectorPrefix), '.');
    }

    /**
     * @return Response
     */
    public function response()
    {
        if (is_null($this->response)) {
            $this->response = new Response();
        }

        if (method_exists($this, 'dialog')) {
            $this->response->swal();
        } else {
            $this->response->toastr();
        }

        return $this->response;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return mixed
     */
    public function getCalledClass()
    {
        return str_replace('\\', '_', get_called_class());
    }

    /**
     * @return string
     */
    public function getHandleRoute()
    {
        return admin_url('_handle_action_');
    }

    /**
     * @return string
     */
    protected function getModelClass()
    {
        return '';
    }

    /**
     * @return array
     */
    public function parameters()
    {
        return [];
    }

    /**
     * @param Request $request
     *
     * @return $this
     */
    public function validate(Request $request)
    {
        if ($this->interactor instanceof Interactor\Form) {
            $this->interactor->validate($request);
        }

        return $this;
    }

    public function interaction(): string
    {
        return $this->interactor instanceof Interactor\Form ? 'form'
            : ($this->interactor instanceof Interactor\Dialog ? 'dialog' : 'direct');
    }

    public function setResolvedModel($model): void
    {
        $this->resolvedModel = $model;
    }

    public function contextModel()
    {
        return $this->resolvedModel ?? (property_exists($this, 'model') ? $this->model : null);
    }

    public function contextKeys(): array
    {
        $model = $this->contextModel();
        return $model instanceof \Illuminate\Database\Eloquent\Model ? [$model->getKey()] : [];
    }

    public function contextDescriptor(): array
    {
        $parameters = $this->parameters();
        $record = null;
        if ($this instanceof RowAction) {
            $record = Context::modelReference($this->getRow());
            unset($record['key']);
        } elseif ($this instanceof BatchAction) {
            $class = $this->getModelClass();
            $record = Context::modelReference($this->parent?->model()->getOriginalModel() ?? new $class);
            unset($record['key']);
        }
        if (!$record && $this->contextModel() instanceof \Illuminate\Database\Eloquent\Model) {
            $record = Context::modelReference($this->contextModel());
            unset($record['key']);
        }
        return ['class' => get_class($this), 'state' => $this->contextState(), 'record' => $record,
            'batch' => $this instanceof BatchAction, 'parameters' => $parameters];
    }

    /** Only declared runtime options; fixed class properties are rebuilt from code. */
    public function contextState(): array
    {
        $state = [];
        foreach ($this->contextProperties as $name) {
            $property = new \ReflectionProperty($this, $name);
            if ($property->isStatic() || !$property->isInitialized($this)) continue;
            $value = $property->getValue($this);
            $this->assertContextValue($value);
            $state[$name] = $value;
        }
        if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \LogicException('Explicit action options exceed 4 KiB. Load large option sets on demand.');
        }
        return $state;
    }

    private function assertContextValue($value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertContextValue($item);
            }
        } elseif ($value !== null && !is_scalar($value)) {
            throw new \LogicException('Action options must contain scalar values and arrays only.');
        }
    }

    public function restoreContext(array $state): void
    {
        foreach ($state as $name => $value) {
            if (!in_array($name, $this->contextProperties, true)) {
                ContextToken::fail('action_context_invalid');
            }
            (new \ReflectionProperty($this, $name))->setValue($this, $value);
        }
    }

    public function loadInteraction(): array
    {
        if ($this->interactor instanceof Interactor\Form) {
            return $this->interactor->renderPayload();
        }
        if ($this->interactor instanceof Interactor\Dialog) {
            return ['mode' => 'dialog', 'settings' => $this->interactor->settings()];
        }
        return ['mode' => 'direct'];
    }

    /**
     * @return mixed
     */
    protected function addScript()
    {
        if (!in_array('vendor/laravel-admin/actions.js', Admin::$js, true)) {
            Admin::js('vendor/laravel-admin/actions.js', false);
        }
        $context = app(Context::class);
        $definition = $context->register($this);
        $this->attribute('data-admin-action', $definition);
        $this->attribute('data-admin-mode', $this->interaction());
        $this->attribute('data-admin-batch', $this instanceof BatchAction ? '1' : '0');
        // Toolbar actions have no selected record. Do not let the browser send
        // their placeholder key (usually 0) as a record selection.
        $this->attribute('data-admin-keyed', $this->contextKeys() ? '1' : '0');
        $this->attribute('data-admin-load', admin_url('_load_action_'));
        $this->attribute('data-admin-execute', $this->getHandleRoute());

        if ($this->interaction() === 'dialog') {
            $model = $this instanceof RowAction ? $this->getRow() : $this->contextModel();
            $this->setResolvedModel($model);
            $this->attribute('data-admin-dialog', json_encode(
                $this->loadInteraction()['settings'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            ));
        }
    }

    /**
     * @param string $method
     * @param array  $arguments
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public function __call($method, $arguments = [])
    {
        if (in_array($method, Interactor\Interactor::$elements)) {
            return $this->interactor->{$method}(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist.");
    }

    /**
     * @return string
     */
    public function html()
    {
    }

    /**
     * @return mixed
     */
    public function render()
    {
        $content = $this->html();
        if (!$content) {
            return $content;
        }

        $node = Html::first($content, $this->selector ?: 'a,button');
        $href = $node ? $node->getAttribute('href') : '';

        if ($href && $href !== '#' && !str_starts_with($href, 'javascript:')) {
            return $content;
        }

        $this->addScript();

        return $this->attachAttributes($content);
    }

    protected function attachAttributes($content)
    {
        if (!$content) {
            return $content;
        }

        $document = Html::document($content);
        $node = Html::firstNode($document, $this->selector ?: 'a,button');

        if (!$node) {
            return $content;
        }

        foreach ($this->attributes as $name => $value) {
            $node->setAttribute($name, $value);
        }

        // Return the whole fragment, not only the matched anchor. Navbar and
        // other custom actions wrap their anchor in a list item; dropping that
        // wrapper leaves a bare <a> in the navbar and moves the button out of
        // its intended position.
        $body = $document->getElementsByTagName('body')->item(0);

        return $body ? Html::innerHtml($body) : Html::outerHtml($node);
    }
}
