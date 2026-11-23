(function ($) {
    $.admin = $.admin || {};
    if ($.admin.initDependentSelects) return;

    var column = 'data-admin-select-column';
    var configAttribute = 'data-admin-select-options-by';
    var initializing = 'data-admin-select-initializing';
    var dependent = 'select[' + configAttribute + ']';

    function scope(field) {
        var group = $(field);

        return group.closest('form[data-admin-filter]')[0] || group.closest('.fields-group, form')[0];
    }

    function fieldsIn(group, selector) {
        return $(group).find(selector).filter(function () { return scope(this) === group; });
    }

    function replaceOptions(field, options, selected) {
        $(field).empty();
        if (!field.multiple) field.add(new Option('', ''));
        options.forEach(function (option) { field.add(new Option(option[1], option[0])); });
        $(field).val(selected);
        if (!field.multiple && field.selectedIndex < 0) field.value = '';
    }

    function localOptions(state, value) {
        var groups = state.config.groups || {};

        return value !== '' && Object.prototype.hasOwnProperty.call(groups, value) ? groups[value] : [];
    }

    function rememberInitial(field, state, parentValue) {
        state.initial = {
            parentValue: parentValue,
            selected: $(field).val(),
            options: Array.from(field.options).filter(function (option) { return option.value !== ''; })
                .map(function (option) { return [option.value, option.text]; })
        };
    }

    function status(field, state, message, busy) {
        var $field = $(field);
        if (busy) $field.attr('aria-busy', 'true');
        else $field.removeAttr('aria-busy');
        if (state.status) {
            state.status.remove();
            state.status = null;
        }
        if (message) {
            var widget = $field.next('.select2');
            state.status = $('<span class="help-block" role="status">').text(message).insertAfter(widget.length ? widget : $field);
        }
    }

    function load(field, state, openAfterLoad) {
        if (openAfterLoad) state.openAfterLoad = true;
        if (state.request) return;

        var value = state.parentValue;
        var $field = $(field);
        var config = state.config;
        status(field, state, config.loading, true);
        var request = $.ajax({url: config.url, data: {parent: value}, dataType: 'json', adminLoading: false});
        state.request = request;

        function isCurrent() {
            return state.request === request && state.parent.value === value && document.documentElement.contains(field);
        }

        request.done(function (items) {
            if (!isCurrent()) return;
            var open = state.openAfterLoad;
            state.request = null;
            state.openAfterLoad = false;
            state.loaded = true;
            status(field, state);

            var selected = $field.val();
            replaceOptions(field, items.map(function (item) { return [item.id, item.text]; }), selected);
            $field.trigger(JSON.stringify(selected) === JSON.stringify($field.val()) ? 'change.select2' : 'change');
            if (open && state.parentValue === value && $field.data('select2')) $field.select2('open');
        }).fail(function () {
            if (!isCurrent()) return;
            state.request = null;
            state.openAfterLoad = false;
            status(field, state, config.failed);
        });
    }

    function cancelLoad(field, state) {
        if (!state.config.url) return;

        var request = state.request;
        state.request = null;
        state.openAfterLoad = false;
        state.loaded = false;
        if (request) request.abort();
        status(field, state);
        if ($(field).data('select2')) $(field).select2('close');
    }

    function init(field) {
        var $field = $(field);
        if ($field.data('adminDependentSelect')) return;

        var config;
        try {
            config = JSON.parse(field.getAttribute(configAttribute));
        } catch (error) {
            window.console && window.console.error('Invalid dependent select configuration:', field, error);
            return;
        }
        if (!config || typeof config !== 'object') return;

        if (config.static) {
            // The server rendered the saved labels but cannot scope a parent query, so the
            // options never follow the parent. Keep the value and tell the developer why.
            window.console && window.console.warn(
                'Dependent select "' + field.name + '" has no queryable parent scope; its saved value'
                + ' is kept but options will not follow "' + config.parent + '".'
                + ' Pass the allowed parent IDs as the fifth argument of optionsBy().'
            );
            return;
        }

        var parent = fieldsIn(scope(field), 'select[' + column + ']:not([multiple])').filter(function () {
            return this !== field && this.getAttribute(column) === config.parent;
        })[0];
        if (!parent) {
            window.console && window.console.warn(
                'Dependent select "' + field.name + '" needs a single-select sibling named "' + config.parent + '"'
                + ' in the same form or field group; options will not follow it.'
            );
            return;
        }

        var state = {parent: parent, config: config};
        $field.data('adminDependentSelect', state);
        if (parent.hasAttribute(configAttribute)) init(parent);
        state.parentValue = parent.value;
        state.pendingParent = parent.hasAttribute(initializing);
        var initialParent = state.pendingParent ? config.initialParent : state.parentValue;
        if (!config.url) {
            replaceOptions(field, localOptions(state, initialParent), config.selected);
            $field.trigger('change.select2');
        } else if (initialParent !== config.initialParent) {
            replaceOptions(field, [], []);
            $field.trigger('change.select2');
        }
        rememberInitial(field, state, initialParent);
    }

    $(document).on('change.adminDependentSelect admin:select-options-initialized.adminDependentSelect', 'select[' + column + ']', function () {
        var parent = this;
        var value = parent.value;
        fieldsIn(scope(parent), dependent).each(function () {
            var $field = $(this);
            var state = $field.data('adminDependentSelect');
            if (!state || state.parent !== parent || parent.value !== value || parent.hasAttribute(initializing)) return;

            var finishingInitialization = state.pendingParent;
            state.pendingParent = false;
            if (finishingInitialization && state.initial.parentValue === value) {
                state.parentValue = value;
                return;
            }
            if (!finishingInitialization && state.parentValue === value) return;

            state.parentValue = value;
            cancelLoad(this, state);
            replaceOptions(this, state.config.url ? [] : localOptions(state, value), []);
            if (finishingInitialization) rememberInitial(this, state, value);
            $field.trigger('change');
            if (state.config.url && value !== '' && parent.value === value) load(this, state);
        });
    });

    // The parent's own option list never arrived: unlock the children but keep
    // whatever they already show, so a failed request cannot blank saved values.
    $(document).on('admin:select-options-failed.adminDependentSelect', 'select[' + column + ']', function () {
        var parent = this;
        fieldsIn(scope(parent), dependent).each(function () {
            var state = $(this).data('adminDependentSelect');
            if (!state || state.parent !== parent) return;
            state.pendingParent = false;
        });
    });

    $(document).on('select2:opening.adminDependentSelect', dependent, function (event) {
        var state = $(this).data('adminDependentSelect');
        if (!state || event.isDefaultPrevented()) return;
        if (state.pendingParent) {
            event.preventDefault();
            return;
        }
        if (!state.config.url || state.loaded) return;

        event.preventDefault();
        if (state.parentValue !== '' && !this.hasAttribute('readonly') && !this.matches(':disabled')) load(this, state, true);
    });

    document.addEventListener('submit', function (event) {
        var blocked = null;
        $(event.target).find(dependent).each(function () {
            var state = $(this).data('adminDependentSelect');
            if (!state || !state.pendingParent || state.parent.value === state.initial.parentValue || this.matches(':disabled')) return;
            var selected = $(this).val();
            if (selected === null || selected === '' || !selected.length) return;
            blocked = state;

            return false;
        });
        if (!blocked) return;

        event.preventDefault();
        if ($.admin.toastr) {
            $.admin.toastr.warning(
                blocked.config.loading || 'Loading...', '', {positionClass: 'toast-top-center'}
            );
        }
    }, true);

    $(document).on('reset.adminDependentSelect', 'form', function (event) {
        var form = this;
        setTimeout(function () {
            if (event.isDefaultPrevented() || !document.documentElement.contains(form)) return;

            var restored = new Set();
            var parents = new Set();
            function restore(field) {
                var state = $(field).data('adminDependentSelect');
                if (!state || restored.has(field)) return;

                restored.add(field);
                restore(state.parent);
                parents.add(state.parent);
                cancelLoad(field, state);
                state.parentValue = state.parent.value;
                var value = state.pendingParent ? state.initial.parentValue : state.parentValue;
                var initial = state.initial.parentValue === value;
                var options = initial ? state.initial.options : (state.config.url ? [] : localOptions(state, value));
                replaceOptions(field, options, initial ? state.initial.selected : []);
            }

            $(form).find(dependent).each(function () { restore(this); });
            parents.forEach(function (parent) { $(parent).trigger('change.select2'); });
            restored.forEach(function (field) { $(field).trigger('change'); });
        }, 0);
    });

    $.admin.initDependentSelects = function () {
        $(dependent).each(function () { init(this); });
    };
})(jQuery);
