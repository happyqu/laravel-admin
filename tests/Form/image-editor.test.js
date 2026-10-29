const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '../..');
const tick = () => new Promise(resolve => setTimeout(resolve, 10));

function page(realPlugin = false, pluginOptions = {}) {
    const dom = new JSDOM('<form><input type="file" name="photos[]" multiple><button>Save</button></form>', {
        runScripts: 'outside-only', url: 'http://localhost/',
    });
    const w = dom.window;
    const input = w.document.querySelector('input');
    const data = {files: [], seen: [], exports: [], released: [], loaded: [], size: 20};
    Object.defineProperty(input, 'files', {get: () => data.files, set: value => { data.files = value; }});
    w.URL.createObjectURL = () => 'blob:test/' + Math.random();
    w.URL.revokeObjectURL = url => data.released.push(url);
    w.Image = function () {
        const image = w.document.createElement('img');
        Object.defineProperties(image, {naturalWidth: {value: 4000}, naturalHeight: {value: 2000}});
        setTimeout(() => { data.loaded.push(image); if (!data.pause) image.onload(); }, 0);
        return image;
    };
    w.HTMLCanvasElement.prototype.getContext = () => ({drawImage() {}});
    w.HTMLCanvasElement.prototype.toBlob = function (callback, type, quality) {
        data.exports.push({width: this.width, height: this.height, type, quality});
        callback(new w.Blob(['x'.repeat(data.size)], {type}));
    };
    w.DataTransfer = class {
        constructor() { this.files = []; this.items = {add: file => this.files.push(file)}; }
    };
    w.Cropper = {Cropper: class {
        constructor() {
            data.crops = (data.crops || 0) + 1;
            this.selection = {width: 200, $nextTick: callback => Promise.resolve().then(callback),
                $toCanvas: async options => {
                    data.cropSize = options;
                    const canvas = w.document.createElement('canvas');
                    canvas.width = options.width - (data.roundedCrop ? 4 : 0);
                    canvas.height = options.height || options.width / 2;
                    return canvas;
                }};
            data.selection = this.selection;
        }
        getCropperSelection() { return this.selection; }
        getCropperImage() { return {$ready: () => Promise.resolve(), $getTransform: () => [0.1, 0, 0, 0.1, 0, 0]}; }
        destroy() { data.destroyed = (data.destroyed || 0) + 1; }
    }};
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    if (realPlugin) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets/bootstrap-fileinput/js/fileinput.min.js'), 'utf8'));
        w.$(input).fileinput(Object.assign({showUpload: false, dropZoneEnabled: false}, pluginOptions));
    } else {
        w.$.fn.fileinput = function (command) {
            if (command === 'clear') { this.trigger('fileclear'); data.files = []; }
            return this;
        };
    }
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/laravel-admin/image-editor.js'), 'utf8'));
    const labels = {title: 'Crop', confirm: 'OK', cancel: 'Cancel', processing: 'Processing', error: 'Failed', unsupported: 'Unsupported'};
    data.bind = config => w.LAImageEditor.bind([input], Object.assign({labels}, config));
    data.select = files => { data.files = files; input.dispatchEvent(new w.Event('change', {bubbles: true})); };
    data.file = (name = 'a.jpg', type = 'image/jpeg') => new w.File(['x'.repeat(100)], name, {type});
    input.addEventListener('change', () => data.seen.push(Array.from(input.files)));
    return {w, input, data};
}

test('compresses in order before notifying the upload widget and blocks pending submission', async () => {
    const {w, input, data} = page();
    try {
        data.bind({compress: {quality: 0.7, maxWidth: 1600, maxHeight: 1200}});
        data.select([data.file(), data.file('b.png', 'image/png')]);
        const event = new w.Event('submit', {cancelable: true, bubbles: true});
        assert.equal(input.form.dispatchEvent(event), false);
        assert.equal(input.validity.customError, true);
        assert.equal(data.seen.length, 0);
        await tick(); await tick();
        assert.equal(data.seen.length, 1);
        assert.deepEqual(data.seen[0].map(file => file.name), ['a.jpg', 'b.png']);
        assert.equal(data.files[0].size, 20);
        assert.deepEqual(data.exports[0], {width: 1600, height: 800, type: 'image/jpeg', quality: 0.7});
        assert.equal(data.files[1].type, 'image/png');
        assert.equal(input.validity.customError, false);
        assert.equal(data.released.length, 2);
    } finally { w.close(); }
});

