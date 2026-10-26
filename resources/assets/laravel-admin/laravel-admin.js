$.fn.editable.defaults.params = function (params) {
    params._token = LA.token;
    params._editable = 1;
    params._method = 'PUT';
    return params;
};

$.fn.editable.defaults.error = function (data) {
    var msg = '';
    if (data.responseJSON.errors) {
        $.each(data.responseJSON.errors, function (k, v) {
            msg += v + "\n";
        });
    }
    return msg
};

toastr.options = {
    closeButton: true,
    progressBar: true,
    showMethod: 'slideDown',
    timeOut: 4000
};

// Expired admin sessions must re-authenticate in the top window, not inside iframes.
// 401 = guarded JSON request; 419 = CSRF token of a stale session.
$(document).ajaxError(function (event, xhr) {
    if (xhr.status !== 401 && xhr.status !== 419) {
        return;
    }

    var $login = $('meta[name="admin-login-url"]');
    var defaultMessage = xhr.status === 401
        ? ($login.attr('data-expired') || 'Your session has expired, please sign in again.')
        : ($login.attr('data-expired-page') || 'Your session has expired, please refresh the page.');
    var message = defaultMessage;

    // For 419 the Laravel exception body is unreliable outside debug mode.
    if (xhr.status === 401 && xhr.responseJSON && xhr.responseJSON.message) {
        message = xhr.responseJSON.message;
    }

    toastr.error(message);

    var loginUrl = $login.attr('content');
    if (!loginUrl) {
        return;
    }

    setTimeout(function () {
        try {
            (window.top || window).location.href = loginUrl;
        } catch (e) {
            window.location.href = loginUrl;
        }
    }, 800);
});

NProgress.configure({parent: 'body', showSpinner: false});

// Track foreground requests, including requests with global:false or a custom context.
(function ($) {
    var pending = 0;
    var timer = null;
    var indicator = null;
    var forms = [];

    function showIndicator() {
        if (indicator) {
            return;
        }
        indicator = $('<div class="admin-ajax-loading" role="status" aria-live="polite"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i><span></span></div>');
        indicator.find('span').text($('meta[name="admin-loading-text"]').attr('content') || 'Loading...');
        indicator.appendTo('body');
    }

    function start(immediate) {
        pending++;
        if (pending === 1) {
            NProgress.start();
            // Fast AJAX requests only need the progress bar.
            timer = setTimeout(function () {
                timer = null;
                showIndicator();
            }, 180);
        }

        if (immediate) {
            clearTimeout(timer);
            timer = null;
            showIndicator();
        }
    }

    function finish() {
        pending--;
        if (pending !== 0) {
            return;
        }

        clearTimeout(timer);
        timer = null;
        if (indicator) {
            indicator.remove();
            indicator = null;
        }
        NProgress.done();
    }

    function restoreForm(record) {
        var index = forms.indexOf(record);
        if (index === -1) {
            return;
        }
        forms.splice(index, 1);
        record.buttons.forEach(function (button) {
            button.element.disabled = button.disabled;
            if (button.input) {
                button.element.value = button.content;
            } else {
                button.element.innerHTML = button.content;
            }
        });
        finish();
    }

    function startFormLoading(form, event) {
        if (form.target && form.target !== '_self') {
            return true;
        }
        if (forms.some(function (record) { return record.form === form; })) {
            return false;
        }
        var record = {form: form, buttons: []};
        forms.push(record);
        start(true);
        // Let the browser collect the submitter's name/value before disabling it.
        setTimeout(function () {
            if (forms.indexOf(record) === -1) {
                return;
            }
            if (event && event.isDefaultPrevented()) {
                restoreForm(record);
                return;
            }
            var label = $('meta[name="admin-loading-text"]').attr('data-submitting') || 'Submitting...';
            $(form).find(':submit, input[type=image]').each(function () {
                if (this.disabled) {
                    return;
                }
                var input = this.tagName === 'INPUT';
                record.buttons.push({element: this, input: input, disabled: this.disabled, content: input ? this.value : this.innerHTML});
                this.disabled = true;
                if (input) {
                    this.value = label;
                } else {
                    this.textContent = label;
                }
            });
        }, 0);
        return true;
    }

    // Native form.submit() does not dispatch a submit event (used after widget confirmation).
    LA.startFormLoading = startFormLoading;
    LA.stopFormLoading = function (form) {
        forms.slice().forEach(function (record) {
            if (record.form === form) {
                restoreForm(record);
            }
        });
    };
    LA.startLoading = function () {
        var active = true;
        start();
        return function () {
            if (active) {
                active = false;
                finish();
            }
        };
    };
    $(document).on('submit.adminLoading', 'form[data-admin-form]', function (event) {
        if (event.isDefaultPrevented() || (this.target && this.target !== '_self')) {
            return;
        }
        if (LA.canSubmitForm && LA.canSubmitForm(this)) {
            return;
        }
        if (!startFormLoading(this, event)) {
            event.preventDefault();
        }
    });
    // Restore controls when the browser returns to a page from its back/forward cache.
    $(window).on('pageshow.adminLoading', function () {
        forms.slice().forEach(restoreForm);
    });

    $.ajaxPrefilter(function (options, originalOptions, xhr) {
        if (options.adminLoading === false) {
            return;
        }

        var beforeSend = options.beforeSend;
        var started = false;
        options.beforeSend = function () {
            var result = beforeSend && beforeSend.apply(this, arguments);
            if (result === false || xhr.state() !== 'pending') {
                return false;
            }
            started = true;
            start();
            return result;
        };
        xhr.always(function () {
            if (started) {
                started = false;
                finish();
            }
        });
    });
})(jQuery);

