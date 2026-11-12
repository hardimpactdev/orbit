/** Resolve a constant CEL behavior name or workflow target; leave computed expressions intact. */
export function resolveConstantName(target: string): string {
    return /^\$\{\{\s*"([\w.-]+)"\s*\}\}$/.exec(target)?.[1] ?? target;
}
