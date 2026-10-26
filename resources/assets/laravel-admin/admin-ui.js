/*
 * Admin UI helpers for the AdminLTE 4 frontend.
 *
 * This is intentionally NOT a jQuery plugin bridge: it does not add
 * $.fn.modal / $.fn.popover / $.fn.tab back to jQuery. Package code calls
 * these helpers, or the Bootstrap 5 API directly.
 */
(function (window) {
    'use strict';

    if (!window.bootstrap) {
        return;
    }

    var AdminUI = {};

    AdminUI.modal = function (element, action) {
        var instance = window.bootstrap.Modal.getOrCreateInstance(element);
        if (action === 'hide') {
            instance.hide();
        } else if (action === 'toggle') {
            instance.toggle();
        } else {
            instance.show();
        }
    };

    AdminUI.popover = function (element, options) {
        return window.bootstrap.Popover.getOrCreateInstance(element, options || {});
    };

    AdminUI.disposePopover = function (element) {
        var instance = window.bootstrap.Popover.getInstance(element);
        if (instance) {
            instance.dispose();
        }
    };

    AdminUI.tooltip = function (element, options) {
        return window.bootstrap.Tooltip.getOrCreateInstance(element, options || {});
    };

    AdminUI.tab = function (element) {
        window.bootstrap.Tab.getOrCreateInstance(element).show();
    };

    /**
     * Preserve the label/disabled state while a form is submitting.
     * Replaces Bootstrap 3's $(el).button('loading'|'reset').
     */
    AdminUI.setButtonLoading = function (button, loading) {
        var element = button && button.jquery ? button[0] : button;
        if (!element) {
            return;
        }

        if (loading) {
            if (element.dataset.adminLoading === '1') {
                return;
            }
            element.dataset.adminLoading = '1';
            element.dataset.adminLoadingHtml = element.innerHTML;
            element.disabled = true;
            element.innerHTML = element.dataset.loadingText || 'Loading...';
            return;
        }

        if (element.dataset.adminLoading !== '1') {
            return;
        }
        element.disabled = false;
        element.innerHTML = element.dataset.adminLoadingHtml || element.innerHTML;
        delete element.dataset.adminLoading;
        delete element.dataset.adminLoadingHtml;
    };

    window.AdminUI = AdminUI;
})(window);
