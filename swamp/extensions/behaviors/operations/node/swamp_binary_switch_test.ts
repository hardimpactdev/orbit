/**
 * The swamp role's binary switch must never leave a running serve or worker on a deleted binary: Swamp starts
 * children from its own executable path, and a replaced file reads as "/usr/local/bin/swamp (deleted)". The
 * db3f6373 rollout on 2026-11-03 failed every 209 dispatch for a minute that way.
 */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.9";
import { ROOT } from "../../../../bin/gate-test-support.ts";

const WORKFLOW = "operation-node-role-add";
// deno-lint-ignore no-explicit-any
type Step = { name: string; dependsOn?: { step: string }[]; task: any };
const steps = (): Step[] => {
    // deno-lint-ignore no-explicit-any
    const flow = parse(
        Deno.readTextFileSync(`${ROOT}/workflows/workflow-${WORKFLOW}.yaml`),
    ) as any;
    return flow.jobs.flatMap((job: { steps: Step[] }) => job.steps);
};
const step = (name: string) => steps().find((s) => s.name === name)!;
const after = (name: string) => (step(name).dependsOn ?? []).map((d) => d.step);

Deno.test("the role installs beside the running binary, keeps it, links, then writes the units", () => {
    const install = step("Install Swamp").task.globalArgs;
    assertEquals(
        install.path.includes("binary.path"),
        true,
        "the release goes to its own versioned path",
    );
    assertEquals(after("Keep the running Swamp"), ["Install Swamp"]);
    assertEquals(after("Link Swamp"), ["Keep the running Swamp"]);
    assertEquals(after("Apply Swamp serve"), ["Link Swamp"]);
    assertEquals(after("Apply Swamp worker unit"), ["Link Swamp", "Close the Swamp worker credentials"]);
    // Only the rename and the link touch the path serve and the workers run.
    const writers = steps().filter((s) =>
        s.task?.globalArgs?.path === "/usr/local/bin/swamp" &&
        s.task.methodName === "apply"
    );
    assertEquals(writers.map((s) => s.name), ["Link Swamp"]);
    assertEquals(step("Link Swamp").task.behaviorType, "@swamp/cfgmgmt/link");
});

/** The Keep step's script, pointed at a scratch root instead of /usr/local. */
const keepScript = (root: string) => {
    const argv: string[] = step("Keep the running Swamp").task.inputs.argv;
    return argv[argv.indexOf("-c") + 1]
        .replaceAll("/usr/local/bin/swamp", `${root}/bin/swamp`)
        .replaceAll("/usr/local/lib", `${root}/lib`);
};
const sh = (script: string, ...args: string[]) => {
    const out = new Deno.Command("/bin/sh", {
        args: ["-c", script, "sh", ...args],
    }).outputSync();
    assert(out.success, new TextDecoder().decode(out.stderr));
};
/** The keep step, with the release path the workflow passes. */
const keep = (root: string, version: string) =>
    sh(keepScript(root), `${root}/lib/orbit-swamp/${version}/swamp`);
const plainFile = async (root: string) => {
    await Deno.copyFile(SHELL, `${root}/bin/swamp`);
    await Deno.chmod(`${root}/bin/swamp`, 0o755);
};
/** What the Link step does: `ln -sfn` (official cfgmgmt link). */
const link = (target: string, path: string) =>
    sh(`ln -sfn '${target}' '${path}'`);
const exe = (pid: number) => Deno.readLinkSync(`/proc/${pid}/exe`);

async function scratch() {
    const root = await Deno.makeTempDir({ prefix: "orbit-swamp-switch-" });
    await Deno.mkdir(`${root}/bin`);
    await Deno.mkdir(`${root}/lib/orbit-swamp/v2`, { recursive: true });
    await Deno.mkdir(`${root}/lib/orbit-swamp/v3`, { recursive: true });
    for (const v of ["v2", "v3"]) {
        await Deno.copyFile(SHELL, `${root}/lib/orbit-swamp/${v}/swamp`);
    }
    for (const v of ["v2", "v3"]) {
        await Deno.chmod(`${root}/lib/orbit-swamp/${v}/swamp`, 0o755);
    }
    return root;
}
// A stand-in binary that stays alive as itself: dash waiting on a child (uutils sleep is multi-call by argv[0]).
const SHELL = "/usr/bin/dash";
const start = (path: string) =>
    new Deno.Command(path, { args: ["-c", "sleep 60; true"] }).spawn();

Deno.test("replacing the running file in place leaves the process on a deleted binary", async () => {
    const root = await scratch();
    try {
        await plainFile(root);
        const child = start(`${root}/bin/swamp`);
        link(`${root}/lib/orbit-swamp/v2/swamp`, `${root}/bin/swamp`);
        assert(exe(child.pid).endsWith(" (deleted)"), exe(child.pid));
        child.kill();
        await child.status;
    } finally {
        await Deno.remove(root, { recursive: true });
    }
});

Deno.test("the keep step renames the running file, so the link switch leaves it a live binary", async () => {
    const root = await scratch();
    try {
        await plainFile(root);
        const old = start(`${root}/bin/swamp`);
        keep(root, "v2");
        // The path is a link to the release at once, before Link Swamp confirms it.
        assertEquals(
            Deno.readLinkSync(`${root}/bin/swamp`),
            `${root}/lib/orbit-swamp/v2/swamp`,
        );
        link(`${root}/lib/orbit-swamp/v2/swamp`, `${root}/bin/swamp`);
        assertFalse(exe(old.pid).endsWith(" (deleted)"), exe(old.pid));
        assert(
            exe(old.pid).startsWith(`${root}/lib/orbit-swamp/replaced-`),
            exe(old.pid),
        );
        // The workers' account starts children through this directory.
        assertEquals(
            Deno.statSync(exe(old.pid).replace(/\/swamp$/, "")).mode! & 0o777,
            0o755,
        );

        // The next pin: a process started through the link runs its version's file, which the switch never touches.
        const linked = start(`${root}/bin/swamp`);
        keep(root, "v3"); // a link is left alone
        assertEquals(
            Deno.readLinkSync(`${root}/bin/swamp`),
            `${root}/lib/orbit-swamp/v2/swamp`,
        );
        link(`${root}/lib/orbit-swamp/v3/swamp`, `${root}/bin/swamp`);
        assertEquals(exe(linked.pid), `${root}/lib/orbit-swamp/v2/swamp`);
        for (const child of [old, linked]) {
            child.kill();
            await child.status;
        }
    } finally {
        await Deno.remove(root, { recursive: true });
    }
});

Deno.test("a file that reappears with the same bytes never overwrites the one a process already runs from", async () => {
    const root = await scratch();
    try {
        // Another installer can put the same release back as a plain file, as the Node worker installer does.
        await plainFile(root);
        const first = start(`${root}/bin/swamp`);
        keep(root, "v2");
        await Deno.remove(`${root}/bin/swamp`);
        await plainFile(root);
        const second = start(`${root}/bin/swamp`);
        keep(root, "v2");
        for (const child of [first, second]) {
            assertFalse(exe(child.pid).endsWith(" (deleted)"), exe(child.pid));
        }
        assert(exe(first.pid) !== exe(second.pid));
        for (const child of [first, second]) {
            child.kill();
            await child.status;
        }
    } finally {
        await Deno.remove(root, { recursive: true });
    }
});
