/**
 * The swamp role's binary switch must never leave a running serve or worker on a deleted binary: Swamp starts
 * children from its own executable path, and a replaced file reads as "/usr/local/bin/swamp (deleted)". The
 * db3f6373 rollout on 2026-11-03 failed every 209 dispatch for a minute that way.
 */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.9";
import { ROOT } from "../../../../bin/gate-test-support.ts";

// deno-lint-ignore no-explicit-any
type Task = Record<string, any>;
const tasks = parse(
    Deno.readTextFileSync(`${ROOT}/ansible/tasks/role-swamp.yml`),
) as Task[];
const task = (name: string) => tasks.find((item) => item.name === name)!;
const preserve = task("Preserve a regular Swamp executable before linking");
const childTask = (name: string) =>
    preserve.block.find((item: Task) => item.name === name)!;

Deno.test("the role installs beside the running binary, keeps it, links, then writes the units", () => {
    const names = tasks.map((item) => item.name);
    const install =
        task("Install pinned Swamp binary")["ansible.builtin.get_url"];
    assertEquals(
        install.dest,
        "{{ rt.swamp.binary.path }}",
        "the release goes to its own versioned path",
    );
    assert(
        names.indexOf("Install pinned Swamp binary") <
            names.indexOf(preserve.name),
    );
    assert(
        names.indexOf(preserve.name) <
            names.indexOf("Link the pinned Swamp executable"),
    );
    assert(
        names.indexOf("Link the pinned Swamp executable") <
            names.indexOf("Write Swamp service units"),
    );
    assertEquals(
        preserve.when,
        "swamp_executable.stat.exists and not swamp_executable.stat.islnk",
    );
    const writers = tasks.filter((item) =>
        item["ansible.builtin.file"]?.path === "/usr/local/bin/swamp"
    );
    assertEquals(writers.map((item) => item.name), [
        "Link the pinned Swamp executable",
    ]);
    assertEquals(writers[0]["ansible.builtin.file"].state, "link");
    assertEquals(
        childTask("Preserve the regular executable")["ansible.builtin.command"]
            .argv[0],
        "mv",
    );
});
const sh = (script: string, ...args: string[]) => {
    const out = new Deno.Command("/bin/sh", {
        args: ["-c", script, "sh", ...args],
    }).outputSync();
    assert(out.success, new TextDecoder().decode(out.stderr));
};
/** Apply the Ansible preserve block and link to scratch paths, using its actual rename argv. */
const keep = (root: string, version: string) => {
    if (!Deno.lstatSync(`${root}/bin/swamp`).isSymlink) {
        const replaced = Deno.makeTempDirSync({
            dir: `${root}/lib/orbit-swamp`,
            prefix: "replaced-",
        });
        const mode = childTask(
            "Allow workers to execute the preserved binary",
        )["ansible.builtin.file"].mode;
        Deno.chmodSync(replaced, Number.parseInt(mode, 8));
        const argv: string[] = childTask(
            "Preserve the regular executable",
        )["ansible.builtin.command"].argv.map((value: string) =>
            value.replaceAll("/usr/local/bin/swamp", `${root}/bin/swamp`)
                .replaceAll("{{ swamp_replaced.path }}", replaced)
        );
        const output = new Deno.Command(argv[0], { args: argv.slice(1) })
            .outputSync();
        assert(output.success, new TextDecoder().decode(output.stderr));
    }
    // The next file task confirms (or changes) the link after the preserve block.
    link(`${root}/lib/orbit-swamp/${version}/swamp`, `${root}/bin/swamp`);
};
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
        // The preserve block and link task leave the old inode live beside the release.
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
        keep(root, "v3"); // the existing release's inode is left alone
        assertEquals(
            Deno.readLinkSync(`${root}/bin/swamp`),
            `${root}/lib/orbit-swamp/v3/swamp`,
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
