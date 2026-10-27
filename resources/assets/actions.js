(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory;
    else factory(root, root.jQuery);
}(typeof window !== 'undefined' ? window : globalThis, function (root, $) {
    'use strict';

    function State() { this.sequence = 0; this.request = null; this.saving = false; }
    State.prototype.open = function () {
        if (this.saving) return null;
        this.sequence++;
        if (this.request && this.request.abort) this.request.abort();
        this.request = null;
        return this.sequence;
    };
    State.prototype.current = function (id) { return this.sequence === id; };
    State.prototype.lock = function () { if (this.saving) return false; this.saving = true; return true; };
    State.prototype.unlock = function () { this.saving = false; };
    State.prototype.close = function () { if (!this.saving) this.open(); };

    function append(form, key, value) {
        if (Array.isArray(value)) value.forEach(function (item, index) { append(form, key + '[' + index + ']', item); });
        else if (value && typeof value === 'object' && !(root.Blob && value instanceof root.Blob)) {
            Object.keys(value).forEach(function (name) { append(form, key + '[' + name + ']', value[name]); });
        } else form.append(key, value == null ? '' : value);
    }

    function Resources(load) { this.promises = Object.create(null); this.load = load; }
    Resources.prototype.get = function (kind, url) {
        var key = kind + ':' + url, self = this;
        if (!self.promises[key]) self.promises[key] = Promise.resolve().then(function () {
            return self.load(kind, url);
        }).catch(function (error) { delete self.promises[key]; throw error; });
        return self.promises[key];
    };
    var api = { State: State, Resources: Resources, append: append };
    if (!$ || !root.document) return api;
    if (root.HappyAdminActions) return root.HappyAdminActions;
    root.HappyAdminActions = api;

    var state = new State(), active = null, shell = null, closing = false, queued = null;
    function notify(error) {
        if (error && error.adminActionReported) return;
        if (error && typeof error === 'object') error.adminActionReported = true;
        // 401 is handled globally by laravel-admin.js (redirect to login).
        if (error && error.status === 401) return;
        var message = $.admin.responseMessage(error && error.responseJSON, $.admin.responseMessage(error));
        $.admin.toastr.error(message || 'Unable to complete this action.');
    }
    var resources = new Resources(function (kind, url) {
        var absolute = new URL(url, root.document.baseURI).href;
        var selector = kind === 'js' ? 'script[src]' : 'link[rel="stylesheet"]';
        var found = Array.from(root.document.querySelectorAll(selector)).some(function (node) {
            return (kind === 'js' ? node.src : node.href) === absolute;
        });
        if (found) return Promise.resolve();
        return new Promise(function (resolve, reject) {
            var node = root.document.createElement(kind === 'js' ? 'script' : 'link');
            node.onload = resolve;
            node.onerror = function () { node.remove(); reject(new Error('Unable to load action resource.')); };
            if (kind === 'js') { node.src = absolute; node.async = false; }
            else { node.rel = 'stylesheet'; node.href = absolute; }
            root.document.head.appendChild(node);
        });
    });
    function assets(definition) {
        definition = definition || {};
        return Promise.all((definition.css || []).map(function (url) { return resources.get('css', url); }))
            .then(function () { return (definition.js || []).reduce(function (chain, url) {
                return chain.then(function () { return resources.get('js', url); });
            }, Promise.resolve()); });
    }
    function destroyFields() {
        if (!shell) return;
        shell.find(':input').each(function () {
            var input = $(this);
            if (input.data('select2') && $.fn.select2) input.select2('destroy');
            if (input.data('fileinput') && $.fn.fileinput) input.fileinput('destroy');
            if (input.data('DateTimePicker')) input.data('DateTimePicker').destroy();
            if (input.data('iCheck') && $.fn.iCheck) input.iCheck('destroy');
        });
    }
    function modal() {
        if (!shell) {
            shell = $('<div class="modal fade" data-admin-action-shell="1" tabindex="-1" role="dialog"></div>').appendTo(root.document.body);
            shell.on('hide.bs.modal', function (event) {
                if (state.saving) event.preventDefault();
                else { closing = true; state.close(); }
            });
            shell.on('hidden.bs.modal', function () {
                destroyFields(); shell.empty(); active = null; closing = false;
                if (queued) { var next = queued; queued = null; open(next); }
            });
            shell.on('submit.happyAdminAction', 'form', function (event) {
                event.preventDefault();
                if (!active || !state.lock()) return;
                var form = this, current = active;
                var confirmation = current.confirm ? $.admin.swal({ type: 'question', title: current.confirm, showCancelButton: true }) : Promise.resolve({ value: true });
                Promise.resolve(confirmation).then(function (result) {
                    if (!result.value) return;
                    shell.find(':submit').button('loading');
                    clearErrors();
                    var data = new root.FormData(form);
                    data.set('_action_token', current.ticket);
                    data.set('_token', $.admin.token);
                    return send(current, data, true);
                }).catch(notify).finally(function () { state.unlock(); shell.find(':submit').button('reset'); });
            });
        }
        return shell;
    }
    function clearErrors() { shell.find('.has-error').removeClass('has-error'); shell.find('[data-admin-action-error]').remove(); }
    function showErrors(error) {
        var errors = error.responseJSON && error.responseJSON.errors;
        if (!errors || !shell) return false;
        Object.keys(errors).forEach(function (key) {
            var parts = key.split('.'), name = parts.shift() + parts.map(function (part) { return '[' + part + ']'; }).join('');
            var input = shell.find(':input').filter(function () { return this.name === name || this.name === name + '[]'; }).first();
            if (input.length) $('<span class="help-block" data-admin-action-error="1"></span>')
                .text([].concat(errors[key]).join('\n')).appendTo(input.closest('.form-group').addClass('has-error'));
        });
        notify(error);
        return true;
    }
    function runThen(command) {
        if (command.action === 'refresh') { $.admin.reload({adminLoading: false}); }
        if (command.action === 'redirect') { $.admin.redirect(command.value); }
        if (command.action === 'location') { root.location.assign(command.value); }
        if (['open', 'download'].indexOf(command.action) !== -1) { root.open(command.value, '_blank', 'noopener'); }
    }
    function response(data, target) {
        if (data.html !== undefined) target.html(data.html);
        var command = $.admin.isResponseError(data) ? null : (data.then || null);
        if (data.swal) {
            return Promise.resolve($.admin.swal(data.swal)).then(function () {
                if (command) runThen(command);
            });
        }
        if (data.toastr && data.toastr.type) {
            $.admin.toastr[data.toastr.type](data.toastr.content, '', data.toastr.options);
            // Let the toast stay visible briefly before the page goes away.
            if (command) { setTimeout(function () { runThen(command); }, 700); }
            return;
        }
        if (command) { runThen(command); }
    }
    function send(current, data, multipart, deferDialogResponse) {
        return Promise.resolve($.ajax({ url: current.url, method: 'POST', data: multipart ? data : JSON.stringify(data),
            processData: false, contentType: multipart ? false : 'application/json', dataType: 'json' }))
            .then(function (result) {
                if (!(deferDialogResponse && result.swal && !$.admin.isResponseError(result))) {
                    response(result, current.target);
                }
                if ($.admin.isResponseError(result)) {
                    throw { responseJSON: result, adminActionReported: !!(result.toastr || result.swal) };
                }
                if (result.status === true && shell && current.mode === 'form') {
                    state.unlock(); shell.modal('hide');
                }
                return result;
            }).catch(function (error) {
                if (current.mode === 'form' && error.status === 422) showErrors(error);
                else notify(error);
                throw error;
            });
    }
    function selected(button) {
        if (button.attr('data-admin-batch') !== '1') {
            // Toolbar actions carry a placeholder key (0) but act on the page,
            // not on a selected record.
            if (button.attr('data-admin-keyed') === '0') return [];
            var key = button.attr('data-_key');
            return key == null || key === '' ? [] : [key];
        }
        var grid = button.closest('.grid-box');
        var name = button.attr('data-admin-selection');
        return grid.find('input[type="checkbox"][data-id]:checked').filter(function () {
            return this.classList.contains(name);
        }).map(function () { return this.getAttribute('data-id'); }).get();
    }
    function showDialog(current, settings, sequence) {
        settings = Object.assign({}, settings);
        settings.preConfirm = function (value) {
            if (!state.current(sequence) || !state.lock()) return Promise.reject();
            function execute() {
                var data = new root.FormData();
                append(data, '_input', value);
                append(data, '_key', current.keys);
                data.set('_action_token', current.ticket); data.set('_token', $.admin.token);
                return send(current, data, true, true);
            }
            return execute().finally(function () { state.unlock(); });
        };
        settings.allowOutsideClick = function () { return !state.saving; };
        settings.allowEscapeKey = function () { return !state.saving; };
        Promise.resolve($.admin.swal(settings)).then(function (result) {
            // preConfirm must finish closing the confirmation before opening its result.
            if (result && result.value && result.value.swal && !$.admin.isResponseError(result.value)) {
                response(result.value, current.target);
            }
        }).catch(function () {});
    }
    function open(button) {
        if (closing) { queued = button; return; }
        var sequence = state.open();
        if (sequence === null) return;
        var keys = selected(button), mode = button.attr('data-admin-mode');
        if (button.attr('data-admin-batch') === '1' && !keys.length) { $.admin.toastr.warning('No data selected!'); return; }
        var current = { target: button, url: button.attr('data-admin-execute'), ticket: button.attr('data-admin-action'), mode: mode,
            keys: keys };
        var inlineDialog = button.attr('data-admin-dialog');
        if (mode === 'dialog' && inlineDialog) {
            try { showDialog(current, JSON.parse(inlineDialog), sequence); }
            catch (error) { notify(error); }
            return;
        }
        if (mode === 'direct') {
            state.lock();
            send(current, { _action_token: current.ticket, _key: keys, _token: $.admin.token }, false)
                .catch(function () {}).finally(function () { state.unlock(); });
            return;
        }
        var request = $.ajax({
            url: button.attr('data-admin-load'), method: 'POST',
            data: { _action_token: current.ticket, _key: keys, _token: $.admin.token }, dataType: 'json', cache: false
        });
        state.request = request;
        Promise.resolve(request).then(function (payload) {
            if ($.admin.isResponseError(payload)) { throw { responseJSON: payload }; }
            return assets(payload.assets).then(function () {
                if (!state.current(sequence)) return;
                if (!payload.ticket) { throw new Error('Unable to prepare this action.'); }
                current.ticket = payload.ticket;
                current.confirm = payload.confirm;
                active = current;
                if (payload.mode === 'form') {
                    var markup = $('<div>').append($.parseHTML(payload.html, root.document, false));
                    var content = markup.find('.modal').first();
                    var dialog = content.find('.modal-dialog').first();
                    var title = button.text().trim();
                    if (title) dialog.find('.modal-title').text(title);
                    // The form only appears once its loading finished.
                    modal(); destroyFields();
                    shell.attr('id', content.attr('id'));
                    var holder = shell.children('.modal-dialog').first();
                    if (!holder.length) { holder = $('<div class="modal-dialog"></div>').appendTo(shell); }
                    holder.attr('class', dialog.attr('class')).empty().append(dialog.children());
                    shell.children('[data-admin-action-extra]').remove();
                    $('<div data-admin-action-extra="1"></div>').append(markup.contents().not(content)).appendTo(shell);
                    (payload.styles || []).forEach(function (style) { $('<style>').text(style).appendTo(shell); });
                    (payload.scripts || []).forEach(function (script) {
                        // A failing widget initializer must not turn the
                        // successfully loaded form into an error toast.
                        try {
                            Function('jQuery', '$', script).call(root, $, $);
                        } catch (e) {
                            window.console && window.console.error('Admin action form script failed:', e);
                        }
                    });
                    shell.modal('show');
                } else if (payload.mode === 'dialog') {
                    showDialog(current, payload.settings, sequence);
                }
            });
        }).catch(function (error) {
            if (error.statusText !== 'abort' && state.current(sequence)) {
                notify(error);
                if (shell) shell.find('.modal-body').empty().text('Unable to load action. Close and retry.');
            }
        });
    }
    $(root.document).on('click.happyAdminAction', '[data-admin-action]', function (event) { event.preventDefault(); open($(this)); });
    api.open = open;
    return api;
}));
