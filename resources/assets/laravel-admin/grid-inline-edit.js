/*
 * Lightweight inline editor for the Grid `editable()` displayer.
 * No x-editable dependency: the editor is a Bootstrap 5 popover with a
 * native input/select and a JSON request to the resource endpoint.
 */
(function ($, window) {
    'use strict';

    if (!$.fn || !window.bootstrap) {
        return;
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function hideAll() {
        document.querySelectorAll('[data-inline-edit]').forEach(function (element) {
            var instance = window.bootstrap.Popover.getInstance(element);
            if (instance) {
                instance.hide();
            }
        });
    }

    function sourceOf($anchor) {
        var raw = $anchor.attr('data-source');
        if (!raw) {
            return [];
        }
        try {
            return JSON.parse(raw);
        } catch (error) {
            return [];
        }
    }

    function buildEditor($anchor) {
        var type = $anchor.data('type') || 'text';
        var value = $anchor.attr('data-value');
        var $content = $('<div class="admin-inline-edit p-2" style="min-width: 220px;"></div>');
        var $input;

        if (type === 'select' || type === 'radio') {
            $input = $('<select class="form-select form-select-sm"></select>');
            sourceOf($anchor).forEach(function (option) {
                $('<option></option>')
                    .attr('value', option.value)
                    .text(option.text)
                    .prop('selected', String(option.value) === String(value))
                    .appendTo($input);
            });
        } else if (type === 'textarea') {
            $input = $('<textarea class="form-control form-control-sm" rows="3"></textarea>').val(value || '');
        } else if (type === 'checkbox' || type === 'switch') {
            $input = $('<input type="checkbox" class="form-check-input">')
                .prop('checked', value === '1' || value === 'on' || value === true);
        } else if (type === 'date') {
            $input = $('<input type="date" class="form-control form-control-sm">').val(value || '');
        } else if (type === 'datetime') {
            $input = $('<input type="datetime-local" class="form-control form-control-sm">').val((value || '').replace(' ', 'T'));
        } else if (type === 'time') {
            $input = $('<input type="time" class="form-control form-control-sm">').val(value || '');
        } else if (type === 'month') {
            $input = $('<input type="month" class="form-control form-control-sm">').val(value || '');
        } else if (type === 'year') {
            $input = $('<input type="number" min="1900" max="2100" class="form-control form-control-sm">').val(value || '');
        } else {
            $input = $('<input type="text" class="form-control form-control-sm">').val(value || '');
        }

        var $error = $('<div class="text-danger small mt-1"></div>');
        var $actions = $(
            '<div class="d-flex justify-content-end gap-2 mt-2">' +
            '<button type="button" class="btn btn-sm btn-outline-secondary admin-inline-cancel">取消</button>' +
            '<button type="button" class="btn btn-sm btn-primary admin-inline-save">保存</button>' +
            '</div>'
        );

        $content.append($input, $error, $actions);

        $actions.find('.admin-inline-cancel').on('click', function () {
            var instance = window.bootstrap.Popover.getInstance($anchor[0]);
            if (instance) {
                instance.hide();
            }
        });

        $actions.find('.admin-inline-save').on('click', function () {
            var button = this;
            var next = $input.is(':checkbox') ? ($input.prop('checked') ? '1' : '0') : $input.val();
            var original = $anchor.attr('data-original');
            var instance = window.bootstrap.Popover.getInstance($anchor[0]);

            if (String(next) === String(original)) {
                if (instance) {
                    instance.hide();
                }
                return;
            }

            button.disabled = true;
            $error.empty();

            $.ajax({
                url: $anchor.data('url'),
                type: 'POST',
                dataType: 'json',
                data: {
                    _token: LA.token,
                    _method: 'PUT',
                    _editable: 1,
                    pk: $anchor.data('pk'),
                    name: $anchor.data('name'),
                    value: next
                }
            }).done(function (response) {
                if (!response || response.status === false || response.success === false) {
                    $error.text((response && (response.message || response.msg)) || '保存失败');
                    return;
                }

                var display = next;
                if ($input.is('select')) {
                    display = $input.find('option:selected').text();
                } else if ($input.is(':checkbox')) {
                    display = $input.prop('checked') ? 'ON' : 'OFF';
                }

                $anchor.attr('data-value', next).attr('data-original', next);
                $anchor.find('.ie-display').text(display);

                if (window.toastr && response.message) {
                    toastr.success(response.message);
                }

                if (instance) {
                    instance.hide();
                }
            }).fail(function (xhr) {
                var errors = xhr.responseJSON && xhr.responseJSON.errors;
                if (errors) {
                    $error.text(Object.keys(errors).map(function (key) {
                        return [].concat(errors[key]).join(' ');
                    }).join(' '));
                } else {
                    $error.text((xhr.responseJSON && xhr.responseJSON.message) || '保存失败');
                }
            }).always(function () {
                button.disabled = false;
            });
        });

        return $content;
    }

    $(document).on('click', '[data-inline-edit]', function (event) {
        event.preventDefault();

        var anchor = this;
        var $anchor = $(anchor);

        hideAll();

        var instance = window.bootstrap.Popover.getInstance(anchor);
        if (instance) {
            instance.dispose();
        }

        var $content = buildEditor($anchor);

        instance = new window.bootstrap.Popover(anchor, {
            html: true,
            sanitize: false,
            trigger: 'manual',
            container: 'body',
            placement: 'bottom',
            fallbackPlacements: ['top', 'right', 'left'],
            content: $content[0]
        });

        anchor.addEventListener('shown.bs.popover', function () {
            $content.find('input, select, textarea').first().trigger('focus');
        }, { once: true });

        instance.show();
    });

    $(document).on('click', function (event) {
        if ($(event.target).closest('[data-inline-edit], .popover').length === 0) {
            hideAll();
        }
    });
})(window.jQuery, window);
