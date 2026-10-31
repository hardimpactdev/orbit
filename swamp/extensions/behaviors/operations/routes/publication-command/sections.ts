/** Pure format-aware policy; no host I/O, scripts, locks or transport. */
function applyDelta<T>(
    before: Map<string, T>,
    after: Map<string, T>,
    current: Map<string, T>,
    family: string,
) {
    const result = new Map(current);
    const same = (a: T | undefined, b: T | undefined) =>
        JSON.stringify(a) === JSON.stringify(b);
    for (const key of new Set([...before.keys(), ...after.keys()])) {
        const previous = before.get(key),
            desired = after.get(key),
            actual = current.get(key);
        if (same(previous, desired)) continue;
        if (!same(actual, previous) && !same(actual, desired)) {
            throw new Error(`${family}.owned_revision_conflict`);
        }
        if (desired === undefined) result.delete(key);
        else result.set(key, desired);
    }
    return result;
}
export function caddySections(content: string) {
    if (
        !content.startsWith("# Managed by Orbit: Node Caddy build\n") ||
        content.includes("\0")
    ) throw new Error("caddy.foreign_config");
    const matches = [...content.matchAll(/^# orbit: ([^\s]+) ([^\r\n]+)\n/gm)];
    const header =
        content.slice(0, matches[0]?.index ?? content.length).replace(
            /\n+$/,
            "",
        ) + "\n";
    const groups = new Map<string, string[]>();
    matches.forEach((match, i) => {
        const key = `${match[1]}\0${match[2]}`;
        const block =
            content.slice(match.index, matches[i + 1]?.index ?? content.length)
                .replace(/\n+$/, "") + "\n";
        groups.set(key, [...(groups.get(key) ?? []), block]);
    });
    return { header, groups };
}
export function mergeCaddy(before: string, after: string, current: string) {
    const original = caddySections(before), desired = caddySections(after);
    if (!current) {
        if (original.groups.size) {
            throw new Error("caddy.current_config_missing");
        }
        current = original.header;
    }
    const observed = caddySections(current);
    if (
        original.header !== desired.header ||
        observed.header !== original.header
    ) throw new Error("caddy.global_revision_conflict");
    const groups = applyDelta(
        original.groups,
        desired.groups,
        observed.groups,
        "caddy",
    );
    const blocks = [...groups.values()].flat();
    return original.header + (blocks.length ? "\n" + blocks.join("\n") : "");
}
export function phpSections(text: string) {
    let prefix = "", name: string | undefined;
    const groups = new Map<string, string>(), directories = new Set<string>();
    for (const line of text.match(/[^\n]*\n|[^\n]+$/g) ?? []) {
        const trimmed = line.trim();
        if (trimmed.startsWith("[")) {
            name = trimmed.slice(1, -1);
            if (
                !trimmed.endsWith("]") ||
                !/^orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?$/
                    .test(name) ||
                groups.has(name)
            ) throw new Error("php.shared_pool_invalid");
            groups.set(name, "");
        }
        if (name !== undefined) {
            if (/^chdir\s*=/.test(trimmed)) {
                const path = trimmed.slice(trimmed.indexOf("=") + 1).trim();
                if (
                    directories.has(name) || !path.startsWith("/") ||
                    /[\x00-\x1f]/.test(path)
                ) throw new Error("php.shared_pool_invalid");
                directories.add(name);
            }
            groups.set(name, groups.get(name)! + line);
        } else if (!trimmed || /^[;#]/.test(trimmed)) prefix += line;
        else throw new Error("php.shared_pool_invalid");
    }
    return { prefix, groups };
}
export function mergePhp(before: string, after: string, current: string) {
    const original = phpSections(before),
        desired = phpSections(after),
        observed = phpSections(current);
    const groups = applyDelta(
        original.groups,
        desired.groups,
        observed.groups,
        "php",
    );
    return observed.groups.size && !groups.size ? "" : observed.prefix +
        [...groups.keys()].sort().map((key) => groups.get(key)).join("");
}
