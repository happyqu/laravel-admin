<?php

namespace Encore\Admin\Form\Field;

trait HasCollectionEditor
{
    protected function scalarValueRule(): \Closure
    {
        return static function ($attribute, $value, $fail) {
            if (!is_scalar($value) && $value !== null) {
                $fail('Expected a scalar value.');
            }
        };
    }

    protected function validateScalarValues(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $this->column.'.values.'.$key => 'Expected a scalar value.',
                ]);
            }
        }
    }

    protected function setupScript()
    {
        $this->script = <<<'JS'
$('[data-collection-field]').off('click.adminCollection').on('click.adminCollection', '[data-collection-add], [data-collection-remove]', function (event) {
    var root = $(this).closest('[data-collection-field]');
    if (root[0] !== event.delegateTarget) return;
    event.preventDefault();
    event.stopPropagation();
    if ($(this).is('[data-collection-add]')) {
        root.find('[data-collection-body]').first().append(root.children('template[data-collection-template]').html());
    } else {
        $(this).closest('tr').remove();
    }
});
JS;
    }
}
