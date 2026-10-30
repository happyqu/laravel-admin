<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Admin;

trait HasChoiceInput
{
    protected function choiceValue()
    {
        $name = $this->elementName ?: $this->formatName($this->column);
        $path = trim(str_replace(['[', ']'], ['.', ''], $name), '.');

        return old($path, $this->value());
    }

    protected function initChoiceInput(): void
    {
        $name = $this->elementName ?: $this->formatName($this->column);
        $path = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
        $missing = new \stdClass();
        $value = old($path, $missing);
        $this->addVariables([
            'choiceValue' => $value === $missing ? $this->value() : $value,
            'choiceValues' => array_map('strval', array_filter((array) ($value === $missing ? $this->value() : $value),
                static fn ($item) => is_scalar($item) && (string) $item !== '')),
            'hasOldChoiceInput' => $value !== $missing,
        ]);
        $this->attribute('data-admin-choice', '1');

        Admin::script(<<<'JS'
;(function () {
    $.admin = $.admin || {};
    if ($.admin.choiceGuardInstalled) return;
    $.admin.choiceGuardInstalled = true;
    function locked(input) { return input && (input.readOnly || input.hasAttribute('readonly') || input.matches(':disabled')); }
    function guard(event) {
        if (event.type === 'keydown' && (event.key === 'Tab' || event.keyCode === 9)) return;
        var target = $(event.target);
        var box = target.closest('.bootstrap-duallistbox-container');
        var input = box.length ? box.next('select[data-admin-choice]')[0]
            : target.closest('label').find('input[data-admin-choice]')[0] || target.closest('[data-admin-choice]')[0];
        if (locked(input)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }
    ['click', 'dblclick', 'mousedown', 'touchstart', 'keydown', 'keyup', 'keypress', 'change'].forEach(function (type) {
        document.addEventListener(type, guard, true);
    });
    $(document).on('select2:opening.adminChoice select2:selecting.adminChoice select2:unselecting.adminChoice select2:clearing.adminChoice',
        'select[data-admin-choice]', function (event) { if (locked(this)) event.preventDefault(); });
})();
JS);
    }
}
