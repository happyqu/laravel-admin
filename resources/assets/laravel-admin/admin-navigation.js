(function ($) {
    'use strict';

    var sequence = 0;
    var currentRead = null;
    var scrollTimer = null;
    var renderedUrl = window.location.href;
    var scripts = {};
    var styles = {};

    function absolute(url, base) { return new URL(url, base || window.location.href).href; }
    function local(url) {
        var parsed = new URL(url, window.location.href);
        return /^https?:$/.test(parsed.protocol) && parsed.origin === window.location.origin;
    }
    function error(message) {
        $.admin.toastr.error(message || $('meta[name="admin-loading-text"]').attr('data-failed') || 'Unable to complete this request.');
    }
    function state(scroll) {
        return $.extend({}, window.history.state, {adminNavigation: true, scrollY: scroll});
    }
    function rememberScroll() {
        if (window.location.href === renderedUrl) {
            window.history.replaceState(state(window.pageYOffset), '', renderedUrl);
        }
    }

    $('script[src]').each(function () { scripts[this.src] = Promise.resolve(); });
    $('link[rel="stylesheet"]').each(function () { styles[this.href] = true; });
    window.history.replaceState(state(window.pageYOffset), '', window.location.href);

    function loadScript(src, base) {
        src = absolute(src, base);
        if (!scripts[src] && Array.prototype.some.call(document.querySelectorAll('script[src]'), function (script) { return script.src === src; })) {
            scripts[src] = Promise.resolve();
        }
        if (!scripts[src]) {
            scripts[src] = Promise.resolve($.ajax({url: src, dataType: 'script', cache: true, adminLoading: false, global: false}))
                .catch(function (reason) { delete scripts[src]; throw reason; });
        }
        return scripts[src];
    }

    function render(html, url, options, id) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var content = parsed.getElementById('admin-content');
        if (!content || !document.getElementById('admin-content')) {
            // Authentication pages and other layouts need their own document.
            window.location.assign(url);
            return Promise.resolve(false);
        }
        var inline = [];
        var external = Array.prototype.slice.call(parsed.querySelectorAll('script[src]'));
        Array.prototype.slice.call(content.querySelectorAll('script')).forEach(function (script) {
            if (!script.src && (!script.type || /^(?:text|application)\/javascript$/.test(script.type))) {
                inline.push(script.textContent);
            }
            if (!script.type || /^(?:text|application)\/javascript$/.test(script.type)) {
                script.remove();
            }
        });
        var ready = Promise.resolve();
        external.forEach(function (script) {
            ready = ready.then(function () { return loadScript(script.getAttribute('src'), url); });
        });
        return ready.then(function () {
            if (id !== sequence) { return false; }
            parsed.querySelectorAll('link[rel="stylesheet"]').forEach(function (link) {
                var href = absolute(link.getAttribute('href'), url);
                if (!styles[href]) {
                    var copy = document.importNode(link, true);
                    copy.href = href;
                    document.head.appendChild(copy);
                    styles[href] = true;
                }
            });
            $(window).trigger('admin:before-navigate');
            $(document).off('.adminPage');
            $('body').off('.adminPage');
            $(window).off('.adminPage');
            $('#admin-content [data-toggle="popover"]').popover('destroy');
            $('#admin-content').replaceWith(document.importNode(content, true));
            var token = parsed.querySelector('meta[name="csrf-token"]');
            if (token) {
                $('meta[name="csrf-token"]').attr('content', token.content);
                $.admin.token = token.content;
            }
            document.title = parsed.title || document.title;
            $.admin.grid.selects = {};
            if (options.history !== false) {
                var nextState = {adminNavigation: true, scrollY: 0};
                if (options.replace || url === window.location.href) {
                    window.history.replaceState(nextState, '', url);
                } else {
                    window.history.pushState(nextState, '', url);
                }
            }
            renderedUrl = window.location.href;
            inline.forEach(function (code) { $.globalEval(code); });
            $('#admin-content [data-toggle="popover"]').popover();
            window.scrollTo(0, options.scroll || 0);
            $(document).trigger('admin:navigated', [url]);
            return true;
        });
    }

    function validation(form, payload) {
        if (!form) { error($.admin.responseMessage(payload)); return; }
        var $form = $(form);
        $form.find('.admin-ajax-error').remove();
        $form.find('.has-error').removeClass('has-error');
        var errors = payload.errors || payload.validation || {};
        var messages = [];
        Object.keys(errors).forEach(function (name) {
            var message = [].concat(errors[name]).join(' ');
            messages.push(message);
            var key = name.replace(/\.([^\.]+)/g, '[$1]');
            $form.find(':input').filter(function () { return this.name === name || this.name === key; }).first()
                .closest('.form-group').addClass('has-error')
                .append($('<span class="help-block admin-ajax-error"></span>').text(message));
        });
        var alert = $('<div class="alert alert-danger admin-ajax-error" role="alert" tabindex="-1"></div>').text(messages.join(' ') || $.admin.responseMessage(payload) || 'Validation failed.');
        $form.prepend(alert);
        alert[0].focus();
    }

    function send(url, settings, options) {
        options = options || {};
        var id = ++sequence;
        if (currentRead) { currentRead.abort(); currentRead = null; }
        rememberScroll();
        var finish = options.adminLoading === false ? function () {} : $.admin.startLoading();
        var transport;
        var xhr = $.ajax($.extend({}, settings, {
            url: url,
            dataType: 'html',
            adminLoading: options.adminLoading,
            headers: {'Accept': 'text/html', 'X-Admin-Navigation': 'true'},
            xhr: function () { transport = $.ajaxSettings.xhr(); return transport; }
        }));
        if (!settings.type || settings.type.toUpperCase() === 'GET') { currentRead = xhr; }
        var task = Promise.resolve(xhr).then(function (body) {
            if (id !== sequence) { return false; }
            var destination = transport && transport.responseURL ? transport.responseURL : url;
            if (!local(destination)) { window.location.assign(destination); return false; }
            var contentType = xhr.getResponseHeader('Content-Type') || '';
            if (/json/i.test(contentType)) {
                var payload = JSON.parse(body);
                if ($.admin.isResponseError(payload) || payload.errors || payload.validation) {
                    validation(options.form, payload);
                    return false;
                }
                if (payload.redirect && options.form && $.admin.afterFormSaved && $.admin.afterFormSaved(options.form, payload)) {
                    return true;
                }
                if ($.admin.responseMessage(payload)) { $.admin.toastr.success($.admin.responseMessage(payload)); }
                return $.admin.navigate(payload.redirect || payload.url || window.location.href, {replace: true});
            }
            return render(body, destination, options, id);
        }, function (failure) {
            if (id !== sequence || failure.statusText === 'abort') { return false; }
            if (failure.status === 401 || failure.status === 419) { return false; }
            var payload = failure.responseJSON;
            if (!payload && /json/i.test(failure.getResponseHeader('Content-Type') || '')) {
                try { payload = JSON.parse(failure.responseText); } catch (ignored) { /* Use the generic request error. */ }
            }
            if (failure.status === 422 && payload) {
                validation(options.form, payload);
            } else if (options.history === false) {
                window.location.assign(url);
            } else {
                error($.admin.responseMessage(payload));
            }
            return false;
        });
        return task.catch(function () {
            // Never retry a mutating request automatically.
            error();
            return false;
        }).then(function (result) {
            if (currentRead === xhr) { currentRead = null; }
            if (options.form) { $.admin.stopFormLoading(options.form); }
            finish();
            return result;
        });
    }

    $.admin.navigate = function (url, options) {
        url = absolute(url);
        if (!local(url)) { window.location.assign(url); return Promise.resolve(false); }
        return send(url, {type: 'GET'}, options);
    };
    $.admin.canSubmitForm = function (form) {
        var content = document.getElementById('admin-content');
        return !!content && content.contains(form) && form.getAttribute('data-admin-ajax') !== 'false'
            && (!form.target || form.target === '_self') && local(form.action)
            && /^(get|post)$/i.test(form.getAttribute('method') || 'get');
    };
    $.admin.submitForm = function (form, submitter) {
        if (!$.admin.canSubmitForm(form)) { return Promise.resolve(false); }
        if (!form.noValidate && !(submitter && submitter.formNoValidate) && !form.reportValidity()) {
            return Promise.resolve(false);
        }
        if (!$.admin.startFormLoading(form)) { return Promise.resolve(false); }
        var data = new FormData(form);
        if (submitter && submitter.name && !submitter.disabled) { data.append(submitter.name, submitter.value); }
        var url = new URL(form.action, window.location.href);
        var method = (form.getAttribute('method') || 'get').toUpperCase();
        if (method === 'GET') {
            url.search = '';
            data.forEach(function (value, name) { if (typeof value === 'string') { url.searchParams.append(name, value); } });
            return send(url.href, {type: 'GET'}, {form: form});
        }
        return send(url.href, {type: 'POST', data: data, processData: false, contentType: false}, {form: form});
    };

    $(document).on('click.adminNavigation', '#admin-content a[href]', function (event) {
        if (event.isDefaultPrevented() || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (event.which && event.which !== 1)) { return; }
        var href = this.getAttribute('href');
        if (!href || href.charAt(0) === '#' || this.hasAttribute('download') || (this.target && this.target !== '_self')
            || this.getAttribute('data-admin-ajax') === 'false' || !local(href)) { return; }
        var url = new URL(href, window.location.href);
        if (url.searchParams.has('_export_') || (url.hash && url.pathname === window.location.pathname && url.search === window.location.search)) { return; }
        event.preventDefault();
        $.admin.navigate(url.href);
    });
    $(document).on('submit.adminNavigation', '#admin-content form', function (event) {
        if (event.isDefaultPrevented() || !$.admin.canSubmitForm(this)) { return; }
        event.preventDefault();
        $.admin.submitForm(this, event.originalEvent && event.originalEvent.submitter);
    });
    $(window).on('popstate.adminNavigation', function (event) {
        clearTimeout(scrollTimer);
        var entry = event.originalEvent.state;
        if (entry && entry.adminNavigation) {
            $.admin.navigate(window.location.href, {history: false, scroll: entry.scrollY});
        } else if (new URL(renderedUrl).pathname !== window.location.pathname || new URL(renderedUrl).search !== window.location.search) {
            window.location.reload();
        }
    });
    $(window).on('scroll.adminNavigation', function () {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(rememberScroll, 100);
    });
})(jQuery);