test('v2 cropping exports fixed pixels and free crops at original resolution', async () => {
    for (const fixed of [false, true]) {
        const {w, data} = page();
        try {
            data.bind({cropper: {width: fixed ? 400 : null, height: fixed ? 400 : null, options: {}}});
            data.select([data.file()]);
            await tick();
            w.document.querySelector('.la-image-editor .btn-primary').click();
            await tick();
            assert.equal(data.files[0].size, 20);
            assert.equal(data.cropSize.width, fixed ? 400 : 2000);
            assert.equal(data.destroyed, 1);
            assert.equal(w.document.querySelector('.la-image-editor'), null);
        } finally { w.close(); }
    }
});

test('canceling the second crop discards the whole batch and releases object URLs', async () => {
    const {w, input, data} = page();
    try {
        data.bind({cropper: {width: null, height: null, options: {}}});
        data.select([data.file(), data.file('b.jpg')]);
        await tick();
        w.document.querySelector('.la-image-editor .btn-primary').click();
        await tick();
        w.document.querySelector('.la-image-editor .btn-default').click();
        await tick();
        assert.equal(data.files.length, 0);
        assert.equal(data.seen.length, 0);
        assert.equal(data.destroyed, 2);
        assert.equal(data.released.length, 2);
        assert.equal(input.validity.customError, false);
    } finally { w.close(); }
});

test('non-square fixed crops normalize Cropper rounding to the requested output pixels', async () => {
    for (const [width, height] of [[1600, 900], [901, 1600]]) {
        const {w, data} = page();
        try {
            data.roundedCrop = true;
            data.bind({cropper: {width, height, options: {}}});
            data.select([data.file()]);
            await tick();
            assert.equal(data.selection.precise, true);
            w.document.querySelector('.la-image-editor .btn-primary').click();
            await tick();
            assert.equal(data.exports[0].width, width);
            assert.equal(data.exports[0].height, height);
        } finally { w.close(); }
    }
});

for (const failure of ['cancel', 'unsupported', 'encode']) {
    test(`${failure} preserves a saved single-image preview and its delete action`, async () => {
        const {w, input, data} = page(true, {
            overwriteInitial: true,
            initialPreviewAsData: true,
            initialPreview: ['/uploads/saved.png'],
            initialPreviewConfig: [{caption: 'saved.png', key: 7, url: '/delete'}],
        });
        try {
            input.removeAttribute('multiple');
            const widget = w.$(input).data('fileinput');
            const before = JSON.stringify(widget.previewCache.data);
            const deleteButtonsBefore = widget.$preview.find('.file-preview-initial .kv-file-remove').length;
            assert.equal(widget.previewCache.count(), 1);
            assert.ok(deleteButtonsBefore > 0);
            data.bind({cropper: {width: 1600, height: 900, options: {}}});
            if (failure === 'encode') w.HTMLCanvasElement.prototype.toBlob = callback => callback(null);
            data.select([failure === 'unsupported' ? data.file('a.gif', 'image/gif') : data.file()]);
            await tick();
            if (failure !== 'unsupported') {
                w.document.querySelector(failure === 'cancel' ? '.la-image-editor .btn-default' : '.la-image-editor .btn-primary').click();
            }
            await tick();
            assert.equal(widget.previewCache.count(), 1);
            assert.equal(JSON.stringify(widget.previewCache.data), before);
            assert.equal(widget.overwriteInitial, true);
            assert.equal(widget.$preview.find('.file-preview-initial .kv-file-remove').length, deleteButtonsBefore);
            assert.equal(widget.getFileStack().length, 0);
            assert.equal(data.seen.length, 0);
            assert.equal(input.laImageEditor.pending, false);
            // A subsequent confirmed replacement must still overwrite normally.
            data.bind({compress: {quality: 0.8, maxWidth: 1000, maxHeight: 1000}});
            w.HTMLCanvasElement.prototype.toBlob = function (callback, type) { callback(new w.Blob(['ok'], {type})); };
            data.select([data.file('replacement.jpg')]);
            await tick(); await tick();
            assert.equal(widget.previewCache.count(), 0);
            assert.equal(data.files[0].name, 'replacement.jpg');
        } finally { w.close(); }
    });
}

