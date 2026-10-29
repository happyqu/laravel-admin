(function (window, $) {
    'use strict';
    if (!$ || !window.flatpickr || ($.admin && $.admin.datepicker)) return;

    var flatpickr = window.flatpickr;
    // Avoid timezone shims that offset the multi-argument Date constructor.
    // Keep local calendar semantics (including DST) without a fixed UTC offset.
    function localDate(year, month, day, hours, minutes, seconds, milliseconds) {
        var date = new Date(0);
        var integerYear = Number(year) < 0 ? Math.ceil(Number(year)) : Math.floor(Number(year));
        date.setHours(0, 0, 0, 0);
        date.setFullYear(integerYear >= 0 && integerYear <= 99 ? 1900 + integerYear : Number(year), month,
            arguments.length > 2 ? day : 1);
        date.setHours(arguments.length > 3 ? hours : 0, arguments.length > 4 ? minutes : 0,
            arguments.length > 5 ? seconds : 0, arguments.length > 6 ? milliseconds : 0);
        return date;
    }

    var document = window.document;
    var live = new Set();
    var nextId = 0;
    var tokens = { YYYY: 'Y', YY: 'y', MMMM: 'F', MMM: 'M', MM: 'm', M: 'n', DD: 'd', D: 'j',
        dddd: 'l', ddd: 'D', d: 'w', HH: 'H', hh: 'G', h: 'h', mm: 'i',
        ss: 'S', s: 's', A: 'K', X: 'U' };
    var tokenPattern = /\[[^\]]*\]|\\.|YYYY|MMMM|dddd|MMM|ddd|YY|MM|DD|HH|hh|mm|ss|[YMDdHhmsAaX]|./g;

    function convertFormat(format) {
        return String(format || 'YYYY-MM-DD').match(tokenPattern).map(function (part) {
            if (part[0] === '[') return part.slice(1, -1).split('').map(function (c) { return '\\' + c; }).join('');
            if (part[0] === '\\') return part;
            if (tokens[part]) return tokens[part];
            if (/[A-Za-z]/.test(part)) throw new Error('Unsupported date format token: ' + part);
            return part;
        }).join('');
    }

    function localeFor(name) {
        if (name && typeof name === 'object') return name;
        var code = String(name || 'en').toLowerCase().replace(/-/g, '_');
        var aliases = { zh_cn: 'zh', zh_hans: 'zh', zh_hant: 'zh_tw', zh_hk: 'zh_tw', pt_br: 'pt', vi: 'vn' };
        code = aliases[code] || code;
        var locale = flatpickr.l10ns[code] || flatpickr.l10ns[code.split('_')[0]];
        if (locale) {
            // Match the Bootstrap calendar: Monday first and compact Chinese weekdays.
            if (/^zh(?:_|$)/.test(code)) return $.extend({}, locale, {
                firstDayOfWeek: 1,
                weekdays: $.extend({}, locale.weekdays, { shorthand: ['日', '一', '二', '三', '四', '五', '六'] })
            });
            return locale;
        }
        console.warn('Unknown datepicker locale, using English: ' + name);
        return flatpickr.l10ns.en;
    }

    function parse(value, format, locale, bound) {
        if (value === false || value === null || value === undefined || value === '') return null;
        if (value && typeof value.toDate === 'function') value = value.toDate();
        if (value instanceof Date) return isNaN(value.getTime()) ? null : new Date(value.getTime());
        if (typeof value === 'number') return isNaN(new Date(value).getTime()) ? null : new Date(value);
        if (bound && (value === 'now' || value === 'today')) return new Date();
        if (typeof value !== 'string') return null;
        function matches(text, pattern) {
            var digits = { Y: '\\d{4}', y: '\\d{2}', m: '\\d{2}', n: '\\d{1,2}', d: '\\d{2}', j: '\\d{1,2}',
                H: '\\d{2}', h: '\\d{1,2}', G: '\\d{2}', i: '\\d{2}', S: '\\d{2}', s: '\\d{1,2}', U: '-?\\d+', w: '[0-6]' };
            var regex = '';
            for (var i = 0; i < pattern.length; i++) {
                var c = pattern[i];
                if (c === '\\') regex += pattern[++i].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                else regex += digits[c] || (/[FMlDK]/.test(c) ? '.+?' : c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
            }
            return new RegExp('^' + regex + '$').test(text);
        }
        // Flatpickr's parser rolls impossible dates forward. Verify the formatted result too.
        var date = matches(value, format) ? flatpickr.parseDate(value, format, false, locale) : null;
        if (date && flatpickr.formatDate(date, format, locale) === value) return date;
        if (bound && /^\d{4}-\d\d-\d\d(?:[ T]\d\d:\d\d(?::\d\d)?)?$/.test(value)) {
            var isoFormat = value.length === 10 ? 'Y-m-d' : 'Y-m-d' + (value[10] === 'T' ? '\\T' : ' ') + 'H:i' + (value.length === 19 ? ':S' : '');
            date = flatpickr.parseDate(value, isoFormat, false, locale);
            if (date && flatpickr.formatDate(date, isoFormat, locale) === value) return date;
        }
        return null;
    }

    function DateValue(date, format, locale) {
        this.date = new Date(date.getTime());
        this.formatString = format;
        this.locale = locale;
    }
    DateValue.prototype.toDate = function () { return new Date(this.date.getTime()); };
    DateValue.prototype.valueOf = function () { return this.date.getTime(); };
    DateValue.prototype.clone = function () { return new DateValue(this.date, this.formatString, this.locale); };
    DateValue.prototype.isValid = function () { return !isNaN(this.date.getTime()); };
    DateValue.prototype.format = function (format) { return flatpickr.formatDate(this.date, format ? convertFormat(format) : this.formatString, this.locale); };

    function inputOf(node) {
        return $(node).is('input') ? node : $(node).find('input').first()[0];
    }

    // Bootstrap datetimepicker-style drill-up navigation, backed by Flatpickr's date handling.
    function calendarNavigation(instance) {
        if (instance.config.noCalendar || instance.config.showMonths !== 1) return;
        var calendar = instance.calendarContainer;
        var mode = 'days', viewYear = instance.currentYear, firstYear = viewYear - 5;
        calendar.classList.add('admin-datepicker-tiered');
        var header = document.createElement('div');
        header.className = 'admin-datepicker-navigation';
        function button(className, label) {
            var el = document.createElement('button');
            el.type = 'button'; el.className = className; el.textContent = label;
            return el;
        }
        var prev = button('admin-datepicker-prev', '');
        var title = button('admin-datepicker-title', '');
        var next = button('admin-datepicker-next', '');
        prev.innerHTML = instance.config.prevArrow;
        next.innerHTML = instance.config.nextArrow;
        header.appendChild(prev); header.appendChild(title); header.appendChild(next);
        calendar.insertBefore(header, instance.monthNav);
        var panel = document.createElement('div');
        panel.className = 'admin-datepicker-periods';
        panel.id = 'admin-datepicker-periods-' + (++nextId);
        panel.setAttribute('role', 'group');
        title.setAttribute('aria-controls', panel.id);
        calendar.insertBefore(panel, instance.innerContainer);
        var numericMonths = /月$/.test(instance.l10n.months.longhand[0]);
        function allowed(year, month) {
            var min = instance.config.minDate, max = instance.config.maxDate;
            var start = localDate(year, month === undefined ? 0 : month, 1);
            var end = localDate(year, month === undefined ? 12 : month + 1, 0, 23, 59, 59, 999);
            return year >= 1 && year <= 9999 && !(min && end < min) && !(max && start > max);
        }
        function canMove(step) {
            if (mode === 'days') {
                var date = localDate(instance.currentYear, instance.currentMonth + step, 1);
                return allowed(date.getFullYear(), date.getMonth());
            }
            if (mode === 'months') return allowed(viewYear + step);
            var start = firstYear + step * 12;
            for (var year = start; year < start + 12; year++) if (allowed(year)) return true;
            return false;
        }
        function render() {
            calendar.setAttribute('data-admin-calendar-view', mode);
            panel.hidden = mode === 'days';
            title.setAttribute('aria-expanded', String(mode !== 'days'));
            title.textContent = mode === 'days'
                ? instance.l10n.months.longhand[instance.currentMonth] + ' ' + instance.currentYear
                : mode === 'months' ? String(viewYear) : firstYear + '-' + (firstYear + 11);
            panel.setAttribute('aria-label', mode === 'months' ? instance.l10n.monthAriaLabel : instance.l10n.yearAriaLabel);
            prev.setAttribute('aria-label', mode === 'days' ? 'Previous month' : mode === 'months' ? 'Previous year' : 'Previous years');
            next.setAttribute('aria-label', mode === 'days' ? 'Next month' : mode === 'months' ? 'Next year' : 'Next years');
            prev.disabled = !canMove(-1); next.disabled = !canMove(1);
            panel.textContent = '';
            if (mode === 'days') return;
            for (var index = 0; index < 12; index++) {
                (function (value) {
                    var monthView = mode === 'months';
                    var year = monthView ? viewYear : firstYear + value;
                    var item = button('admin-datepicker-period', monthView
                        ? (numericMonths ? (value + 1) + '月' : instance.l10n.months.shorthand[value]) : String(year));
                    item.disabled = !allowed(year, monthView ? value : undefined);
                    var selected = year === instance.currentYear && (!monthView || value === instance.currentMonth);
                    item.setAttribute('aria-pressed', String(selected));
                    item.addEventListener('click', function () {
                        if (monthView) {
                            instance.jumpToDate(localDate(viewYear, value, 1), true);
                            mode = 'days';
                        } else {
                            viewYear = year;
                            mode = 'months';
                        }
                        render(); title.focus();
                    });
                    panel.appendChild(item);
                }(index));
            }
        }
        title.addEventListener('click', function () {
            if (mode === 'days') { mode = 'months'; viewYear = instance.currentYear; }
            else if (mode === 'months') { mode = 'years'; firstYear = viewYear - 5; }
            render();
        });
        function move(step) {
            if (!canMove(step)) return;
            if (mode === 'days') instance.changeMonth(step);
            else if (mode === 'months') viewYear += step;
            else firstYear += step * 12;
            render();
        }
        prev.addEventListener('click', function () { move(-1); });
        next.addEventListener('click', function () { move(1); });
        header.addEventListener('keydown', function (event) {
            // Keep Flatpickr's day-grid keyboard handler away from the navigation buttons.
            event.stopPropagation();
            if (event.key === 'Escape') {
                event.preventDefault();
                if (mode === 'days') instance.close();
                else { mode = mode === 'years' ? 'months' : 'days'; render(); title.focus(); }
            }
        });
        panel.addEventListener('keydown', function (event) {
            event.stopPropagation();
            if (event.key === 'Escape') {
                event.preventDefault(); mode = mode === 'years' ? 'months' : 'days'; render(); title.focus(); return;
            }
            var offset = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -4, ArrowDown: 4 }[event.key];
            if (!offset && event.key !== 'Home' && event.key !== 'End') return;
            event.preventDefault();
            var items = Array.from(panel.children), index = items.indexOf(document.activeElement);
            var target = event.key === 'Home' ? 0 : event.key === 'End' ? 11 : index + offset;
            while (target >= 0 && target < 12 && items[target].disabled) target += offset < 0 || event.key === 'End' ? -1 : 1;
            if (items[target]) items[target].focus();
        });
        instance.config.onClose.push(function () { mode = 'days'; render(); });
        instance.config.onOpen.push(function () { mode = 'days'; render(); });
        instance.config.onMonthChange.push(render);
        instance.config.onYearChange.push(render);
        instance.config.onValueUpdate.push(render);
        // Silent jumpToDate/setDate/clear calls still rebuild the day grid.
        // Keep the custom header in that same redraw, even without change hooks.
        var redraw = instance.redraw;
        instance.redraw = function () {
            var result = redraw.apply(instance, arguments);
            render();
            return result;
        };
        instance.config.onDestroy.push(function () { instance.redraw = redraw; });
        render();
    }

    function init(node, supplied) {
        var input = inputOf(node);
        if (!input) return null;
        input.setAttribute('autocomplete', 'off');
        var existing = $(input).data('adminDatepicker');
        if (existing) existing.destroy();
        else if (input._flatpickr) input._flatpickr.destroy();
        var options = $.extend({}, supplied);
        var locked = input.disabled || input.readOnly;
        var nativeFormat = options.dateFormat || convertFormat(options.format);
        var locale = localeFor(options.locale);
        var unescaped = nativeFormat.replace(/\\./g, '');
        var hasTime = /[HhGgiSsK]/.test(unescaped);
        var hasDate = /[YymndjFMlDwWU]/.test(unescaped);
        var invalid = null;
        var parseRejected = false;
        var rawValue = input.value;
        var previous = null;
        var fp;
        var id = ++nextId;
        var host = $(node);
        var group = $(input).closest('.input-group');
        var baseMin = options.minDate;
        var baseMax = options.maxDate;
        var cleanup = [];
        var destroyed = false;
        var ignored = ['allowInputToggle', 'useCurrent', 'useStrict', 'sideBySide', 'icons', 'tooltips', 'widgetPositioning'];
        var unsupported = ['timeZone', 'disabledTimeIntervals', 'enabledHours', 'disabledHours', 'viewMode', 'toolbarPlacement', 'extraFormats', 'parseInputDate'];
        unsupported.forEach(function (key) {
            if (options[key] !== undefined && options[key] !== false && options[key] !== '') {
                throw new Error('Datepicker option ' + key + ' is not supported; migrate it to Flatpickr options.');
            }
        });

        function parseInput(value, format) {
            if (typeof options.parseDate !== 'function') return parse(value, format, locale, false);
            var date = options.parseDate(value, format);
            return date && typeof date.getTime === 'function' && !isNaN(date.getTime()) ? new Date(date.getTime()) : null;
        }

        function bound(value) {
            var result = parse(value, nativeFormat, locale, true);
            if (!result && value !== undefined && value !== null && value !== false && value !== '') throw new Error('Invalid datepicker boundary: ' + value);
            return result;
        }
        baseMin = bound(baseMin);
        baseMax = bound(baseMax);
        var config = $.extend({}, options, {
            dateFormat: nativeFormat, locale: locale,
            enableTime: options.enableTime === undefined ? hasTime : options.enableTime,
            noCalendar: options.noCalendar === undefined ? !hasDate : options.noCalendar,
            enableSeconds: options.enableSeconds === undefined ? /[Ss]/.test(unescaped) : options.enableSeconds,
            time_24hr: options.time_24hr === undefined ? !/K/.test(unescaped) : options.time_24hr,
            minuteIncrement: options.stepping || options.minuteIncrement || 1,
            defaultHour: options.defaultHour === undefined ? 0 : options.defaultHour,
            defaultMinute: options.defaultMinute === undefined ? 0 : options.defaultMinute,
            defaultSeconds: options.defaultSeconds === undefined ? 0 : options.defaultSeconds,
            allowInput: options.allowInput === undefined ? true : options.allowInput,
            clickOpens: locked ? false : options.clickOpens !== false,
            allowInvalidPreload: false, disableMobile: true,
            errorHandler: function (error) {
                if (!invalid) (options.errorHandler || console.warn)(error);
            },
            minDate: baseMin || undefined, maxDate: baseMax || undefined,
            monthSelectorType: 'static',
            parseDate: function (text, format) {
                var result = parseInput(text, format);
                if (!result && text) {
                    invalid = rawValue || String(text);
                    parseRejected = true;
                }
                return result || undefined;
            },
            onChange: [function (dates, text) {
                // Reject only the failed parse that caused this change. A subsequent calendar
                // selection is a new valid edit, even when the previous input was invalid.
                if (parseRejected) { parseRejected = false; restoreInvalid(); return; }
                invalid = null;
                rawValue = text;
                input.setCustomValidity('');
                var date = dates.length ? new DateValue(dates[0], nativeFormat, locale) : false;
                var event = $.Event('dp.change', { date: date, oldDate: previous });
                previous = date;
                host.trigger(event);
            }].concat(options.onChange || []),
            onOpen: [function () { host.trigger('dp.show'); }].concat(options.onOpen || []),
            onClose: [function () { host.trigger('dp.hide'); }].concat(options.onClose || []),
            onReady: [function (dates, text, instance) {
                if (dates.length) previous = new DateValue(dates[0], nativeFormat, locale);
                restoreInvalid();
                parseRejected = false;
                var calendar = instance.calendarContainer;
                calendar.classList.add('admin-datepicker');
                calendarNavigation(instance);
                // Bootstrap dropdowns and X-Editable must not treat calendar interactions as outside clicks.
                calendar.addEventListener('click', function (event) { event.stopPropagation(); });
                if (options.showClear || options.showTodayButton || options.showClose || options.mode === 'multiple') {
                    var footer = document.createElement('div');
                    footer.className = 'admin-datepicker-buttons';
                    var chinese = /^zh/i.test(String(options.locale));
                    function button(label, click) {
                        var el = document.createElement('button');
                        el.type = 'button'; el.className = 'btn btn-default btn-xs'; el.textContent = label;
                        el.addEventListener('click', click); footer.appendChild(el);
                    }
                    if (options.showTodayButton) button(chinese ? '今天' : 'Today', function () { controller.date(new Date()); });
                    if (options.showClear || options.mode === 'multiple') button(chinese ? '清空' : 'Clear', function () { controller.clear(); instance.close(); });
                    if (options.showClose) button(chinese ? '关闭' : 'Close', function () { instance.close(); });
                    calendar.appendChild(footer);
                }
            }].concat(options.onReady || [])
        });
        function restoreInvalid() {
            if (invalid) { input.value = invalid; input.setCustomValidity('Invalid date'); }
        }
        if (options.calendarWeeks !== undefined) config.weekNumbers = options.calendarWeeks;
        if (options.date !== undefined) config.defaultDate = options.date;
        if (options.defaultDate === false) delete config.defaultDate;
        if (options.widgetParent) config.appendTo = $(options.widgetParent)[0];
        // Keep the calendar inside Bootstrap's focus trap, including nested Action dialogs.
        if (!config.appendTo && !config.inline) config.appendTo = $(input).closest('.modal')[0] || undefined;
        if (config.inline) config.appendTo = input.parentNode;
        var disable = [].concat(config.disable || []);
        if (options.disabledDates) disable = disable.concat(options.disabledDates.map(function (value) {
            return bound(value);
        }));
        if (options.daysOfWeekDisabled) disable.push(function (date) { return options.daysOfWeekDisabled.indexOf(date.getDay()) !== -1; });
        if (disable.length) config.disable = disable;
        if (options.enabledDates) config.enable = options.enabledDates.map(function (value) { return bound(value); });
        ignored.concat(unsupported, ['format', 'date', 'stepping', 'calendarWeeks', 'widgetParent', 'disabledDates', 'enabledDates', 'daysOfWeekDisabled', 'showClear', 'showTodayButton', 'showClose']).forEach(function (key) { delete config[key]; });

        var controller = {
            input: input, baseMin: baseMin, baseMax: baseMax,
            show: function () { if (!locked) fp.open(); return controller; },
            hide: function () { fp.close(); return controller; },
            clear: function () { invalid = null; rawValue = ''; input.setCustomValidity(''); fp.clear(); return controller; },
            date: function (value) {
                if (!arguments.length) return fp.selectedDates.length ? new DateValue(fp.selectedDates[0], nativeFormat, locale) : null;
                if (value === false || value === null || value === '') return controller.clear();
                invalid = null; rawValue = typeof value === 'string' ? value : '';
                if (Array.isArray(value)) value = window.Array.from(value);
                fp.setDate(value && typeof value.toDate === 'function' ? value.toDate() : value, true);
                return controller;
            },
            minDate: function (value) { if (!arguments.length) return fp.config.minDate || false; fp.set('minDate', bound(value) || undefined); return controller; },
            maxDate: function (value) { if (!arguments.length) return fp.config.maxDate || false; fp.set('maxDate', bound(value) || undefined); return controller; },
            destroy: function () {
                if (destroyed) return;
                destroyed = true;
                cleanup.forEach(function (fn) { fn(); });
                $(input).off('.adminDatepicker').removeData('adminDatepicker').removeData('DateTimePicker');
                if (node !== input) host.removeData('DateTimePicker').removeData('adminDatepicker');
                input.setCustomValidity('');
                if (fp) fp.destroy();
                live.delete(controller);
            },
            cleanup: cleanup
        };
        $(input).on('input.adminDatepicker', function () {
            // Flatpickr also dispatches input after onChange; keep the restored invalid value invalid.
            if (invalid && input.value === invalid) return;
            rawValue = input.value;
            var values = options.mode === 'multiple' ? rawValue.split(options.conjunction || ', ')
                : options.mode === 'range' ? rawValue.split(locale.rangeSeparator) : [rawValue];
            invalid = rawValue && values.some(function (value) { return !parseInput(value, nativeFormat); }) ? rawValue : null;
            input.setCustomValidity(invalid ? 'Invalid date' : '');
        });
        fp = flatpickr(input, config);
        if (!fp || !fp.config) throw new Error('Unable to initialize datepicker.');
        if (fp.altInput) fp.altInput.setAttribute('autocomplete', 'off');
        controller.instance = fp;
        controller.id = id;
        $(input).data('adminDatepicker', controller).data('DateTimePicker', controller);
        if (node !== input) host.data('DateTimePicker', controller).data('adminDatepicker', controller);
        if (group.length) {
            var ns = '.adminDatepicker' + id;
            group.on('click' + ns, '.input-group-addon,.input-group-btn', function (event) { event.preventDefault(); controller.show(); });
            cleanup.push(function () { group.off(ns); });
        }
        live.add(controller);
        return controller;
    }

    function range(start, end, options) {
        var starts = $(start), ends = $(end);
        starts.each(function (index) {
            if (!ends[index]) return;
            var a = init(this, options), b = init(ends[index], $.extend({}, options, { useCurrent: false }));
            var ns = '.adminDateRange' + a.id;
            function update() {
                var from = a.date(), to = b.date();
                var min = from ? from.toDate() : null, max = to ? to.toDate() : null;
                if (a.baseMax && (!max || max > a.baseMax)) max = a.baseMax;
                if (b.baseMin && (!min || min < b.baseMin)) min = b.baseMin;
                // Setting a boundary may clear an incompatible date. Change events only fire for user edits.
                a.maxDate(max); b.minDate(min);
            }
            $(a.input).add(b.input).on('change' + ns, update);
            var unlink = function () { $(a.input).add(b.input).off(ns); };
            a.cleanup.push(unlink); b.cleanup.push(unlink);
            update();
        });
    }

    function destroy(root) {
        live.forEach(function (controller) {
            if (!root || root === controller.input || $.contains(root, controller.input)) controller.destroy();
        });
    }

    $.admin = $.admin || {};
    $.admin.datepicker = { init: init, range: range, destroy: destroy, convertFormat: convertFormat };
    $.fn.adminDatepicker = function (options) { return this.each(function () { init(this, options); }); };
    // Limited transition API for existing host scripts. Event dates expose format()/toDate(), not Moment.
    $.fn.datetimepicker = $.fn.adminDatepicker;
    $(window).on('admin:before-navigate.adminDatepicker', function () { destroy(document.getElementById('admin-content')); });
    $(document).on('hidden.bs.modal.adminDatepicker', '.modal', function () {
        if ($(this).is('[data-admin-action-shell]')) destroy(this);
        else {
            var modal = this;
            live.forEach(function (controller) { if ($.contains(modal, controller.input)) controller.hide(); });
        }
    });
    var observer = new MutationObserver(function () {
        if (!window.document || !document.documentElement) { observer.disconnect(); return; }
        live.forEach(function (controller) { if (!document.documentElement.contains(controller.input)) controller.destroy(); });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });

    // X-Editable's bundled combodate input depends on Moment. Route its date types through Flatpickr.
    if ($.fn.editabletypes && $.fn.editabletypes.text) {
        var Text = $.fn.editabletypes.text;
        var DateInput = function (options) { this.init('adminDate', options, DateInput.defaults); };
        $.fn.editableutils.inherit(DateInput, Text);
        $.extend(DateInput.prototype, {
            render: function () {
                this.setClass();
                var options = $.extend({}, this.options.datepicker, { format: this.options.format, locale: this.options.locale, showClear: true, inline: true });
                var combo = this.options.combodate || {};
                if (/Y/.test(convertFormat(options.format))) {
                    if (combo.minYear && !options.minDate) options.minDate = String(combo.minYear) + '-01-01';
                    if (combo.maxYear && !options.maxDate) options.maxDate = String(combo.maxYear) + '-12-31';
                }
                this.$input.adminDatepicker(options);
            },
            value2input: function (value) { this.$input.data('adminDatepicker').date(value || null); },
            input2value: function () { return this.$input.val(); },
            destroy: function () { var picker = this.$input.data('adminDatepicker'); if (picker) picker.destroy(); }
        });
        DateInput.defaults = $.extend({}, Text.defaults, { format: 'YYYY-MM-DD', locale: 'en', clear: false, combodate: null, datepicker: null });
        $.fn.editabletypes.adminDate = DateInput;
        $.fn.editabletypes.combodate = DateInput;
    }
})(window, window.jQuery);
