import { parse } from "jsr:@std/yaml@1.0.12";

/**
 * The Ansible files each skippable node.converge phase can load, resolved statically from the staged bundle
 * (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change).
 *
 * A phase starts at the tasks of the converge playbooks that `--tags <phase>` selects: a task or play tagged with the phase or `always`,
 * and every file that the playbooks import statically and that names the phase or `always` in a tag. From there it follows every bundle
 * file a reached file names: by relative path, by file name, or by module name for a Python file (Ansible modules, module_utils and
 * imported helpers). A directory a reached file names, such as a `copy` source or a fileglob, brings every file under it. Shared
 * infrastructure is in every phase. A reference the resolver cannot name, such as a templated include, makes that phase read the whole
 * bundle.
 *
 * Every bundle file is in exactly one class (classify()): (b) implicit, a file any Ansible run may load without a name, is in every phase;
 * (a) a file a phase walk reaches is in that phase; (c) a file only the walk of another Operation's playbook reaches is in no phase, since a
 * converge phase loads a file only through a name its walk sees or through an implicit location; (d) a file nothing reaches is in every phase.
 */

export const phases = ["agent", "software", "roles", "tools", "network"] as const;
export type Phase = typeof phases[number];

/** The playbooks a node.converge runs. The roles phase may run the local DNS wrapper instead (workflow-operation-node-converge-direct). */
export const entries: Record<Phase, string[]> = {
    agent: ["operation-node-converge.yml"],
    software: ["operation-node-converge.yml"],
    roles: ["operation-node-converge.yml", "operation-node-converge-local-dns.yml"],
    tools: ["operation-node-converge.yml"],
    network: ["operation-node-converge.yml"],
};

/** Read by every phase: the configuration Ansible loads, the playbooks it parses whole, and the role materializer that writes each role's
 * variables. Each phase also follows the names in the shared files. */
export const shared = ["ansible.cfg", "build_vars.py", ...new Set(Object.values(entries).flat())];

/** `everyPhase` holds the bundle files of classes (b) and (d); each is in every phase that does not read the whole bundle. */
/** `outside` holds the files a phase names above the bundle, such as `{{ playbook_dir }}/../scripts/x`, relative to the bundle root. */
export type Resolution = { files: string[]; whole: false; everyPhase: string[]; outside: string[] }
    | { files: null; whole: true; reason: string; everyPhase: string[]; outside: string[] };

/** A relative path joined to a folder of the bundle, with `.` and `..` folded; a result that starts with `../` is above the bundle. */
function fold(folder: string, path: string): string {
    const parts: string[] = [];
    for (const part of [...folder.split("/"), ...path.split("/")]) {
        if (part === "" || part === ".") continue;
        if (part === ".." && parts.length && parts[parts.length - 1] !== "..") parts.pop();
        else parts.push(part);
    }
    return parts.join("/");
}

/** The relative modules a JavaScript or TypeScript file imports, folded from its own folder: `from "../x.ts"`, `import "./x.ts"` or
 * `import("../x.ts")`. A path is relative to the bundle root; one that starts with `../` is above the bundle. */
export function importNames(file: string, text: string): string[] {
    if (!/\.(m?[jt]s|tsx?)$/.test(file)) return [];
    const folder = file.includes("/") ? file.slice(0, file.lastIndexOf("/")) : "";
    return [...new Set([...text.matchAll(/(?:\bfrom\s*|\bimport\s*\(?\s*)["'](\.{1,2}\/[^"']+)["']/g)].map((match) => fold(folder, match[1])))];
}

/** The files above the bundle that a text names: a path from the playbook folder that climbs out of it, a local source that climbs
 * out of the bundle from its own file, or a module a script imports from above the bundle. */
export function outsideNames(file: string, text: string): string[] {
    const found = new Set<string>(importNames(file, text).filter((path) => path.startsWith("../")));
    for (const match of text.matchAll(/\bplaybook_dir\s*(?:\}\}\s*\/|(?:~|\+)\s*'\/)((?:\.\.\/)+[^"'{}\s,)\]]+)/g)) {
        const path = fold("", match[1]);
        if (path.startsWith("../")) found.add(path);
    }
    let documents: unknown = null;
    try {
        documents = parse(text);
    } catch {
        // A task file that does not parse already makes its phase read the whole bundle (unresolved()).
    }
    const walk = (node: unknown): void => {
        if (Array.isArray(node)) return node.forEach(walk);
        if (node === null || typeof node !== "object") return;
        for (const [key, value] of Object.entries(node)) {
            if (localSource.test(key)) {
                const args = typeof value === "string" ? Object.fromEntries(value.split(/\s+/).map((pair) => pair.split("=", 2))) : value as Record<string, unknown> | null;
                const remote = args?.remote_src === true || args?.remote_src === "true" || args?.remote_src === "yes";
                if (typeof args?.src === "string" && args.src.startsWith("../") && !remote) {
                    const path = fold(file.includes("/") ? file.slice(0, file.lastIndexOf("/")) : "", args.src);
                    if (path.startsWith("../")) found.add(path);
                }
            }
            walk(value);
        }
    };
    if (/\.ya?ml$/.test(file) || text.trimStart().startsWith("{")) walk(documents);
    return [...found];
}

