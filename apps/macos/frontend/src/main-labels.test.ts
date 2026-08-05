import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const mainSource = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), 'main.ts'),
    'utf8',
);

test('node detail workload tables use App headers, not Project', () => {
    assert.match(mainSource, /\['Process', 'App', 'Runtime', 'Status'\]/);
    assert.match(mainSource, /\['App', 'Instance', 'Environment', 'Status'\]/);
    assert.doesNotMatch(mainSource, /\['Process', 'Project', 'Runtime', 'Status'\]/);
    assert.doesNotMatch(mainSource, /\['Project', 'Instance', 'Environment', 'Status'\]/);
});
