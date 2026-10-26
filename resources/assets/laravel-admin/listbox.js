(function (window) {
    'use strict';

    var instances = new WeakMap();
    var sequence = 0;

    function mount(source, settings) {
        if (instances.has(source)) return;
        var options = Object.assign({
            selectorMinimalHeight: 200, moveOnSelect: true, moveOnDoubleClick: true, showFilterInputs: true,
            filterPlaceHolder: 'Filter', filterTextClear: 'Clear',
            infoText: '{0}', infoTextEmpty: '0', infoTextFiltered: '{0} / {1}',
            nonSelectedListLabel: '', selectedListLabel: '', filterOnValues: false
        }, settings);
        var wrapper = document.createElement('div');
        wrapper.className = 'admin-listbox row g-3';
        var panels = [];
        var disposed = false;
        var originalHidden = source.hidden;
        var resetTimer;

        function element(tag, className, text) {
            var node = document.createElement(tag);
            node.className = className;
            if (text != null) node.textContent = text;
            return node;
        }

        function locked() { return source.matches(':disabled, [readonly]'); }
        function disabled(option) {
            return option.disabled || (option.parentElement.tagName === 'OPTGROUP' && option.parentElement.disabled);
        }

        function move(panel, all) {
            if (locked()) return;
            Array.from(panel.select.options).forEach(function (helper) {
                if ((all || helper.selected) && !helper.disabled) helper.sourceOption.selected = !panel.selected;
            });
            source.dispatchEvent(new Event('change', {bubbles: true}));
        }

        [false, true].forEach(function (selected) {
            var panel = {selected: selected};
            var column = element('div', 'col-sm-6');
            panel.select = element('select', 'form-select admin-listbox-select');
            panel.select.multiple = true;
            panel.select.id = 'admin-listbox-' + (++sequence);
            panel.select.setAttribute('aria-label', (source.getAttribute('aria-label') || source.name || 'Listbox') + (selected ? ' — selected' : ' — available'));
            var label = selected ? options.selectedListLabel : options.nonSelectedListLabel;
            if (label) {
                var heading = element('label', 'form-label', label);
                heading.htmlFor = panel.select.id;
                column.appendChild(heading);
            }
            panel.filter = element('input', 'form-control mb-2');
            panel.filter.type = 'search';
            panel.filter.placeholder = options.filterPlaceHolder;
            panel.filter.setAttribute('aria-label', panel.select.getAttribute('aria-label') + ' — ' + options.filterPlaceHolder);
            panel.filter.value = (selected ? options.selectedFilter : options.nonSelectedFilter) || '';
            panel.filter.hidden = !options.showFilterInputs;
            panel.filter.addEventListener('input', refresh);
            column.appendChild(panel.filter);
            var toolbar = element('div', 'd-flex gap-1 mb-2');
            [[selected ? '‹' : '›', false], [selected ? '«' : '»', true]].forEach(function (action) {
                var button = element('button', 'btn btn-outline-secondary flex-fill', action[0]);
                button.type = 'button';
                button.setAttribute('aria-label', (selected ? 'Remove' : 'Add') + (action[1] ? ' all filtered options' : ' selected options'));
                button.addEventListener('click', function () { move(panel, action[1]); });
                toolbar.appendChild(button);
            });
            column.appendChild(toolbar);
            panel.select.style.height = Math.max(0, Number(options.selectorMinimalHeight) || 200) + 'px';
            panel.select.addEventListener(options.moveOnSelect ? 'change' : 'dblclick', function () {
                if (options.moveOnSelect || options.moveOnDoubleClick) move(panel, false);
            });
            column.appendChild(panel.select);
            var footer = element('div', 'd-flex justify-content-between align-items-center mt-1');
            panel.info = element('small', 'text-body-secondary');
            panel.info.setAttribute('aria-live', 'polite');
            footer.appendChild(panel.info);
            panel.clear = element('button', 'btn btn-link btn-sm', options.filterTextClear);
            panel.clear.type = 'button';
            panel.clear.addEventListener('click', function () { panel.filter.value = ''; refresh(); });
            footer.appendChild(panel.clear);
            column.appendChild(footer);
            panel.column = column;
            panels.push(panel);
            wrapper.appendChild(column);
        });

        function refresh() {
            if (disposed) return;
            var groups = [[], []];
            Array.from(source.options).forEach(function (option) { groups[option.selected ? 1 : 0].push(option); });
            panels.forEach(function (panel, index) {
                var highlighted = new Set(Array.from(panel.select.selectedOptions).map(function (option) { return option.sourceOption; }));
                panel.select.replaceChildren();
                var query = panel.filter.value.toLocaleLowerCase();
                groups[index].forEach(function (option) {
                    var text = options.filterOnValues ? option.value : option.text;
                    if (query && text.toLocaleLowerCase().indexOf(query) === -1) return;
                    var helper = new Option(option.text, option.value, false, highlighted.has(option));
                    helper.disabled = disabled(option);
                    helper.sourceOption = option;
                    panel.select.appendChild(helper);
                });
                var count = panel.select.options.length;
                panel.info.textContent = (query ? options.infoTextFiltered : (count ? options.infoText : options.infoTextEmpty))
                    .replace(/\{0\}/g, count).replace(/\{1\}/g, groups[index].length);
                panel.clear.hidden = !query || !options.showFilterInputs;
                panel.column.querySelectorAll('input, select, button').forEach(function (control) { control.disabled = locked(); });
            });
        }

        function invalid(event) {
            event.preventDefault();
            var helper = panels[0].select;
            helper.setCustomValidity(source.validationMessage);
            helper.reportValidity();
            helper.setCustomValidity('');
        }
        function reset() { resetTimer = window.setTimeout(refresh, 0); }
        source.after(wrapper);
        source.hidden = true;
        source.addEventListener('change', refresh);
        source.addEventListener('invalid', invalid);
        var form = source.form;
        if (form) form.addEventListener('reset', reset);
        var observer = new MutationObserver(refresh);
        observer.observe(source, {childList: true, subtree: true, attributes: true, characterData: true});
        instances.set(source, {
            refresh: refresh,
            destroy: function () {
                disposed = true;
                observer.disconnect();
                window.clearTimeout(resetTimer);
                source.removeEventListener('change', refresh);
                source.removeEventListener('invalid', invalid);
                if (form) form.removeEventListener('reset', reset);
                wrapper.remove();
                source.hidden = originalHidden;
                instances.delete(source);
            }
        });
        refresh();
    }

    window.AdminListbox = {
        mount: mount,
        refresh: function (source) { if (instances.has(source)) instances.get(source).refresh(); },
        destroy: function (source) { if (instances.has(source)) instances.get(source).destroy(); }
    };
})(window);
