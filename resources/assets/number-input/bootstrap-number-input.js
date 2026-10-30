(function($) {

    // Run before native submission and delegated AJAX/confirmation handlers serialize the form.
    document.addEventListener('submit', function(event) {
        var form = event.target;
        var hasNumber = false;
        $(form.elements).each(function() {
            var normalize = $(this).data('bootstrapNumberNormalize');
            if (normalize) {
                hasNumber = true;
                normalize();
            }
        });
        var submitter = event.submitter;
        if (hasNumber && !form.noValidate && !(submitter && submitter.formNoValidate) && !form.reportValidity()) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    $.fn.bootstrapNumber = function(options) {

        var settings = $.extend({
            upClass: 'default',
            downClass: 'default',
            center: true
        }, options);

        return this.each(function(e) {
            var self = $(this);
            var clone = self.clone();

            function bound(name) {
                var value = clone.attr(name);
                return value != null && $.trim(value) !== '' && isFinite(Number(value)) ? Number(value) : null;
            }

            function number(value) {
                return /^[+-]?\d+$/.test($.trim(value)) && isFinite(Number(value)) ? Number(value) : null;
            }

            function locked() {
                return clone.prop('readonly') || clone[0].matches(':disabled');
            }

            function setText(n) {
                var min = bound('min');
                var max = bound('max');
                if (min !== null && n < min) {
                    n = min;
                } else if (max !== null && n > max) {
                    n = max;
                }
                clone.val(n);
            }

            function normalize() {
                if (locked()) return;
                var previous = clone.val();
                var n = number(previous);
                if (n === null) clone.val('');
                else setText(n);
                if (clone.val() !== previous) clone.trigger('change');
            }

            clone.data('bootstrapNumberNormalize', normalize);

            var group = $("<div class='input-group'></div>");
            var down = $("<button type='button'>-</button>").attr('class', 'btn btn-' + settings.downClass).click(function() {
                if (locked()) return;
                setText((number(clone.val()) || 0) - 1);
                clone.focus().trigger('change');
            });
            var up = $("<button type='button'>+</button>").attr('class', 'btn btn-' + settings.upClass).click(function() {
                if (locked()) return;
                setText((number(clone.val()) || 0) + 1);
                clone.focus().trigger('change');
            });
            $("<span class='input-group-btn'></span>").append(down).appendTo(group);
            clone.appendTo(group);
            if (clone) {
                clone.css('text-align', 'center');
            }
            $("<span class='input-group-btn'></span>").append(up).appendTo(group);

            // remove spins from original
            clone.prop('type', 'text').keydown(function(e) {
                // Implicit submission checks required fields before dispatching submit.
                if (e.keyCode === 13) normalize();
                if (e.ctrlKey || e.metaKey || $.inArray(e.keyCode, [46, 8, 9, 27, 13]) !== -1 ||
                    (e.keyCode >= 35 && e.keyCode <= 40) ||
                    (!e.shiftKey && $.inArray(e.keyCode, [109, 173, 189]) !== -1)) {
                    return;
                }
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            }).on('input', function() {
                if (locked()) return;
                // Keep empty values and intermediate edits (such as "-") until blur.
                clone.trigger('change');
            }).blur(normalize);

            self.replaceWith(group);

            function syncButtons() {
                down.add(up).prop('disabled', !!locked());
            }

            syncButtons();
            var observer = new MutationObserver(syncButtons);
            observer.observe(clone[0], { attributes: true, attributeFilter: ['readonly', 'disabled'] });
            clone.parents('fieldset').each(function() {
                observer.observe(this, { attributes: true, attributeFilter: ['disabled'] });
            });
        });
    };
}(jQuery));
