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

NProgress.configure({parent: '#app'});

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

    $.admin.reload = function () {
        window.location.reload();
    };

    $.admin.redirect = function (url) {
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
                window.location.href = navigate;
            } else {
                window.location.reload();
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
