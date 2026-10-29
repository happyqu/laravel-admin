<?php

namespace Encore\Admin\Form;

class CascadeScript
{
    public static function runtime(): string
    {
        return <<<'JS'
$.admin = $.admin || {};
if (!$.admin.cascade) {
    $.admin.cascade = {
        sync: function (scope) {
            $(scope).find(':input').each(function () {
                var input = $(this);
                if (!input.closest('.cascade-group').length) return;
                var saved = input.data('adminCascadeDisabled');
                var hidden = input.closest('.cascade-group.hide').length > 0;
                if (hidden && !saved) {
                    input.data('adminCascadeDisabled', {disabled: this.disabled});
                    this.disabled = true;
                } else if (!hidden && saved) {
                    this.disabled = saved.disabled;
                    input.removeData('adminCascadeDisabled');
                } else return;
                if ($.fn.iCheck && input.data('iCheck')) input.iCheck('update');
            });
        },
        matches: function (operator, actual, expected) {
            function list(value) { return Array.isArray(value) ? value : value == null ? [] : [value]; }
            function numeric(value) {
                return typeof value === 'number' || (typeof value === 'string' && /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(value.trim()));
            }
            if (operator === '=' || operator === '!=') {
                var equal;
                if (Array.isArray(actual) && Array.isArray(expected)) {
                    equal = JSON.stringify(actual.map(String).sort()) === JSON.stringify(expected.map(String).sort());
                } else {
                    equal = numeric(actual) && numeric(expected)
                        ? Number(actual) === Number(expected) : (actual == null ? '' : actual) == expected;
                }
                return operator === '=' ? equal : !equal;
            }
            if (['>', '<', '>=', '<='].indexOf(operator) !== -1) {
                if (actual == null) actual = '';
                if (numeric(actual) && numeric(expected)) { actual = Number(actual); expected = Number(expected); }
                if (operator === '>') return actual > expected;
                if (operator === '<') return actual < expected;
                if (operator === '>=') return actual >= expected;
                return actual <= expected;
            }
            if (operator === 'in' || operator === 'notIn') {
                var included = expected.indexOf(actual) !== -1;
                return operator === 'in' ? included : !included;
            }
            if (operator === 'has') return list(actual).indexOf(expected) !== -1;
            var overlaps = list(actual).some(function (value) { return list(expected).indexOf(value) !== -1; });
            if (operator === 'oneIn') return overlaps;
            if (operator === 'oneNotIn') return !overlaps;
            throw new Error('Unsupported cascade operator: ' + operator);
        }
    };
}
JS;
    }
}
