(function (window, $) {
    'use strict';
    $.admin = $.admin || {};
    if ($.admin.cascader) { $.admin.cascader.init(document); return; }
    var live = new Set(), sequence = 0, trees = new Map();
    function button(className, text) { return $('<button type="button">').addClass(className).text(text); }
    function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
    function tree(config) {
        var key = config.treeKey || config.url;
        if (!trees.has(key)) {
            var deferred = $.Deferred(), promise = deferred.promise();
            // A shared download belongs to all matching instances, not one field's lifecycle.
            promise.abort = function () {};
            trees.set(key, promise);
            $.ajax({ url: config.url, data: { operation: 'tree' }, dataType: 'json', adminLoading: false })
                .done(function (result) { deferred.resolve(result.items); })
                .fail(function (xhr, state) { trees.delete(key); deferred.reject(xhr, state); });
        }
        return trees.get(key);
    }
    function Controller(root) {
        var self = this;
        this.root = root;
        this.$root = $(root);
        this.config = JSON.parse(root.getAttribute('data-admin-cascader'));
        this.messages = this.config.messages;
        this.inputs = this.$root.find('[data-cascader-value]').toArray();
        this.resetValues = this.inputs.map(function (input) { return input.value; });
        this.toggle = this.$root.find('.admin-cascader-toggle');
        this.clear = this.$root.find('.admin-cascader-clear');
        this.label = this.$root.find('[data-cascader-label]');
        this.status = this.$root.find('.admin-cascader-status');
        this.panel = this.$root.find('.admin-cascader-panel');
        this.columnsRoot = this.panel.find('.admin-cascader-columns');
        this.breadcrumb = this.panel.find('.admin-cascader-breadcrumb');
        this.form = root.closest('form');
        this.columns = [];
        this.path = [];
        this.committed = [];
        this.resolveVersion = 0;
        this.valid = true;
        this.opened = false;
        this.destroyed = false;
        this.tree = null;
        this.treeLoading = false;
        this.pendingOpen = false;
        var id = 'admin-cascader-panel-' + (++sequence);
        this.panel.attr('id', id);
        this.toggle.attr('aria-controls', id);
        // Keep the panel inside a modal's focus boundary, outside clipping form groups.
        this.panel.appendTo(this.$root.closest('.modal').length ? this.$root.closest('.modal') : document.body);
        this.toggle.on('click.adminCascader', function () { self.opened || self.pendingOpen ? self.hide() : self.open(); });
        this.clear.on('click.adminCascader', function () { if (!self.locked()) self.commit([]); });
        this.panel.on('keydown.adminCascader', function (event) { self.keydown(event); });
        this.$root.on('keydown.adminCascader', function (event) {
            if (self.opened && event.key === 'Escape') self.keydown(event);
        });
        this.toggle.on('keydown.adminCascader', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); self.open(); self.focusColumn(0); }
        });
        this.resolve();
    }
    Controller.prototype.locked = function () {
        return this.config.locked || this.inputs.some(function (input) { return input.matches(':disabled'); });
    };
    Controller.prototype.removed = function () {
        // Follow this field's ancestor names, excluding deletion flags in sibling/child rows.
        if (!this.form || !this.inputs.length) return false;
        var prefix = this.inputs[0].name, index;
        while ((index = prefix.lastIndexOf('[')) > 0) {
            prefix = prefix.slice(0, index);
            var flag = this.form.elements.namedItem(prefix + '[_remove_]');
            if (flag && flag.classList && flag.classList.contains('fom-removed') && flag.value === '1') return true;
        }
        return false;
    };
    Controller.prototype.ids = function (path) { return path.map(function (node) { return String(node.value); }); };
    Controller.prototype.notify = function (message, retry) {
        this.status.empty().toggleClass('is-error', !!retry).append(document.createTextNode(message || ''));
        if (retry) this.status.append(button('', this.messages.retry).on('click', retry));
    };
    Controller.prototype.display = function () {
        var text = this.committed.map(function (node) { return node.label; }).join(' / ');
        this.label.text(text || this.config.placeholder).attr('title', text);
        this.clear.prop('hidden', !this.committed.length || this.locked());
    };
    Controller.prototype.resolve = function () {
        var self = this, version = ++this.resolveVersion;
        if (this.resolveRequest) this.resolveRequest.abort();
        var values = this.inputs.map(function (input) { return input.value; });
        var nonempty = values.filter(function (id) { return id !== ''; });
        this.committed = nonempty.map(function (id) { return { value: id, label: id }; });
        this.display();
        if (this.config.preload && this.tree === null) {
            this.valid = false;
            this.treeLoading = true;
            this.notify(this.messages.loading);
            this.resolveRequest = tree(this.config).done(function (items) {
                if (self.destroyed || version !== self.resolveVersion) return;
                self.tree = items;
                self.treeLoading = false;
                self.resolve();
                if (self.pendingOpen) self.open();
            }).fail(function (xhr, state) {
                if (self.destroyed || version !== self.resolveVersion) return;
                self.treeLoading = false;
                self.pendingOpen = false;
                self.notify(self.messages.failed, function () { self.resolve(); });
            });
            return;
        }
        if (!nonempty.length) { this.valid = true; this.notify(''); return; }
        if (this.tree !== null) {
            var ids = values.slice(), path = [], items = this.tree, ended = false, invalid = false;
            ids.forEach(function (id) {
                if (id === '') { ended = true; return; }
                var node = items.find(function (item) { return String(item.value) === id; });
                if (ended || !node || node.disabled) { invalid = true; return; }
                path.push(node);
                items = node.children || [];
            });
            if (!this.config.multiField) {
                // Terminal IDs need not be globally unique: require exactly one matching path.
                var matches = [];
                var walk = function (items, ancestors) {
                    items.forEach(function (node) {
                        var next = ancestors.concat([node]);
                        if (String(node.value) === values[0] && !next.some(function (item) { return item.disabled; })) matches.push(next);
                        walk(node.children || [], next);
                    });
                };
                walk(this.tree, []);
                path = matches.length === 1 ? matches[0] : [];
                invalid = !path.length;
            }
            invalid = invalid || (this.config.depth && path.length > this.config.depth)
                || (this.config.leafOnly && path.length && !path[path.length - 1].leaf);
            this.valid = !invalid;
            if (invalid) this.notify(this.messages.invalid, function () { self.resolve(); });
            else { this.committed = path; this.display(); this.notify(''); }
            return;
        }
        this.valid = false;
        this.notify(this.messages.loading);
        var data = { operation: 'resolve' };
        if (this.config.multiField) data.path = values;
        else data.value = values[0];
        this.resolveRequest = $.ajax({ url: this.config.url, data: data, dataType: 'json', adminLoading: false })
            .done(function (result) {
                if (self.destroyed || version !== self.resolveVersion) return;
                self.committed = result.items;
                self.valid = true;
                self.display();
                self.notify('');
            }).fail(function (xhr, state) {
                if (self.destroyed || state === 'abort' || version !== self.resolveVersion) return;
                self.notify(xhr.status === 422 ? self.messages.invalid : self.messages.failed, function () { self.resolve(); });
            });
    };
    Controller.prototype.position = function () {
        if (!this.opened) return;
        var rect = this.root.getBoundingClientRect();
        var width = Math.min(Math.max(240, this.columns.length * 192), window.innerWidth - 16);
        var height = Math.min(this.panel[0].offsetHeight || 340, window.innerHeight - 16);
        var top = rect.bottom + 4;
        if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 4);
        this.panel.css({ width: width, left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)), top: top });
        var focusedColumn = $(document.activeElement).closest('.admin-cascader-column');
        if (focusedColumn.length && this.panel[0].contains(focusedColumn[0]) && focusedColumn.css('display') === 'none') {
            this.focusColumn(this.columns.length - 1);
        }
    };
    Controller.prototype.open = function () {
        if (this.locked()) return;
        if (this.config.preload && this.tree === null) {
            this.pendingOpen = true;
            if (!this.treeLoading) this.resolve();
            return;
        }
        live.forEach(function (controller) { if (controller !== this) controller.hide(); }, this);
        this.hide();
        this.opened = true;
        this.path = [];
        this.panel.prop('hidden', false);
        this.toggle.attr('aria-expanded', 'true');
        this.load([], '', null, false, false, true);
        this.position();
    };
    Controller.prototype.trim = function (length) {
        this.columns.splice(length).forEach(function (column) {
            clearTimeout(column.timer);
            column.version++;
            if (column.request) column.request.abort();
            column.element.remove();
        });
    };
    Controller.prototype.hide = function () {
        this.pendingOpen = false;
        this.opened = false;
        this.trim(0);
        this.panel.prop('hidden', true);
        this.toggle.attr('aria-expanded', 'false');
    };
    Controller.prototype.crumbs = function () {
        var self = this;
        this.breadcrumb.empty();
        if (this.path.length) {
            this.breadcrumb.append(button('', '‹ ' + this.messages.back).on('click', function () {
                self.path.pop();
                self.trim(self.path.length + 1);
                self.crumbs();
                self.position();
                self.focusColumn(self.path.length);
            }));
        }
        this.breadcrumb.append($('<span>').text(this.path.map(function (node) { return node.label; }).join(' / ') || this.config.placeholder));
    };
    Controller.prototype.load = function (path, search, cursor, append, focus, restore) {
        var self = this, depth = path.length, ids = this.ids(path), column = this.columns[depth];
        if (!column) {
            column = { element: $('<div class="admin-cascader-column">'), version: 0, items: [] };
            column.search = $('<input type="search" class="admin-cascader-search">').attr({ placeholder: this.messages.search, 'aria-label': this.messages.search });
            column.list = $('<div role="listbox">').attr('aria-label', this.messages.search);
            column.element.append(column.search, column.list).appendTo(this.columnsRoot);
            this.columns[depth] = column;
            column.search.on('input', function () {
                self.path = path.slice();
                self.trim(depth + 1);
                self.crumbs();
                column.version++;
                if (column.request) column.request.abort();
                clearTimeout(column.timer);
                if (self.tree !== null) self.load(path, column.search.val(), null, false, false, false);
                else column.timer = setTimeout(function () { self.load(path, column.search.val(), null, false, false, false); }, 250);
            });
        }
        var version = ++column.version;
        if (column.request) column.request.abort();
        if (!append) column.items = [];
        this.crumbs();
        this.position();
        var show = function (result) {
                if (self.destroyed || !self.opened || self.columns[depth] !== column || column.version !== version) return;
                column.element.attr('aria-busy', 'false');
                column.items = column.items.concat(result.items);
                self.renderColumn(column, path, search, result.nextCursor);
                if (focus) self.focusColumn(depth);
                // Restore the committed branch in the panel without changing submitted values.
                if (restore && self.valid && self.committed[depth]) {
                    var selected = column.items.find(function (node) { return String(node.value) === String(self.committed[depth].value); });
                    if (!selected) selected = self.committed[depth];
                    if (!selected.leaf && depth + 1 < self.committed.length) self.expand(path.concat([selected]), false, true);
                }
                self.position();
        };
        if (this.tree !== null) {
            var items = this.tree;
            ids.forEach(function (id) {
                var parent = items.find(function (node) { return String(node.value) === id; });
                items = parent ? parent.children || [] : [];
            });
            items = items.filter(function (node) { return !search || String(node.label).toLocaleLowerCase().indexOf(search.toLocaleLowerCase()) !== -1; });
            var offset = Math.max(0, Number(cursor) || 0);
            show({ items: items.slice(offset, offset + 50), nextCursor: items.length > offset + 50 ? String(offset + 50) : null });
            return;
        }
        column.element.attr('aria-busy', 'true');
        column.list.empty().append($('<div class="admin-cascader-message">').text(this.messages.loading));
        column.request = $.ajax({ url: this.config.url, data: { operation: 'children', path: ids, search: search, cursor: cursor }, dataType: 'json', adminLoading: false })
            .done(show).fail(function (xhr, state) {
                if (self.destroyed || state === 'abort' || self.columns[depth] !== column || column.version !== version) return;
                column.element.attr('aria-busy', 'false');
                column.list.empty().append($('<div class="admin-cascader-message">').text(self.messages.failed),
                    button('admin-cascader-retry', self.messages.retry).on('click', function () { self.load(path, search, cursor, append, true, restore); }));
            });
    };
    Controller.prototype.renderColumn = function (column, path, search, cursor) {
        var self = this, depth = path.length;
        column.list.empty();
        column.items.forEach(function (node) {
            var committedParent = same(self.ids(path), self.ids(self.committed.slice(0, depth)));
            var selected = self.path[depth] || (committedParent ? self.committed[depth] : null);
            var active = selected && String(selected.value) === String(node.value);
            var row = $('<div class="admin-cascader-row">').toggleClass('is-active', !!active);
            var item = button('admin-cascader-node', '').attr({ role: 'option', 'aria-selected': active ? 'true' : 'false' }).prop('disabled', !!node.disabled);
            item.attr('title', node.label);
            item.append($('<span>').text(node.label), $('<span aria-hidden="true">').text(node.leaf ? (active ? '✓' : '') : '›'));
            item.on('click', function () {
                if (self.locked() || node.disabled) return;
                if (node.leaf) self.commit(path.concat([node]));
                else self.expand(path.concat([node]), true, false);
            });
            row.append(item);
            if (!node.leaf && !self.config.leafOnly) {
                row.append(button('admin-cascader-select', '✓').attr('aria-label', self.messages.select + ': ' + node.label)
                    .prop('disabled', !!node.disabled).on('click', function () { if (!self.locked()) self.commit(path.concat([node])); }));
            }
            column.list.append(row);
        });
        if (!column.items.length) column.list.append($('<div class="admin-cascader-message">').text(this.messages.empty));
        if (cursor !== null && cursor !== undefined) {
            column.list.append(button('admin-cascader-more', this.messages.more).on('click', function () { self.load(path, search, cursor, true, true, false); }));
        }
    };
    Controller.prototype.expand = function (path, focus, restore) {
        if (path.length >= (this.config.depth || 16)) return;
        this.trim(path.length);
        this.path = path;
        var column = this.columns[path.length - 1];
        if (column) column.list.find('.admin-cascader-row').removeClass('is-active').find('[aria-selected]').attr('aria-selected', 'false');
        if (column) column.list.find('.admin-cascader-row').each(function (index) {
            if (String(column.items[index] && column.items[index].value) === String(path[path.length - 1].value)) $(this).addClass('is-active').find('[aria-selected]').attr('aria-selected', 'true');
        });
        this.load(path.slice(), '', null, false, focus, restore);
    };
    Controller.prototype.commit = function (path) {
        if (this.locked() || (this.config.depth && path.length > this.config.depth)) return;
        ++this.resolveVersion;
        this.treeLoading = false;
        if (this.resolveRequest) this.resolveRequest.abort();
        var previous = this.inputs.map(function (input) { return input.value; });
        var ids = this.ids(path), self = this;
        this.inputs.forEach(function (input, index) { input.value = self.config.multiField ? (ids[index] || '') : (ids[ids.length - 1] || ''); });
        this.committed = path;
        this.valid = true;
        this.display();
        this.notify('');
        this.hide();
        if (!same(previous, this.inputs.map(function (input) { return input.value; }))) {
            this.inputs.forEach(function (input) { input.dispatchEvent(new Event('change', { bubbles: true })); });
            this.$root.trigger('cascader:change', [ids]);
        }
        this.toggle.trigger('focus');
    };
    Controller.prototype.focusColumn = function (depth) {
        var column = this.columns[depth];
        if (column) column.element.find('button:not(:disabled),input').first().trigger('focus');
    };
    Controller.prototype.keydown = function (event) {
        var key = event.key;
        if (key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.hide(); this.toggle.trigger('focus'); return; }
        var column = $(event.target).closest('.admin-cascader-column'), index = this.columns.findIndex(function (item) { return item.element[0] === column[0]; });
        if (key === 'ArrowDown' || key === 'ArrowUp') {
            var targets = column.find('button:not(:disabled),input').toArray(), current = targets.indexOf(event.target);
            if (targets.length) { event.preventDefault(); targets[(current + (key === 'ArrowDown' ? 1 : targets.length - 1)) % targets.length].focus(); }
        } else if (key === 'ArrowRight' && $(event.target).hasClass('admin-cascader-node')) {
            event.preventDefault(); event.target.click();
        } else if (key === 'ArrowLeft' && index > 0 && !$(event.target).is('input')) {
            event.preventDefault(); this.path = this.path.slice(0, index - 1); this.trim(index); this.crumbs(); this.focusColumn(index - 1); this.position();
        } else if (key === 'Enter' && $(event.target).is('input')) event.preventDefault();
    };
    Controller.prototype.destroy = function () {
        this.destroyed = true;
        ++this.resolveVersion;
        if (this.resolveRequest) this.resolveRequest.abort();
        this.hide();
        this.panel.off('.adminCascader').remove();
        this.toggle.off('.adminCascader');
        this.clear.off('.adminCascader');
        this.$root.off('.adminCascader').removeData('adminCascader');
        live.delete(this);
    };
    function init(root) {
        $(root).find('[data-admin-cascader]').addBack('[data-admin-cascader]').each(function () {
            if (!$.data(this, 'adminCascader')) {
                var controller = new Controller(this);
                $.data(this, 'adminCascader', controller);
                live.add(controller);
            }
        });
    }
    function destroy(root) { live.forEach(function (controller) { if (!root || root === controller.root || $.contains(root, controller.root)) controller.destroy(); }); }
    $.admin.cascader = { init: init, destroy: destroy };
    $(document).on('mousedown.adminCascader', function (event) {
        live.forEach(function (controller) { if (!controller.root.contains(event.target) && !controller.panel[0].contains(event.target)) controller.hide(); });
    });
    $(document).on('keydown.adminCascader', function (event) {
        if (event.key !== 'Escape') return;
        live.forEach(function (controller) {
            if (controller.opened) {
                event.preventDefault(); event.stopPropagation();
                controller.hide(); controller.toggle.trigger('focus');
            }
        });
    });
    // Capture before AJAX form handlers: unresolved existing values must not be silently saved.
    document.addEventListener('submit', function (event) {
        live.forEach(function (controller) {
            if (controller.form === event.target && !controller.locked() && !controller.removed() && !controller.valid) {
                event.preventDefault(); event.stopImmediatePropagation();
                controller.toggle.trigger('focus');
                if (!controller.status.hasClass('is-error')) controller.notify(controller.messages.pending);
            }
        });
    }, true);
    $(document).on('reset.adminCascader', 'form', function (event) {
        var form = this;
        setTimeout(function () {
            if (event.isDefaultPrevented()) return;
            live.forEach(function (controller) {
                if (controller.form === form) {
                    controller.inputs.forEach(function (input, index) { input.value = controller.resetValues[index]; });
                    controller.hide(); controller.resolve();
                }
            });
        }, 0);
    });
    $(window).on('resize.adminCascader scroll.adminCascader', function () { live.forEach(function (controller) { controller.position(); }); });
    $(window).on('admin:before-navigate.adminCascader', function () { destroy(document.getElementById('admin-content')); });
    $(document).on('hidden.bs.modal.adminCascader', '.modal', function () {
        var modal = this;
        live.forEach(function (controller) { if (modal.contains(controller.root)) controller.hide(); });
    });
    var observer = new MutationObserver(function (records) {
        if (!window.document || !document.documentElement) { observer.disconnect(); return; }
        live.forEach(function (controller) { if (!document.documentElement.contains(controller.root)) controller.destroy(); });
        records.forEach(function (record) {
            Array.from(record.addedNodes).forEach(function (node) { if (node.nodeType === 1) init(node); });
        });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    init(document);
})(window, window.jQuery);
