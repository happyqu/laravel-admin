(function (window, document, $) {
    'use strict';
    if (window.LAImageEditor) return;

    function load(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var image = new Image();
            image.onload = function () { resolve(image); };
            image.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode')); };
            image.src = url;
        });
    }

    function crop(image, config, labels, state) {
        return new Promise(function (resolve, reject) {
            var overlay = document.createElement('div');
            overlay.className = 'la-image-editor';
            overlay.innerHTML = '<div class="la-image-editor-panel" role="dialog" aria-modal="true" tabindex="-1">' +
                '<strong></strong><div class="la-image-editor-stage"></div>' +
                '<div class="la-image-editor-actions"><button type="button" class="btn btn-default"></button>' +
                '<button type="button" class="btn btn-primary" disabled></button></div></div>';
            var panel = overlay.firstChild;
            var title = panel.querySelector('strong');
            title.textContent = labels.title;
            panel.setAttribute('aria-label', labels.title);
            panel.querySelector('.la-image-editor-stage').appendChild(image);
            var buttons = panel.querySelectorAll('button');
            buttons[0].textContent = labels.cancel;
            buttons[1].textContent = labels.confirm;
            var previousFocus = document.activeElement;
            var editor;
            var settled = false;
            function finish(canvas, error) {
                if (settled) return;
                settled = true;
                if (editor) editor.destroy();
                overlay.remove();
                document.removeEventListener('keydown', keydown, true);
                state.cancel = null;
                if (previousFocus && previousFocus.isConnected) previousFocus.focus();
                if (error) reject(error); else resolve(canvas);
            }
            function keydown(event) {
                if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); finish(null); }
                if (event.key === 'Tab') {
                    var target = event.shiftKey ? buttons[0] : (buttons[1].disabled ? buttons[0] : buttons[1]);
                    if (!panel.contains(document.activeElement) || document.activeElement === target) {
                        event.preventDefault();
                        (event.shiftKey && !buttons[1].disabled ? buttons[1] : buttons[0]).focus();
                    }
                }
            }
            state.cancel = function () { finish(null); };
            buttons[0].onclick = function () { finish(null); };
            buttons[1].onclick = async function () {
                buttons[1].disabled = true;
                try {
                    var selection = editor.getCropperSelection();
                    var transform = editor.getCropperImage().$getTransform();
                    var scale = Math.hypot(transform[0], transform[1]);
                    var size = config.width ? {width: config.width, height: config.height} : {width: selection.width / scale};
                    var canvas = await selection.$toCanvas(size);
                    if (!canvas || !canvas.width || !canvas.height) throw new Error('crop');
                    // Cropper fits the requested size to the selection's ratio.
                    // Canvas dimensions are integers, so normalize any rounding drift.
                    if (config.width && (canvas.width !== config.width || canvas.height !== config.height)) {
                        var output = document.createElement('canvas');
                        output.width = config.width;
                        output.height = config.height;
                        output.getContext('2d').drawImage(canvas, 0, 0, output.width, output.height);
                        canvas = output;
                    }
                    finish(canvas);
                } catch (error) { finish(null, error); }
            };
            document.body.appendChild(overlay);
            document.addEventListener('keydown', keydown, true);
            buttons[0].focus();
            try {
                editor = new window.Cropper.Cropper(image);
                var selection = editor.getCropperSelection();
                selection.precise = true;
                // Configure the v2 selection element, not the v1 constructor.
                ['initialCoverage', 'initialAspectRatio', 'movable', 'resizable', 'zoomable',
                    'resizeAroundCenter', 'zoomAroundCenter', 'minInset', 'maxInset'].forEach(function (key) {
                    if (Object.prototype.hasOwnProperty.call(config.options, key)) selection[key] = config.options[key];
                });
                selection.aspectRatio = config.width ? config.width / config.height : (config.options.aspectRatio || NaN);
                editor.getCropperImage().$ready().then(function () {
                    if (settled) return;
                    return selection.$nextTick(function () { if (!settled) buttons[1].disabled = false; });
                }).catch(function (error) { finish(null, error); });
            } catch (error) { finish(null, error); }
        });
    }

    async function process(file, config, state) {
        // Animated GIF and other formats stay untouched for compression. Cropping
        // unsupported formats must be explicit: do not silently flatten animations.
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            if (config.cropper) throw new Error(config.labels.unsupported);
            return file;
        }
        var image = await load(file);
        try {
            if (state.obsolete()) return null;
            var canvas;
            if (config.cropper) {
                canvas = await crop(image, config.cropper, config.labels, state);
                if (!canvas) return null;
            } else {
                canvas = document.createElement('canvas');
                canvas.width = image.naturalWidth;
                canvas.height = image.naturalHeight;
                canvas.getContext('2d').drawImage(image, 0, 0);
            }
            if (config.compress) {
                var scale = Math.min(1, config.compress.maxWidth / canvas.width, config.compress.maxHeight / canvas.height);
                if (scale < 1) {
                    var resized = document.createElement('canvas');
                    resized.width = Math.max(1, Math.round(canvas.width * scale));
                    resized.height = Math.max(1, Math.round(canvas.height * scale));
                    resized.getContext('2d').drawImage(canvas, 0, 0, resized.width, resized.height);
                    canvas = resized;
                }
            }
            var blob = await new Promise(function (resolve, reject) {
                canvas.toBlob(function (result) { if (result) resolve(result); else reject(new Error('encode')); },
                    file.type, config.compress ? config.compress.quality : 0.92);
            });
            // Some browsers fall back to PNG for unsupported encoders. Keep MIME and suffix consistent.
            var extension = {'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp'}[blob.type];
            if (!extension) throw new Error('encode');
            if (!config.cropper && blob.size >= file.size) return file;
            var name = blob.type === file.type ? file.name : file.name.replace(/\.[^.]+$/, '') + '.' + extension;
            return new File([blob], name, {type: blob.type, lastModified: file.lastModified});
        } finally {
            URL.revokeObjectURL(image.src);
        }
    }

    function bind(input, config) {
        if (input.laImageEditor) { input.laImageEditor.config = config; return; }
        var state = input.laImageEditor = {config: config, generation: 0, pending: false, replay: false};
        var status = document.createElement('div');
        status.className = 'la-image-editor-status text-muted';
        status.setAttribute('role', 'status');
        input.closest('.file-input') ? input.closest('.file-input').after(status) : input.after(status);
        function reset() {
            state.generation++;
            state.pending = false;
            if (state.observer) state.observer.disconnect();
            if (state.cancel) state.cancel();
            input.setCustomValidity('');
            status.textContent = '';
            status.className = 'la-image-editor-status text-muted';
        }
        function clearSelection() {
            var widget = $(input).data('fileinput');
            var overwriteInitial = widget && widget.overwriteInitial;
            // clear() otherwise discards the saved single-image preview cache.
            if (widget) widget.overwriteInitial = false;
            try { $(input).fileinput('clear'); }
            finally { if (widget) widget.overwriteInitial = overwriteInitial; }
        }
        $(input).on('fileclear.laImageEditor', reset);
        if (input.form) {
            input.form.addEventListener('reset', reset);
            input.form.addEventListener('submit', function (event) {
                if (state.pending) { event.preventDefault(); event.stopImmediatePropagation(); }
            }, true);
        }
        input.addEventListener('change', async function (event) {
            if (state.replay) return;
            reset();
            var files = Array.from(input.files || []);
            if (!files.length) return;
            event.stopImmediatePropagation();
            var config = state.config;
            var generation = state.generation;
            state.obsolete = function () { return generation !== state.generation || !input.isConnected; };
            var obsolete = state.obsolete;
            state.pending = true;
            state.observer = new MutationObserver(function () { if (!input.isConnected) reset(); });
            state.observer.observe(document.body, {childList: true, subtree: true});
            status.textContent = config.labels.processing;
            input.setCustomValidity(config.labels.processing);
            try {
                var transfer = new DataTransfer();
                for (var i = 0; i < files.length; i++) {
                    var result = await process(files[i], config, {obsolete: obsolete,
                        get cancel() { return state.cancel; }, set cancel(value) { state.cancel = value; }});
                    if (obsolete()) return;
                    if (!result) { clearSelection(); return; }
                    transfer.items.add(result);
                }
                input.files = transfer.files;
                state.pending = false;
                input.setCustomValidity('');
                status.textContent = '';
                state.replay = true;
                try { input.dispatchEvent(new Event('change', {bubbles: true})); }
                finally { state.replay = false; }
            } catch (error) {
                if (obsolete()) return;
                clearSelection();
                status.textContent = error.message === config.labels.unsupported ? error.message : config.labels.error;
                status.className = 'la-image-editor-status text-danger';
            } finally {
                if (!obsolete()) {
                    state.pending = false;
                    state.observer.disconnect();
                    input.setCustomValidity('');
                }
            }
        }, true);
    }

    window.LAImageEditor = {
        bind: function (inputs, config) { Array.prototype.forEach.call(inputs, function (input) { bind(input, config); }); }
    };
})(window, document, window.jQuery);
