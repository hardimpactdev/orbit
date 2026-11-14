/** Capture saved image boundaries from real shipped stages. No saved state is used as a producer. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./instance_host.ts";
import { type Boundary, capture, restore, type Stage, stages } from "./host_image_boundary.ts";

const destination = new URL("../fixtures/host-image-boundaries.json", import.meta.url);
const parents: Record<string, string | null> = {
    "dir-system": null,
    "dir-prepare": "dir-system",
    "dir-finish": "dir-prepare",
    "zfs-system": "dir-system",
    "zfs-prepare": "zfs-system",
    "zfs-finish": "zfs-prepare",
    "nested-system": "dir-system",
    "nested-prepare": "nested-system",
    "nested-normal": "nested-prepare",
    "nested-finish": "nested-normal",
    "nested-bad-digest": "nested-normal",
    "zfs-remote-replaced": "zfs-finish",
};
const phases: Stage[] = ["system", "prepare", "normal", "nested", "finish"];
type Kind = "dir" | "zfs" | "nested";
const options = (kind: Kind) => ({ pool: kind === "zfs" ? "zfs" : "dir", nested: kind === "nested" });
type Fixture = Awaited<ReturnType<typeof fixture>>;

async function run(runStage: ReturnType<typeof stages>, phase: Stage, label: string) {
    const result = await runStage(phase);
    assert(result.ok, `${label}/${phase}: ${result.output}`);
    console.log(`${label}/${phase}: passed`);
}

async function captureKind(kind: Kind): Promise<Record<string, Boundary>> {
    const f = await fixture(options(kind));
    try {
        const runStage = stages(f);
        const captured: Record<string, Boundary> = {};
        for (const phase of phases) {
            await run(runStage, phase, kind);
            captured[`${kind}-${phase}`] = capture(f);
        }
        return captured;
    } finally {
        f.cleanup();
    }
}

async function captureVariant(kind: Kind, boundary: Boundary, prepare: (f: Fixture) => void,
    phases: Stage[], label: string): Promise<Boundary> {
    const f = await fixture(options(kind));
    try {
        const runStage = stages(f);
        // restore itself asserts an exact capture round-trip in the fresh fixture.
        restore(f, boundary);
        prepare(f);
        for (const phase of phases) await run(runStage, phase, label);
        return capture(f);
    } finally {
        f.cleanup();
    }
}

function pack(captured: Record<string, Boundary>) {
    type Entry = Boundary["entries"][string];
    const packed: Record<string, { from: string | null; entries: Record<string, Entry & { append?: string } | null> }> = {};
    for (const [name, parent] of Object.entries(parents)) {
        const before = parent ? captured[parent].entries : {};
        const after = captured[name].entries;
        const entries: Record<string, Entry & { append?: string } | null> = {};
        for (const path of [...new Set([...Object.keys(before), ...Object.keys(after)])].sort()) {
            if (JSON.stringify(before[path]) === JSON.stringify(after[path])) continue;
            const entry = after[path];
            const previous = before[path]?.text;
            if ((path === "calls" || path === "targets") && previous !== undefined && entry?.text?.startsWith(previous)) {
                entries[path] = { mode: entry.mode, append: entry.text.slice(previous.length) };
            } else {
                entries[path] = entry ?? null;
            }
        }
        packed[name] = { from: parent, entries };
    }
    return packed;
}

if (Deno.args.length !== 1 || !["--check", "--write"].includes(Deno.args[0])) {
    throw new Error("Usage: deno run -A swamp/tests/_support/regenerate_host_image_boundaries.ts --check|--write");
}
assert(hasAnsible, "Put the pinned Ansible tools on PATH before capturing image boundaries");
// Three independent scratch fixtures; settle all of them so every cleanup runs on failure.
const results = await Promise.allSettled((["dir", "zfs", "nested"] as const).map(captureKind));
const captured: Record<string, Boundary> = {};
for (const result of results) {
    if (result.status === "rejected") throw result.reason;
    Object.assign(captured, result.value);
}
captured["zfs-remote-replaced"] = await captureVariant("zfs", captured["zfs-finish"], (f) => {
    const remote = f.state();
    Deno.writeTextFileSync(`${f.folder}/remote-state.json`, JSON.stringify(remote));
    Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify({ ...remote, default_remote: true }));
    f.write({ ...f.instances, image: { ...f.instances.image, alias: "orbit-instance-bbbbbbbbbbbb" } });
}, ["prepare", "normal", "nested", "finish"], "zfs-remote-replaced");
captured["nested-bad-digest"] = await captureVariant("nested", captured["nested-normal"], (f) => {
    const broken = structuredClone(f.instances);
    broken.nested!.image.incus[1].sha256 = "b".repeat(64);
    f.write(broken);
}, ["nested", "finish"], "nested-bad-digest");

const packed = pack(captured);
if (Deno.args[0] === "--check") {
    assertEquals(packed, JSON.parse(Deno.readTextFileSync(destination)));
    console.log("All twelve captured boundaries match the saved file.");
} else {
    // Write only after every capture and restore succeeded.
    Deno.writeTextFileSync(destination, JSON.stringify(packed, null, 2) + "\n");
    console.log(`Regenerated all twelve boundaries in ${destination.pathname}; review the diff before committing.`);
}