test('reset and newer selections invalidate asynchronous results; repeated binding stays single', async () => {
    const {w, input, data} = page();
    try {
        const config = {compress: {quality: 0.8, maxWidth: 1000, maxHeight: 1000}};
        data.bind(config); data.bind(config);
        data.pause = true;
        data.select([data.file('old.jpg')]);
        await tick();
        input.form.reset();
        data.pause = false;
        data.select([data.file('new.jpg')]);
        await tick();
        data.loaded[0].onload();
        await tick();
        assert.equal(data.seen.length, 1);
        assert.equal(data.files[0].name, 'new.jpg');
        assert.equal(data.released.length, 2);
        assert.equal(w.document.querySelectorAll('.la-image-editor-status').length, 1);
    } finally { w.close(); }
});

test('compression retains smaller originals and GIFs, while cropping rejects GIFs', async () => {
    const {w, data} = page();
    try {
        data.size = 200;
        const original = data.file();
        const gif = data.file('animation.gif', 'image/gif');
        data.bind({compress: {quality: 0.8, maxWidth: 1920, maxHeight: 1920}});
        data.select([original, gif]);
        await tick();
        assert.equal(data.files[0], original);
        assert.equal(data.files[1], gif);
        data.bind({cropper: {width: null, height: null, options: {}}});
        data.select([gif]);
        await tick();
        assert.equal(data.files.length, 0);
        assert.equal(w.document.querySelector('[role=status]').textContent, 'Unsupported');
    } finally { w.close(); }
});

test('processed files reach the real bootstrap-fileinput stack', async () => {
    const {w, input, data} = page(true);
    try {
        data.bind({compress: {quality: 0.8, maxWidth: 1000, maxHeight: 1000}});
        data.select([data.file()]);
        await tick(); await tick();
        assert.equal(data.seen.length, 1);
        assert.equal(data.files[0].size, 20);
        assert.equal(w.$(input).data('fileinput').getFileStack()[0].size, 20);
    } finally { w.close(); }
});

test('removing a nested image field closes its crop dialog and releases resources', async () => {
    const {w, input, data} = page();
    try {
        data.bind({cropper: {width: null, height: null, options: {}}});
        data.select([data.file()]);
        await tick();
        assert.ok(w.document.querySelector('.la-image-editor'));
        input.remove();
        await tick();
        assert.equal(w.document.querySelector('.la-image-editor'), null);
        assert.equal(data.released.length, 1);
        assert.equal(data.seen.length, 0);
        assert.equal(input.laImageEditor.pending, false);
    } finally { w.close(); }
});

test('unavailable DataTransfer fails without uploading the unprocessed original', async () => {
    const {w, input, data} = page();
    try {
        w.DataTransfer = undefined;
        data.bind({compress: {quality: 0.8, maxWidth: 1000, maxHeight: 1000}});
        data.select([data.file()]);
        await tick();
        assert.equal(data.files.length, 0);
        assert.equal(data.seen.length, 0);
        assert.equal(input.laImageEditor.pending, false);
        assert.equal(w.document.querySelector('[role=status]').textContent, 'Failed');
    } finally { w.close(); }
});

test('bundled Cropper 2.3.1 exports the constructor and selection API used by the adapter', () => {
    const dom = new JSDOM('', {runScripts: 'outside-only'});
    try {
        const w = dom.window;
        w.eval(fs.readFileSync(path.join(root, 'resources/assets/cropperjs/cropper.min.js'), 'utf8'));
        assert.equal(w.Cropper.Cropper.version, '2.3.1');
        const selection = w.document.createElement('cropper-selection');
        assert.equal(typeof selection.$toCanvas, 'function');
        assert.equal(typeof selection.$nextTick, 'function');
        assert.equal(typeof w.document.createElement('cropper-image').$ready, 'function');
    } finally { dom.window.close(); }
});
