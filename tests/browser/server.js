const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '../..');
const assets = path.join(root, 'resources/assets');
const fixture = path.join(__dirname, 'fixture.html');
const port = Number(process.env.PORT || 4173);

const types = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.gif': 'image/gif',
    '.svg': 'image/svg+xml',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.ttf': 'font/ttf',
    '.eot': 'application/vnd.ms-fontobject',
};

function send(res, status, body, type) {
    res.writeHead(status, {'Content-Type': type || 'text/plain; charset=utf-8'});
    res.end(body);
}

const server = http.createServer((req, res) => {
    const url = new URL(req.url, `http://${req.headers.host || '127.0.0.1'}`);

    if (url.pathname === '/' || url.pathname === '/index.html') {
        return send(res, 200, fs.readFileSync(fixture), types['.html']);
    }

    if (url.pathname === '/favicon.ico') {
        res.writeHead(204);
        return res.end();
    }

    const prefix = '/vendor/laravel-admin/';
    if (url.pathname.startsWith(prefix)) {
        const relative = decodeURIComponent(url.pathname.slice(prefix.length));
        const file = path.resolve(assets, relative);

        if (!file.startsWith(path.resolve(assets) + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
            return send(res, 404, 'Not found');
        }

        return send(res, 200, fs.readFileSync(file), types[path.extname(file).toLowerCase()]);
    }

    return send(res, 404, 'Not found');
});

server.listen(port, '127.0.0.1', () => {
    console.log(`Fixture server listening on http://127.0.0.1:${port}`);
});