/** The class of each bundle file: `phases` names the phases of a class (a) file. */
export type Classes = { a: Map<string, Phase[]>; b: string[]; c: string[]; d: string[] };

/** Class (b): what Ansible may load without a name. The configuration, the collection and Python pins, the role materializer, and every
 * plugin, variable and role folder that Ansible searches by itself. A relative `copy` or `template` source is found in `files/` or
 * `templates/` only by its name, which the walk sees, so those folders are not implicit. */
const implicitFile = /^(ansible\.cfg|build_vars\.py|galaxy\.ya?ml|[^/]*requirements[^/]*\.(txt|ya?ml))$/;
const implicitFolder = /(^|\/)(library|module_utils|[a-z]+_plugins|group_vars|host_vars|roles|collections|vars|defaults|meta)\//;
export const implicit = (file: string) => implicitFile.test(file) || implicitFolder.test(file);

type Bundle = { files: string[]; texts: Map<string, string>; byPath: Map<string, string[]>; byStem: Map<string, string[]>; byDir: Map<string, string[]> };

async function bundle(root: string): Promise<Bundle> {
    const files: string[] = [];
    const walk = async (relative: string): Promise<void> => {
        const entries = [];
        for await (const entry of Deno.readDir(`${root}${relative ? `/${relative}` : ""}`)) entries.push(entry);
        for (const entry of entries.sort((a, b) => a.name < b.name ? -1 : 1)) {
            const path = relative ? `${relative}/${entry.name}` : entry.name;
            // Python's byte code is derived from the source beside it, and Git ignores it; a local test run must not move a digest.
            if (entry.isDirectory && entry.name !== "__pycache__") await walk(path);
            else if (entry.isFile) files.push(path);
        }
    };
    await walk("");
    const texts = new Map<string, string>();
    const decoder = new TextDecoder();
    for (const file of files) texts.set(file, decoder.decode(await Deno.readFile(`${root}/${file}`)));
    const byPath = new Map<string, string[]>();
    const byStem = new Map<string, string[]>();
    const byDir = new Map<string, string[]>();
    const add = (map: Map<string, string[]>, key: string, file: string) => map.set(key, [...(map.get(key) ?? []), file]);
    for (const file of files) {
        // Every path suffix: `tasks/a.yml` is named as `tasks/a.yml` or `a.yml`.
        const parts = file.split("/");
        for (let index = 0; index < parts.length; index++) add(byPath, parts.slice(index).join("/"), file);
        if (file.endsWith(".py")) add(byStem, parts[parts.length - 1].slice(0, -3), file);
        // Every suffix of every folder above it: `files/a/b.conf` is under `files/a` and `a`.
        for (let end = 1; end < parts.length; end++) for (let index = 0; index < end; index++) add(byDir, parts.slice(index, end).join("/"), file);
    }
    return { files, texts, byPath, byStem, byDir };
}

/** The bundle files a text names. A folder counts with every file under it when the text names it as a path of two or more parts, with a
 * trailing slash, or as a local source or fileglob. */
