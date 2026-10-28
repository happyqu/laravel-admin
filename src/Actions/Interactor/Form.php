<?php

namespace Encore\Admin\Actions\Interactor;

use Encore\Admin\Actions\RowAction;
use Encore\Admin\Admin;
use Encore\Admin\Form\Field;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class Form extends Interactor
{
    /**
     * @var array
     */
    protected $fields = [];

    /**
     * @var string
     */
    protected $modalId;

    /**
     * @var string
     */
    protected $modalSize = '';

    /**
     * @var string
     */
    protected $confirm = '';

    /**
     * @param string $label
     *
     * @return array
     */
    protected function formatLabel($label)
    {
        return array_filter((array) $label);
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Text
     */
    public function text($column, $label = '')
    {
        $field = new Field\Text($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param $column
     * @param string   $label
     * @param \Closure $builder
     *
     * @return Field\Table
     */
    public function table($column, $label = '', $builder = null)
    {
        $field = new Field\Table($column, [$label, $builder]);

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Text
     */
    public function email($column, $label = '')
    {
        $field = new Field\Email($column, $this->formatLabel($label));

        $this->addField($field)->setView('admin::actions.form.text');

        return $field->inputmask(['alias' => 'email']);
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Text
     */
    public function integer($column, $label = '')
    {
        return $this->text($column, $label)
            ->width('200px')
            ->inputmask(['alias' => 'integer']);
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Text
     */
    public function ip($column, $label = '')
    {
        return $this->text($column, $label)
            ->width('200px')
            ->inputmask(['alias' => 'ip']);
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Text
     */
    public function url($column, $label = '')
    {
        return $this->text($column, $label)
            ->inputmask(['alias' => 'url'])
            ->width('200px');
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Text
     */
    public function password($column, $label = '')
    {
        return $this->text($column, $label)
            ->attribute('type', 'password');
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Text
     */
    public function mobile($column, $label = '')
    {
        return $this->text($column, $label)
            ->inputmask(['mask' => '99999999999'])
            ->width('100px');
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Textarea
     */
    public function textarea($column, $label = '')
    {
        $field = new Field\Textarea($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Select
     */
    public function select($column, $label = '')
    {
        $field = new Field\Select($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\MultipleSelect
     */
    public function multipleSelect($column, $label = '')
    {
        $field = new Field\MultipleSelect($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Checkbox
     */
    public function checkbox($column, $label = '')
    {
        $field = new Field\Checkbox($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Radio
     */
    public function radio($column, $label = '')
    {
        $field = new Field\Radio($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\File
     */
    public function file($column, $label = '')
    {
        $field = new Field\File($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\MultipleFile
     */
    public function multipleFile($column, $label = '')
    {
        $field = new Field\MultipleFile($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Image
     */
    public function image($column, $label = '')
    {
        $field = new Field\Image($column, $this->formatLabel($label));

        $this->addField($field)->setView('admin::actions.form.file');

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\MultipleImage
     */
    public function multipleImage($column, $label = '')
    {
        $field = new Field\MultipleImage($column, $this->formatLabel($label));

        $this->addField($field)->setView('admin::actions.form.muitplefile');

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Date
     */
    public function date($column, $label = '')
    {
        $field = new Field\Date($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Date
     */
    public function datetime($column, $label = '')
    {
        return $this->date($column, $label)->format('YYYY-MM-DD HH:mm:ss');
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Date
     */
    public function time($column, $label = '')
    {
        return $this->date($column, $label)->format('HH:mm:ss');
    }

    /**
     * @param string $column
     * @param string $label
     *
     * @return Field\Hidden
     */
    public function hidden($column, $label = '')
    {
        $field = new Field\Hidden($column, $this->formatLabel($label));

        $this->addField($field);

        return $field;
    }

    /**
     * @param $message
     *
     * @return $this
     */
    public function confirm($message)
    {
        $this->confirm = $message;

        return $this;
    }

    /**
     * @return $this
     */
    public function modalLarge()
    {
        $this->modalSize = 'modal-lg';

        return $this;
    }

    /**
     * @return $this
     */
    public function modalSmall()
    {
        $this->modalSize = 'modal-sm';

        return $this;
    }

    protected function addField(Field $field)
    {
        $this->modalId ??= 'admin-action-'.bin2hex(random_bytes(8));
        $field->scopeElement($this->modalId);
        $name = strtolower(class_basename($field));
        if (view()->exists("admin::actions.form.{$name}")) {
            $field->setView("admin::actions.form.{$name}");
        }
        $this->fields[] = $field;
        return $field;
    }

    private function build(): void
    {
        $this->fields = [];
        if ($this->action instanceof RowAction) {
            $this->action->form($this->action->getRow());
        } else {
            $this->action->form();
        }
    }

    public function addScript()
    {
        // The common runtime is registered by Action; no per-button scripts or hidden forms.
    }

    public function validate(Request $request)
    {
        $this->build();
        $failed = \Encore\Admin\Form\SubmissionValidator::failed($this->fields, $request->all());
        $errors = new MessageBag;
        foreach ($failed as $validator) {
            $errors = $errors->merge($validator->messages());
        }
        if ($errors->any()) {
            throw ValidationException::withMessages($errors->toArray());
        }
        $values = \Encore\Admin\Form\SubmissionInput::onlyFields($request->input(), $this->fields);
        $files = \Encore\Admin\Form\SubmissionInput::onlyFields($request->allFiles(), $this->fields);
        foreach ($this->fields as $field) {
            if (!$field->isWritable()) {
                foreach ((array) $field->column() as $column) {
                    \Illuminate\Support\Arr::forget($values, $column);
                    \Illuminate\Support\Arr::forget($files, $column);
                    // Read-only display values used by handlers come from freshly rebuilt fields.
                    if (is_string($field->column()) && $field->value() !== null) {
                        \Illuminate\Support\Arr::set($values, $column, $field->value());
                    }
                }
            }
        }
        $trusted = $request->attributes->get('_admin_action_parameters', []);
        $values = array_replace($values, $trusted);
        \Illuminate\Support\Arr::forget($files, array_keys($trusted));
        \Encore\Admin\Form\SubmissionInput::replace($request, $values, $files);
    }

    public function renderPayload(): array
    {
        $saved = [];
        foreach (['script', 'deferredScript', 'style', 'html', 'css', 'js'] as $kind) {
            $saved[$kind] = Admin::${$kind};
        }
        try {
            $this->build();
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $html = view('admin::actions.form.modal', ['fields' => $this->fields, 'title' => $this->action->name(),
                'modal_id' => $this->modalId, 'modal_size' => $this->modalSize])->render();
            $assets = ['css' => [], 'js' => []];
            foreach ($this->fields as $field) {
                foreach ($field::getAssets() as $kind => $paths) {
                    $assets[$kind] = array_merge($assets[$kind], (array) $paths);
                }
            }
            foreach (['css', 'js'] as $kind) {
                $assets[$kind] = array_values(array_unique(array_map('admin_asset', array_merge(
                    $assets[$kind], array_values(array_diff(Admin::${$kind}, $saved[$kind]))
                ))));
            }
            return ['mode' => 'form', 'html' => $html.implode('', array_diff(Admin::$html, $saved['html'])),
                'confirm' => $this->confirm, 'assets' => $assets,
                'styles' => array_values(array_diff(Admin::$style, $saved['style'])),
                'scripts' => array_values(array_unique(array_merge(array_diff(Admin::$script, $saved['script']),
                    array_diff(Admin::$deferredScript, $saved['deferredScript']))))];
        } finally {
            foreach ($saved as $kind => $values) {
                Admin::${$kind} = $values;
            }
        }
    }
}
