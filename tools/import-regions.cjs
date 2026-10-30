// Convert a parent-code map ({ "0": [{ c, n }], ... }) into a name-only region tree.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const input = process.argv[2];
if (!input) throw new Error('Usage: node tools/import-regions.cjs /path/to/0.json');
const bytes = fs.readFileSync(input);
const data = JSON.parse(bytes.toString('utf8'));
if (!Array.isArray(data['0']) || !data['0'].length) throw new Error('Missing province list.');
function children(code) {
    const nodes = data[code] ?? [];
    if (!Array.isArray(nodes)) throw new Error('Invalid children under ' + code);
    const names = new Set();
    for (const node of nodes) {
        if (!node || !['number', 'string'].includes(typeof node.c) || typeof node.n !== 'string' || !node.n || names.has(node.n)) {
            throw new Error('Invalid or duplicate name under ' + code);
        }
        names.add(node.n);
    }
    return nodes;
}
const regions = Object.create(null), counts = [0, 0, 0];
for (const province of children('0')) {
    const cities = Object.create(null);
    counts[0]++;
    for (const city of children(province.c)) {
        counts[1]++;
        const districts = children(city.c);
        counts[2] += districts.length;
        for (const district of districts) {
            if (children(district.c).length) throw new Error('Only three-level data is supported.');
        }
        cities[city.n] = districts.map(district => district.n);
    }
    regions[province.n] = cities;
}
const destination = path.join(__dirname, '../resources/data/china-regions.json');
fs.writeFileSync(destination, JSON.stringify(regions, null, 2) + '\n');
console.log('Imported province/city/district counts: ' + counts.join('/'));
console.log('Input SHA256: ' + crypto.createHash('sha256').update(bytes).digest('hex'));