export function named(source: string, byPath: Map<string, string[]>, byStem: Map<string, string[]>, byDir: Map<string, string[]> = new Map()): string[] {
    // A full-line comment loads nothing.
    const text = source.replace(/^\s*#.*$/gm, "");
    const found = new Set<string>();
    const folder = (path: string, single: boolean) => {
        const parts = path.split("/");
        for (let index = 0; index < parts.length; index++) {
            if (!single && parts.length - index < 2) continue;
            for (const file of byDir.get(parts.slice(index).join("/")) ?? []) found.add(file);
        }
    };
    for (const token of text.match(/[A-Za-z0-9_.\/-]+/g) ?? []) {
        const path = token.replace(/^(\.\.?\/)+/, "").replace(/[.\/-]+$/, "");
        const parts = path.split("/");
        for (let index = 0; index < parts.length; index++) for (const file of byPath.get(parts.slice(index).join("/")) ?? []) found.add(file);
        folder(path, token.endsWith("/"));
    }
    // A local source or fileglob names its folder, even by one part: `src: tools/` or `with_fileglob: tools/*`.
    for (const match of text.matchAll(/(?:\bsrc["']?\s*[:=]|\bfileglob["']\s*,|\bwith_fileglob["']?\s*:\s*\[?(?:\s*-)?)\s*["']?([^\s"',}\]]+)/g)) {
        const glob = match[1].search(/[*?[]/);
        const path = (glob < 0 ? match[1] : match[1].slice(0, match[1].lastIndexOf("/", glob) + 1)).replace(/^(\.\.?\/)+/, "").replace(/\/+$/, "");
        if (path) folder(path, true);
        // Ansible searches a relative glob in several folders (`files/` and `templates/` beside the role, playbook or task file). Every
        // bundle file whose path, or any end of it, matches the glob counts, which holds every folder it can search.
        if (glob >= 0) {
            const pattern = globPattern(match[1].replace(/^(\.\.?\/)+/, ""));
            for (const [suffix, files] of byPath) if (pattern.test(suffix)) for (const file of files) found.add(file);
        }
    }
    for (const word of text.match(/[A-Za-z_][A-Za-z0-9_]*/g) ?? []) for (const file of byStem.get(word) ?? []) found.add(file);
    return [...found];
}

/** A shell glob as a whole-path pattern: `*` and `?` stay within one path part. */
export function globPattern(glob: string): RegExp {
    let pattern = "";
    for (let index = 0; index < glob.length; index++) {
        const char = glob[index];
        if (char === "*") pattern += "[^/]*";
        else if (char === "?") pattern += "[^/]";
        else if (char === "[") {
            const end = glob.indexOf("]", index + 1);
            if (end < 0) pattern += "\\[";
            else { pattern += `[${glob.slice(index + 1, end).replace(/^!/, "^").replace(/\\/g, "\\\\")}]`; index = end; }
        } else pattern += char.replace(/[.+^${}()|\\]/g, "\\$&");
    }
    return new RegExp(`^${pattern}$`);
}

const runFolder = /^\s*(\(?\s*)?(run|orbit_run|lookup\(\s*'ansible\.builtin\.env'\s*,\s*'ORBIT_RUN'\s*\))\s*(~|\+|\}\})/;
const literal = /^\s*'[^'{}]+'\s*(,|\)|$)/;
const fromPlaybook = /^\s*(playbook_dir)\s*(~|\+)\s*'[^'{}]+'\s*(,|\)|$)/;

/** Why a reached file holds a reference the resolver cannot name, or null. */
export function unresolved(file: string, text: string): string | null {
    // A path computed from the playbook's own folder must end in a literal name.
    for (const match of text.matchAll(/\b(playbook_dir|role_path|inventory_dir|ansible_config_file)\b(.{0,80})/g)) {
        if (match[1] !== "playbook_dir" || !/^\s*((~|\+)\s*'[^'{}]+'(?!\s*(~|\+))|\}\}\/[^"'{}\s]+|\}\}\/\{\{\s*item\b)/.test(match[2])) return `${file}: ${match[0].trim()}`;
    }
    // A file or template lookup of a bundle file must name it literally.
    for (const match of text.matchAll(/\b(lookup|query)\(\s*'([a-z_.]*?)(file|template|fileglob|first_found|filetree|lines|csvfile|ini|unvault)'\s*,([^\n]*)/g)) {
        const argument = match[4];
        // A variable is accepted only when this file defines it under the run folder.
        const variable = argument.match(/^\s*\(?\s*([a-z_][a-z0-9_]*)\s*(,|\))/)?.[1];
        const defined = variable && [...text.matchAll(new RegExp(`\\b${variable}:\\s*["']?\\{\\{(.*)`, "g"))];
        const underRun = defined && defined.length > 0 && defined.every((definition) => runFolder.test(definition[1]));
        if (!literal.test(argument) && !fromPlaybook.test(argument) && !runFolder.test(argument) && !underRun) {
            return `${file}: ${match[0].trim().slice(0, 120)}`;
        }
    }
    // A Jinja include, import or extends must name its template literally; the name then joins the phase like any other name.
    for (const match of text.matchAll(/\{%[-+]?\s*(include|import|extends|from)\s+(.*?)\s*(?:\bimport\b|\bwith\b|\bwithout\b|\bignore\b|[-+]?%\})/gs)) {
        const names = match[2].trim().replace(/\s+as\s+\w+$/, "");
        if (!/^(('[^'{}]*'|"[^"{}]*")|\[\s*(('[^'{}]*'|"[^"{}]*")\s*,?\s*)+\])$/.test(names)) return `${file}: ${match[0].slice(0, 120)}`;
    }
    // A script may run or read itself by its own path; one that opens files beside itself names them dynamically.
    if (file.endsWith(".py") && text.split("\n").some((line) => /\b__file__\b/.test(line) && /\bdirname\b|\.parent\b|join(path)?\(|\.with_\w+\(|Path\(\s*__file__\s*\)(\.resolve\(\))?\s*\//.test(line))) return `${file}: __file__`;
    if (!/\.ya?ml$/.test(file)) return null;
    let documents: unknown;
    try {
        documents = parse(text);
    } catch (error) {
        return `${file}: ${(error as Error).message.split("\n")[0]}`;
    }
    return taskUnresolved(file, documents);
}

const includes = /^(ansible\.builtin\.)?(include_tasks|import_tasks|import_playbook|include_role|import_role|include_vars|include)$/;
const localSource = /^((ansible\.builtin\.)?(template|copy|script|unarchive)|(ansible\.posix\.)?synchronize)$/;

/** A source that is a loop item (`{{ item }}`, `{{ item.src }}`, optionally under `{{ playbook_dir }}/`) whose every value is written
 * literally in the task's own `loop`, `with_items` or `with_list`, or a fileglob item, whose glob names its files. */
function loopItems(record: Record<string, unknown>, source: string): boolean {
    const match = source.match(/^(\{\{\s*playbook_dir\s*\}\}\/)?\{\{\s*item(?:\.(\w+))?\s*\}\}$/);
    if (!match) return false;
    if (!match[1] && !match[2] && record.with_fileglob !== undefined) return true;
    const list = record.loop ?? record.with_items ?? record.with_list;
    if (!Array.isArray(list) || list.length === 0) return false;
    const plain = (value: unknown) => typeof value === "string" && (!value.includes("{{") || /^\{\{\s*playbook_dir\s*\}\}\/[^{}]+$/.test(value));
    return list.every((item) => plain(match[2] ? (item as Record<string, unknown> | null)?.[match[2]] : item));
}

function taskUnresolved(file: string, node: unknown): string | null {
    if (Array.isArray(node)) {
        for (const item of node) {
            const reason = taskUnresolved(file, item);
            if (reason) return reason;
        }
        return null;
    }
    if (node === null || typeof node !== "object") return null;
    const record = node as Record<string, unknown>;
    for (const [key, value] of Object.entries(record)) {
        if (includes.test(key)) {
            const target = typeof value === "string" ? value : (value as Record<string, unknown> | null)?.file ?? (value as Record<string, unknown> | null)?.name;
            if (typeof target !== "string" || (target.includes("{{") && !/^\{\{\s*orbit_run\s*\}\}\//.test(target))) return `${file}: ${key} ${JSON.stringify(target)}`;
        }
        if (/^with_fileglob$/.test(key) && JSON.stringify(value).includes("{{")) return `${file}: ${key} ${JSON.stringify(value)}`;
        if (localSource.test(key)) {
            const args = typeof value === "string" ? Object.fromEntries(value.split(/\s+/).map((pair) => pair.split("=", 2))) : value as Record<string, unknown> | null;
            const source = args?.src;
            const remote = args?.remote_src === true || args?.remote_src === "true" || args?.remote_src === "yes";
            // A loop item names its file literally in the same task, so the file is named in the text.
            const fromLoop = loopItems(record, String(source));
            if (typeof source === "string" && source.includes("{{") && !remote && !fromLoop && !/^\{\{\s*playbook_dir\s*\}\}\/[^{}]+$/.test(source)) {
                return `${file}: ${key} src ${JSON.stringify(source)}`;
            }
        }
        const reason = taskUnresolved(file, value);
        if (reason) return reason;
    }
    return null;
}

type Task = Record<string, unknown>;
const tagsOf = (value: unknown): string[] => Array.isArray(value) ? value.map(String) : typeof value === "string" ? value.split(",").map((tag) => tag.trim()) : [];
const selects = (tags: string[], phase: Phase) => tags.includes(phase) || tags.includes("always");

/** Every tag a task file names, in any `tags` key, including an include's `apply`. A file that does not parse names every tag. */
function tagsIn(text: string): string[] {
    const found: string[] = [];
    const walk = (node: unknown): void => {
        if (Array.isArray(node)) node.forEach(walk);
        else if (node !== null && typeof node === "object") {
            for (const [key, value] of Object.entries(node)) {
                if (key === "tags") found.push(...tagsOf(value));
                walk(value);
            }
        }
    };
    try {
        walk(parse(text));
    } catch {
        return ["always"];
    }
    return found;
}

/** The YAML text of each task `--tags <phase>` selects in a playbook, and of the play keys every phase parses. */
function playbookRoots(text: string, phase: Phase): string[] {
    const plays = parse(text) as Task[];
    if (!Array.isArray(plays)) throw new Error("is no playbook");
    const roots: string[] = [];
    const tasks = (list: unknown, inherited: string[]) => {
        for (const task of Array.isArray(list) ? list as Task[] : []) {
            const tags = [...inherited, ...tagsOf(task.tags)];
            if (Array.isArray(task.block) && !selects(tags, phase)) {
                for (const key of ["block", "rescue", "always"]) tasks(task[key], tags);
            } else if (selects(tags, phase)) roots.push(JSON.stringify(task));
        }
    };
    for (const play of plays) {
        const tags = tagsOf(play.tags);
        // A notified handler runs whatever the tags select, so the handlers are in the play keys every phase reads.
        const { tasks: list, pre_tasks, post_tasks, ...rest } = play;
        roots.push(JSON.stringify(rest));
        for (const section of [pre_tasks, list, post_tasks]) tasks(section, tags);
    }
    return roots;
}

/** Each phase's files, relative to the bundle root, or the whole bundle with the reason. */
export async function resolvePhases(root: string): Promise<Record<Phase, Resolution>> {
    const { files, texts, byPath, byStem, byDir } = await bundle(root);
    const text = (file: string) => texts.get(file) ?? "";
    // The files the playbooks import statically. Ansible parses each of them for every phase, so a task tagged there runs in that phase.
    const statics = new Set<string>();
    const pending = [...new Set(Object.values(entries).flat())];
    while (pending.length) {
        const file = pending.pop()!;
        if (statics.has(file)) continue;
        statics.add(file);
        for (const match of text(file).matchAll(/(import_tasks|import_playbook):\s*(?:\{\s*file:\s*)?["']?([^\s"'{},]+)/g)) {
            const target = files.find((candidate) => candidate === match[2] || candidate === `tasks/${match[2]}` || candidate.endsWith(`/${match[2]}`));
            if (target) pending.push(target);
        }
    }
    const reach = {} as Record<Phase, { reached: Set<string>; reason: string | null; outside: string[] }>;
    for (const phase of phases) {
        const reached = new Set<string>(shared);
        const queue: string[] = [];
        const via = new Map<string, string>();
        const outside = new Set<string>();
        const visit = (names: string[], from = "") => {
            for (const name of names) if (!reached.has(name)) { reached.add(name); queue.push(name); via.set(name, from); }
        };
        // A playbook's tasks of other phases are not followed; its play keys and selected tasks are.
        queue.push(...shared.filter((file) => !file.endsWith(".yml")));
        let reason: string | null = null;
        for (const entry of entries[phase]) {
            try {
                // A selected task or play key of an entry playbook is checked as a task file is.
                for (const root of playbookRoots(text(entry), phase)) {
                    reason ??= unresolved(entry, root);
                    for (const path of outsideNames(entry, root)) outside.add(path);
                    visit(named(root, byPath, byStem, byDir), entry);
                }
            } catch (error) {
                reason ??= `${entry}: ${(error as Error).message.split("\n")[0]}`;
            }
        }
        for (const file of statics) {
            if (entries[phase].includes(file)) continue;
            if (tagsIn(text(file)).some((tag) => tag === phase || tag === "always")) visit([file], "static import");
        }
        // The walk goes on after a reason, so the files no phase names are known whatever falls back.
        while (queue.length) {
            const file = queue.shift()!;
            reason ??= unresolved(file, text(file));
            for (const path of outsideNames(file, text(file))) outside.add(path);
            visit(named(text(file), byPath, byStem, byDir), file);
        }
        if (reason) for (let file = reason.split(":")[0]; file; file = via.get(file) ?? "") reason += ` <- ${via.get(file) || "root"}`;
        reach[phase] = { reached, reason, outside: [...outside].sort() };
    }
    const classes = classOf(files, texts, byPath, byStem, byDir, reach);
    const everyPhase = [...classes.b, ...classes.d].sort();
    const result = {} as Record<Phase, Resolution>;
    for (const phase of phases) {
        const { reached, reason, outside } = reach[phase];
        result[phase] = reason ? { files: null, whole: true, reason, everyPhase, outside }
            : { files: [...new Set([...reached, ...everyPhase])].filter((file) => texts.has(file)).sort(), whole: false, everyPhase, outside };
    }
    lastClasses.set(root, classes);
    return result;
}

const lastClasses = new Map<string, Classes>();

/** Each bundle file's class (see the module comment). */
export async function classify(root: string): Promise<Classes> {
    await resolvePhases(root);
    return lastClasses.get(root)!;
}

/** The playbooks of the bundle other than the converge playbooks: every YAML file that is a list of plays. */
function otherPlaybooks(files: string[], texts: Map<string, string>): string[] {
    const converge = new Set(Object.values(entries).flat());
    return files.filter((file) => {
        if (!/\.ya?ml$/.test(file) || converge.has(file)) return false;
        try {
            const plays = parse(texts.get(file) ?? "");
            return Array.isArray(plays) && plays.length > 0 && plays.every((play) =>
                play !== null && typeof play === "object" && ("hosts" in play || "import_playbook" in play || "ansible.builtin.import_playbook" in play));
        } catch {
            return false;
        }
    });
}

function classOf(files: string[], texts: Map<string, string>, byPath: Map<string, string[]>, byStem: Map<string, string[]>, byDir: Map<string, string[]>,
    reach: Record<Phase, { reached: Set<string> }>): Classes {
    const b = files.filter(implicit);
    const a = new Map<string, Phase[]>();
    for (const file of files) {
        if (implicit(file)) continue;
        const reaching = phases.filter((phase) => reach[phase].reached.has(file));
        if (reaching.length) a.set(file, reaching);
    }
    // The walk of every other playbook, with all its tasks: names only, as a phase walk follows them.
    const other = new Set<string>();
    const queue = otherPlaybooks(files, texts);
    for (const file of queue) other.add(file);
    while (queue.length) {
        for (const name of named(texts.get(queue.shift()!) ?? "", byPath, byStem, byDir)) if (!other.has(name)) { other.add(name); queue.push(name); }
    }
    const c = files.filter((file) => !implicit(file) && !a.has(file) && other.has(file));
    const d = files.filter((file) => !implicit(file) && !a.has(file) && !other.has(file));
    return { a, b, c, d };
}