$(document).click(function () {
    $('.sidebar-form .dropdown-menu').hide();
});

$(function () {
    $('.sidebar-menu li:not(.treeview) > a').on('click', function () {
        var $parent = $(this).parent().addClass('active');
        $parent.siblings('.treeview.active').find('> a').trigger('click');
        $parent.siblings().removeClass('active').find('li').removeClass('active');
    });
    var menu = $('.sidebar-menu li > a[href$="' + (location.pathname + location.search + location.hash) + '"]').parent().addClass('active');
    menu.parents('ul.treeview-menu').addClass('menu-open');
    menu.parents('li.treeview').addClass('active');

    $('[data-toggle="popover"]').popover();

    // Sidebar form autocomplete
    $('.sidebar-form .autocomplete').on('keyup focus', function () {
        var $menu = $('.sidebar-form .dropdown-menu');
        var text = $(this).val();

        if (text === '') {
            $menu.hide();
            return;
        }

        var regex = new RegExp(text, 'i');
        var matched = false;

        $menu.find('li').each(function () {
            if (!regex.test($(this).find('a').text())) {
                $(this).hide();
            } else {
                $(this).show();
                matched = true;
            }
        });

        if (matched) {
            $menu.show();
        }
    }).click(function(event){
        event.stopPropagation();
    });

    $('.sidebar-form .dropdown-menu li a').click(function (){
        $('.sidebar-form .autocomplete').val($(this).text());
    });
});

$(window).scroll(function() {
    if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
        $('#totop').fadeIn(500);
    } else {
        $('#totop').fadeOut(500);
    }
});

$('#totop').on('click', function (e) {
    e.preventDefault();
    $('html,body').animate({scrollTop: 0}, 500);
});

(function ($) {

    var Grid = function () {
        this.selects = {};
    };

    Grid.prototype.select = function (id) {
        this.selects[id] = id;
    };

    Grid.prototype.unselect = function (id) {
        delete this.selects[id];
    };

    Grid.prototype.selected = function () {
        var rows = [];
        $.each(this.selects, function (key, val) {
            rows.push(key);
        });

        return rows;
    };

    $.fn.admin = LA;
    $.admin = LA;
    $.admin.swal = swal;
    $.admin.toastr = toastr;
    $.admin.grid = new Grid();

    $.admin.isResponseError = function (data) {
        return !!data && (data.status === false || data.success === false);
    };

    $.admin.responseMessage = function (data, fallback) {
        return (data && (data.message || data.msg)) || fallback;
    };

    $.admin.reload = function () {
        if ($.admin.navigate) {
            return $.admin.navigate(window.location.href, {replace: true});
        }
        window.location.reload();
    };

    $.admin.redirect = function (url) {
        if ($.admin.navigate) {
            return $.admin.navigate(url);
        }
        window.location.href = url;
    };

    // Show the confirmation message before navigating away, otherwise the
    // toast is destroyed by the reload/redirect that immediately follows.
    $.admin.notifyThenNavigate = function (message, type, navigate) {
        var delay = 0;

        if (message) {
            if (type === 'error' || type === 'warning') {
                $.admin.toastr[type](message, '', {positionClass: 'toast-top-center', timeOut: 10000, extendedTimeOut: 10000});
            } else {
                $.admin.toastr[type || 'success'](message, '', {positionClass: 'toast-top-center'});
            }
            delay = 700;
        }

        setTimeout(function () {
            if (navigate && navigate !== 'reload') {
                $.admin.redirect(navigate);
            } else {
                $.admin.reload();
            }
        }, delay);
    };

    $.admin.getToken = function () {
        return $('meta[name="csrf-token"]').attr('content');
    };

    $.admin.loadedScripts = [];

    $.admin.loadScripts = function(arr) {
        var _arr = $.map(arr, function(src) {

            if ($.inArray(src, $.admin.loadedScripts)) {
                return;
            }

            $.admin.loadedScripts.push(src);

            return $.getScript(src);
        });

        _arr.push($.Deferred(function(deferred){
            $(deferred.resolve);
        }));

        return $.when.apply($, _arr);
    }

})(jQuery);
