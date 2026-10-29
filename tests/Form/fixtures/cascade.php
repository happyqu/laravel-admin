<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Encore\Admin\Form;
use Encore\Admin\Form\Field;

$app = new Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new Illuminate\Config\Repository(['app' => ['locale' => 'en']]));
$app->instance('session', new Illuminate\Session\Store('cascade', new Illuminate\Session\ArraySessionHandler(120)));
$app->instance('request', Illuminate\Http\Request::create('/admin/items'));

class CascadeFixtureSelectable extends Encore\Admin\Grid\Selectable
{
    public function make() {}
}

function formFixture(): Form
{
    return new Form(new class extends Illuminate\Database\Eloquent\Model {});
}

function emitFixture(Form $form): array
{
    Encore\Admin\Admin::$script = [];
    $html = '<form>';
    $events = [];
    foreach ($form->fields() as $field) {
        if ($field instanceof Field\CascadeGroup || $field instanceof Field\Html) {
            $html .= $field->render();
        } elseif (method_exists($field, 'addCascadeScript')) {
            $column = $field->column();
            $class = $field->getElementClassString();
            $multiple = $field instanceof Field\MultipleSelect;
            if ($field instanceof Field\Radio || $field instanceof Field\Checkbox) {
                foreach ([1, 2] as $value) {
                    $html .= '<input class="'.$class.'" type="'.($multiple ? 'checkbox' : 'radio').'" name="'.$column.($multiple ? '[]' : '').'" value="'.$value.'" '.($value === 1 ? 'checked' : '').'>';
                }
            } else {
                $html .= '<select class="'.$class.'" name="'.$column.($multiple ? '[]' : '').'" '.($multiple ? 'multiple' : '').'><option value="1" selected>1</option><option value="2">2</option><option value="10">10</option><option value="19">19</option></select>';
            }
            (new ReflectionMethod($field, 'addCascadeScript'))->invoke($field);
            $events[$column] = (new ReflectionProperty($field, 'cascadeEvent'))->getValue($field);
        } else {
            $html .= '<input name="'.$field->column().'" value="'.($field->column() === 'locked' ? 'locked' : '').'" '.($field->column() === 'locked' ? 'disabled' : 'required').'>';
        }
    }

    return ['html' => $html.'</form>', 'script' => implode("\n", Encore\Admin\Admin::$script), 'events' => $events];
}

$out = [];
foreach (['select', 'multipleSelect', 'belongsTo', 'belongsToMany', 'radio', 'radioButton', 'radioCard', 'checkbox', 'checkboxButton', 'checkboxCard'] as $type) {
    $form = formFixture();
    $field = in_array($type, ['belongsTo', 'belongsToMany'])
        ? $form->{$type}('status', CascadeFixtureSelectable::class) : $form->{$type}('status');
    $field->when($field instanceof Field\MultipleSelect ? 'has' : '=', 1, function ($form) {
        $form->text('extra');
        $form->text('locked');
    });
    $out[$type] = emitFixture($form);
}
// Same field names and conditions, with independently generated branch identities.
$form = formFixture();
$form->select('status')->when(1, fn ($form) => $form->text('extra'));
$out['otherForm'] = emitFixture($form);
$form = formFixture();
$form->select('status')->when('>=', 18, fn ($form) => $form->text('adult'))
    ->when('<', 18, fn ($form) => $form->text('minor'));
$out['collision'] = emitFixture($form);
$form = formFixture();
foreach (['>', '<', '>=', '<='] as $index => $operator) {
    if ($index === 0) $field = $form->select('status');
    $field->when($operator, 2, fn ($form) => $form->text('branch_'.$index));
}
$out['numeric'] = emitFixture($form);
$form = formFixture();
$form->select('outer')->when(1, fn ($form) => $form->select('inner')->when(1, fn ($form) => $form->text('extra')));
$out['nested'] = emitFixture($form);
$form = formFixture();
$form->select('outer')->when(1, fn ($form) => $form->checkbox('inner')->when('has', 1, fn ($form) => $form->text('extra')));
$out['nestedCheckbox'] = emitFixture($form);

foreach (['BelongsTo', 'BelongsToMany'] as $type) {
    $field = $type === 'BelongsTo'
        ? new class('related', [CascadeFixtureSelectable::class]) extends Field\BelongsTo {
            protected function getLoadUrl($multiple = 0) { return '/selector'; }
        }
        : new class('related', [CascadeFixtureSelectable::class]) extends Field\BelongsToMany {
            protected function getLoadUrl($multiple = 0) { return '/selector'; }
        };
    Encore\Admin\Admin::$script = [];
    (new ReflectionMethod($field, 'addScript'))->invoke($field);
    $out['remove'.$type] = implode("\n", Encore\Admin\Admin::$script);
}

echo json_encode($out, JSON_THROW_ON_ERROR);
