import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const operationMethods = new Set(['delete', 'get', 'head', 'options', 'patch', 'post', 'put', 'trace']);

const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot = path.resolve(packageRoot, '..', '..');

const sourceSchemaPath = process.env.ORBIT_OPENAPI_SCHEMA
    ? path.resolve(process.env.ORBIT_OPENAPI_SCHEMA)
    : path.join(repoRoot, '.orbit', 'evidence', 'gateway-openapi.json');

const surfacePath = path.join(repoRoot, 'apps', 'gateway', 'openapi-sdk-surface.json');
const outputSchemaPath = path.join(packageRoot, 'openapi', 'public-gateway-openapi.json');
const evidencePath = path.join(repoRoot, '.orbit', 'evidence', 'typescript-sdk-public-openapi-summary.json');

const schema = JSON.parse(await readFile(sourceSchemaPath, 'utf8'));
const surface = JSON.parse(await readFile(surfacePath, 'utf8'));

const classifiedOperations = new Map();

for (const row of surface.schema_only_operations ?? []) {
    classifiedOperations.set(row.operation, row);
}

const removedOperations = [];
const includedClassifiedOperations = [];
const outputPaths = {};

for (const [routePath, pathItem] of Object.entries(schema.paths ?? {})) {
    if (pathItem === null || typeof pathItem !== 'object' || Array.isArray(pathItem)) {
        outputPaths[routePath] = pathItem;

        continue;
    }

    const filteredPathItem = {};

    for (const [key, value] of Object.entries(pathItem)) {
        if (! operationMethods.has(key)) {
            filteredPathItem[key] = value;

            continue;
        }

        const operationKey = `${key.toUpperCase()} ${routePath}`;
        const classification = classifiedOperations.get(operationKey);

        if (classification?.classification === 'internal_only' || classification?.classification === 'deferred_optional') {
            removedOperations.push({
                operation: operationKey,
                classification: classification.classification,
                operation_id: value?.operationId ?? null,
            });

            continue;
        }

        if (classification?.classification === 'public_sdk') {
            includedClassifiedOperations.push({
                operation: operationKey,
                operation_id: value?.operationId ?? null,
                group: classification.group,
            });
        }

        filteredPathItem[key] = value;
    }

    const operationCount = Object.keys(filteredPathItem).filter((key) => operationMethods.has(key)).length;
    const nonOperationKeys = Object.keys(filteredPathItem).filter((key) => ! operationMethods.has(key));

    if (operationCount > 0 || nonOperationKeys.length > 0) {
        outputPaths[routePath] = filteredPathItem;
    }
}

const outputSchema = {
    ...schema,
    info: {
        ...schema.info,
        title: `${schema.info?.title ?? 'Orbit Gateway API'} Public SDK`,
        description: [
            schema.info?.description,
            'Filtered for generated public SDK clients from apps/gateway/openapi-sdk-surface.json.',
        ].filter(Boolean).join('\n\n'),
    },
    paths: outputPaths,
    'x-orbit-sdk-surface': {
        source: 'apps/gateway/openapi-sdk-surface.json',
        public_schema_only_operations: includedClassifiedOperations.length,
        removed_internal_or_deferred_operations: removedOperations.length,
    },
};

const outputOperationKeys = new Set();

for (const [routePath, pathItem] of Object.entries(outputSchema.paths ?? {})) {
    if (pathItem === null || typeof pathItem !== 'object' || Array.isArray(pathItem)) {
        continue;
    }

    for (const key of Object.keys(pathItem)) {
        if (operationMethods.has(key)) {
            outputOperationKeys.add(`${key.toUpperCase()} ${routePath}`);
        }
    }
}

const leakedOperations = [...classifiedOperations.values()]
    .filter((row) => row.classification !== 'public_sdk')
    .filter((row) => outputOperationKeys.has(row.operation));

const missingPublicOperations = [...classifiedOperations.values()]
    .filter((row) => row.classification === 'public_sdk')
    .filter((row) => ! outputOperationKeys.has(row.operation));

if (leakedOperations.length > 0 || missingPublicOperations.length > 0) {
    throw new Error(JSON.stringify({
        message: 'Filtered public OpenAPI surface does not match the classified SDK surface.',
        leaked_operations: leakedOperations.map((row) => row.operation),
        missing_public_operations: missingPublicOperations.map((row) => row.operation),
    }, null, 2));
}

const summary = {
    generated_at: new Date().toISOString(),
    source_schema: path.relative(repoRoot, sourceSchemaPath),
    source_surface: path.relative(repoRoot, surfacePath),
    output_schema: path.relative(repoRoot, outputSchemaPath),
    input_paths: Object.keys(schema.paths ?? {}).length,
    output_paths: Object.keys(outputSchema.paths ?? {}).length,
    input_operations: countOperations(schema.paths ?? {}),
    output_operations: outputOperationKeys.size,
    included_public_schema_only_operations: includedClassifiedOperations,
    removed_internal_or_deferred_operations: removedOperations,
};

await mkdir(path.dirname(outputSchemaPath), { recursive: true });
await mkdir(path.dirname(evidencePath), { recursive: true });
await writeFile(outputSchemaPath, `${JSON.stringify(outputSchema, null, 2)}\n`);
await writeFile(evidencePath, `${JSON.stringify(summary, null, 2)}\n`);

console.log(`Wrote ${path.relative(repoRoot, outputSchemaPath)} with ${summary.output_operations} operations.`);
console.log(`Wrote ${path.relative(repoRoot, evidencePath)}.`);

function countOperations(paths) {
    let count = 0;

    for (const pathItem of Object.values(paths)) {
        if (pathItem === null || typeof pathItem !== 'object' || Array.isArray(pathItem)) {
            continue;
        }

        count += Object.keys(pathItem).filter((key) => operationMethods.has(key)).length;
    }

    return count;
}
