/** Verified boundaries for the image cases; the shipped task sequence is divided, never rewritten. */
import { assertEquals } from "jsr:@std/assert@1";
import { fixture } from "./instance_host.ts";

type Fixture = Awaited<ReturnType<typeof fixture>>;
type Entry = { mode: number; text?: string; artifact?: string; source?: string; link?: string; directory?: true };
export type Boundary = { entries: Record<string, Entry> };
const roots = ["images", "arc", "arc.conf", "subuid", "subgid", "sysctl", "modules-load", "systemd", "libexec", "bridge-policy",
    "container-libraries", "opt", "cache", "debs", "egress", "etc-orbit", "dev-ports.nft", "state.json", "remote-state.json", "calls", "targets", "nested-image.json", "boundary-facts.json"];

function substitutions(f: Fixture): [string, string][] {
    const pairs: [string, string][] = [[`${f.folder}/unix.socket`, "@SOCKET@"], [`/proc/${Deno.readTextFileSync(`${f.folder}/daemon.pid`).trim()}`, "/proc/@DAEMON@"], [f.folder, "@FIXTURE@"], [Deno.readTextFileSync(`${f.folder}/orbit-root-ca.crt`), "@ROOT_CA@"]];
    const visit = (value: unknown, path: string) => {
        if (typeof value === "string" && /^[a-f0-9]{64}$/.test(value)) pairs.push([value, `@DIGEST:${path}@`]);
        else if (value && typeof value === "object") for (const [key, child] of Object.entries(value)) visit(child, `${path}/${key}`);
    };
    visit(f.instances, "instances");
    const hash = new Deno.Command("openssl", { args: ["x509", "-hash", "-noout", "-in", `${f.folder}/orbit-root-ca.crt`] }).outputSync();
    if (!hash.success) throw new Error("Cannot read fixture CA hash");
    pairs.push([new TextDecoder().decode(hash.stdout).trim(), "@ROOT_CA_HASH@"]);
    // JSON files and scripts can contain escaped copies of the same values.
    return pairs.flatMap(([value, token]) => [[JSON.stringify(value).slice(1, -1), token], [value, token]] as [string, string][])
        .filter(([value, token], i, all) => all.findIndex(([v, t]) => v === value && t === token) === i)
        .sort((a, b) => b[0].length - a[0].length);
}

function replace(value: string, pairs: [string, string][]) {
    for (const [from, to] of pairs) value = value.replaceAll(from, to);
    return value;
}

export function capture(f: Fixture): Boundary {
    const pairs = substitutions(f), entries: Record<string, Entry> = {};
    const sources = [...Deno.readDirSync(f.folder)].filter((e) => e.isFile && e.name.endsWith(".py")).map((e) => e.name);
    sources.push("files/instance-container-wake.sh");
    const artifacts = [...Deno.readDirSync(`${f.folder}/artifacts`)].filter((e) => e.isFile)
        .map((e) => [e.name, Deno.readFileSync(`${f.folder}/artifacts/${e.name}`)] as const);
    const walk = (path: string) => {
        const file = `${f.folder}/${path}`, stat = Deno.lstatSync(file), key = replace(path, pairs);
        const entry: Entry = { mode: stat.mode! & 0o777 };
        if (stat.isSymlink) entry.link = replace(Deno.readLinkSync(file), pairs);
        else if (stat.isDirectory) {
            entry.directory = true;
            for (const child of [...Deno.readDirSync(file)].sort((a, b) => a.name.localeCompare(b.name))) walk(`${path}/${child.name}`);
        } else {
            const bytes = Deno.readFileSync(file);
            const artifact = artifacts.find(([, content]) => content.length === bytes.length && content.every((byte, i) => byte === bytes[i]));
            const source = sources.find((name) => { const content = Deno.readFileSync(`${f.folder}/${name}`); return content.length === bytes.length && content.every((byte, i) => byte === bytes[i]); });
            if (artifact) entry.artifact = artifact[0];
            else if (source) entry.source = source;
            else entry.text = replace(new TextDecoder("utf-8", { fatal: true }).decode(bytes), pairs);
        }
        entries[key] = entry;
    };
    for (const path of [...roots, ...[...Deno.readDirSync(f.folder)].filter((e) => e.name.startsWith("firewall-")).map((e) => e.name)]) {
        try { Deno.lstatSync(`${f.folder}/${path}`); } catch (error) { if (error instanceof Deno.errors.NotFound) continue; throw error; }
        walk(path);
    }
    return { entries: Object.fromEntries(Object.entries(entries).sort(([a], [b]) => a.localeCompare(b))) };
}

export function restore(f: Fixture, boundary: Boundary) {
    // Decode JSON-escaped values according to the destination file, rather than copying a previous fixture's paths or certificate.
    const pairs = substitutions(f).filter(([value]) => !value.includes("\\n")).map(([value, token]) => [token, value] as [string, string]);
    for (const path of roots) {
        try { Deno.removeSync(`${f.folder}/${path}`, { recursive: true }); } catch (error) { if (!(error instanceof Deno.errors.NotFound)) throw error; }
    }
    const ordered = Object.entries(boundary.entries).sort(([a], [b]) => a.split("/").length - b.split("/").length);
    for (const [key, entry] of ordered) {
        const path = replace(key, pairs), file = `${f.folder}/${path}`;
        Deno.mkdirSync(file.slice(0, file.lastIndexOf("/")), { recursive: true });
        if (entry.directory) Deno.mkdirSync(file, { recursive: true });
        else if (entry.link !== undefined) Deno.symlinkSync(replace(entry.link, pairs), file);
        else if (entry.source) Deno.copyFileSync(`${f.folder}/${entry.source}`, file);
        else if (entry.artifact) Deno.copyFileSync(`${f.folder}/artifacts/${entry.artifact}`, file);
        else {
            const values = path.endsWith(".json") || path === "calls" || path === "targets"
                ? pairs.map(([token, value]) => [token, JSON.stringify(value).slice(1, -1)] as [string, string]) : pairs;
            Deno.writeTextFileSync(file, replace(entry.text!, values));
        }
        if (!entry.link) Deno.chmodSync(file, entry.mode);
    }
    assertEquals(capture(f), boundary);
}

export type Stage = "system" | "prepare" | "normal" | "nested" | "finish";
export function stages(f: Fixture) {
    const path = `${f.folder}/tasks/instance-host.yml`;
    const tasks = JSON.parse(Deno.readTextFileSync(path));
    const prepared = tasks.findIndex((t: { name: string }) => t.name === "Read the common NIC ACL");
    const normal = tasks.findIndex((t: { name: string }) => t.name === "Build the Instance image");
    const nested = tasks.findIndex((t: { name: string }) => t.name === "Build the nested-fleet Instance image");
    const finish = tasks.findIndex((t: { name: string }) => t.name === "Check every Instance bridge NIC at the end of the role");
    assertEquals([prepared > 0, normal > prepared, nested === normal + 1, finish === nested + 1], [true, true, true, true]);
    const ranges = { system: [0, prepared], prepare: [prepared, normal], normal: [normal, nested], nested: [nested, finish], finish: [finish, tasks.length] };
    return async (stage: Stage) => {
        const [start, end] = ranges[stage];
        const saved = f.has("boundary-facts.json") ? [{ "ansible.builtin.include_vars": `${f.folder}/boundary-facts.json` }] : [];
        Deno.writeTextFileSync(path, JSON.stringify([...saved, ...tasks.slice(start, end), {
            name: "Save the image fixture boundary facts",
            "ansible.builtin.copy": { dest: `${f.folder}/boundary-facts.json`, content: "{{ dict(instance_pool_read=dict(rc=instance_pool_read.rc, stdout=instance_pool_read.stdout), container_arc_max=container_arc_max | default(0), container_image_directory=container_image_directory | default(''), container_image_socket=container_image_socket | default('')) | to_json }}", mode: "0600" },
            changed_when: false,
        }]));
        return await f.run();
    };
}
