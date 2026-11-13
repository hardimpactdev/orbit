/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

Deno.test({
    name:
        "a new image definition takes the stable alias while retaining the old image",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            f.write({
                ...f.instances,
                image: {
                    ...f.instances.image,
                    alias: "orbit-instance-bbbbbbbbbbbb",
                },
            });
            result = await f.run();
            assert(result.ok, result.output);
            const images = f.state().images.filter((i: { aliases: { name: string }[] }) =>
                i.aliases.some((alias) => alias.name.startsWith("orbit-instance"))
            );
            assertEquals(images.map((i: { aliases: unknown }) => i.aliases), [
                [{name: "orbit-instance-aaaaaaaaaaaa"}],
                [{name: "orbit-instance-bbbbbbbbbbbb"}, {name: "orbit-instance"}],
            ]);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a Node without its Instance pool is refused before anything changes",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: null });
        try {
            const result = await f.run();
            assert(!result.ok);
            assertStringIncludes(result.output, "instance.host_unavailable");
            assertEquals(f.state().projects, []);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a directory pool skips the clone probe: a copy there is a full copy",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir" });
        try {
            const result = await f.run();
            assert(result.ok, result.output);
            assert(
                !f.calls().some((c) => c.includes("orbit-clone-probe-copy")),
            );
            // No ZFS, so no ARC cap.
            assert(!f.has("arc.conf"));
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "an image that keeps a sudo path is never published",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const state = f.state();
            state.sudo_paths =
                "user:ubuntu\nsudoers:/etc/sudoers.d/90-cloud-init-users\nmember:ubuntu";
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify(state),
            );
            const result = await f.run();
            assert(!result.ok);
            assertStringIncludes(
                result.output,
                "the image keeps a sudo path: user:ubuntu, sudoers:/etc/sudoers.d/90-cloud-init-users, member:ubuntu",
            );
            const after = f.state();
            assert(!f.calls().some((c) => c.includes("publish") && c.includes("orbit-instance-build")));
            assertEquals(after.instances, []);
        } finally {
            f.cleanup();
        }
    },
});

for (const [pool, temporary] of [["dir", "orbit-instance-build"], ["zfs", "orbit-clone-probe-copy"]]) {
    Deno.test({
        name: `${temporary} refuses an inherited unguarded profile NIC before starting`,
        ignore: !hasAnsible,
        async fn() {
            const f = await fixture({pool});
            try {
                const state = f.state();
                state.projects.push({name: "orbit-instances", config: {"features.networks": "false", "features.profiles": "true", "features.images": "true"}});
                state.profiles["orbit-instances"] = {config: {}, devices: {eth1: {type: "nic", network: "orbitinst0"}}};
                Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));

                const result = await f.run();

                assert(!result.ok, result.output);
                assertStringIncludes(result.output, "instance.container_network_unsafe");
                assert(f.calls().some((call) => call.includes(temporary) && call.includes(temporary === "orbit-instance-build" ? "init" : "copy")));
                assert(!f.calls().some((call) => call.includes("start") && call.includes(temporary)));
                assert(!f.state().instances.some((row: {name: string}) => ["orbit-instance-build", "orbit-clone-probe", "orbit-clone-probe-copy"].includes(row.name)));
            } finally { f.cleanup(); }
        },
    });
}

Deno.test({
    name: "prepared publication clears inherited stock build ownership",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            const stock = state.images.find((
                image: { aliases: { name: string }[] },
            ) => image.aliases.some((alias) =>
                alias.name === f.instances.stock
            ));
            const prepared = state.images.find((
                image: { aliases: { name: string }[] },
            ) => image.aliases.some((alias) =>
                alias.name === "orbit-instance"
            ));
            assertEquals(
                stock.properties["user.orbit.stock-build"],
                f.instances.stock,
            );
            assertEquals(prepared.properties["user.orbit.stock-build"], "");
            assertEquals(state.instances, []);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "the full prepared-image replacement and failure cleanup use the verified local socket despite the default remote",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            const remote = f.state();
            Deno.writeTextFileSync(
                `${f.folder}/remote-state.json`,
                JSON.stringify(remote),
            );
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify({ ...remote, default_remote: true }),
            );
            const before = f.calls().length;
            f.write({
                ...f.instances,
                image: {
                    ...f.instances.image,
                    alias: "orbit-instance-bbbbbbbbbbbb",
                },
            });
            result = await f.run();
            assert(result.ok, result.output);
            assertEquals(
                JSON.parse(
                    Deno.readTextFileSync(`${f.folder}/remote-state.json`),
                ),
                remote,
            );
            assert(
                f.state().images.some((i: { aliases: { name: string }[] }) =>
                    i.aliases.some((a) =>
                        a.name === "orbit-instance-bbbbbbbbbbbb"
                    )
                ),
            );
            const local = f.state();
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify({ ...local, sudo_paths: "user:ubuntu" }),
            );
            f.write({
                ...f.instances,
                image: {
                    ...f.instances.image,
                    alias: "orbit-instance-cccccccccccc",
                },
            });
            result = await f.run();
            assert(!result.ok, result.output);
            assertEquals(f.state().instances, []);
            assertEquals(
                JSON.parse(
                    Deno.readTextFileSync(`${f.folder}/remote-state.json`),
                ),
                remote,
            );
            const targets = Deno.readTextFileSync(`${f.folder}/targets`).trim()
                .split("\n").map((line) => JSON.parse(line)).slice(before);
            const effects = targets.filter((target) =>
                target.args.some((arg: string) =>
                    [
                        "image",
                        "init",
                        "publish",
                        "copy",
                        "start",
                        "stop",
                        "delete",
                        "exec",
                        "file",
                    ].includes(arg)
                )
            );
            assert(effects.length > 0);
            assert(
                effects.every((target) =>
                    !target.remote && target.configRemote === "local" &&
                    target.socket === `${f.folder}/unix.socket`
                ),
            );
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "a prior prepared-image builder is refused by exact name and recipe without deletion",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir" });
        try {
            const state = f.state();
            const instances = [{
                name: "orbit-instance-build",
                project: "orbit-instances",
                status: "Stopped",
            }];
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify({ ...state, instances }),
            );
            const result = await f.run();
            assert(!result.ok, result.output);
            assertStringIncludes(result.output, "orbit-instance-build");
            assertStringIncludes(
                result.output,
                "/solutions/incus-shared-image-refresh#operator-cleanup",
            );
            assertEquals(f.state().instances, instances);
            assert(
                !f.calls().some((c) =>
                    c.includes("delete") && c.includes("orbit-instance-build")
                ),
            );
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a Node without the nested-fleet switch runs the exact command lines of the normal image",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir" });
        try {
            const result = await f.run();
            assert(result.ok, result.output);
            const calls = JSON.stringify(f.calls()).replaceAll(f.folder, "FIXTURE");
            const digest = Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(calls))))
                .map((byte) => byte.toString(16).padStart(2, "0")).join("");
            // Captured from the tasks at 2a-local 7b9e25ba02, before the nested-fleet image existed, with this same host shape; and
            // again when the image removed its linker cache last and the converge read the existing containers (two calls more), and
            // when the image took the Node's Orbit root CA (one push and `update-ca-certificates`, two calls more).
            assertEquals([f.calls().length, digest], [57, "63e10e071e2e7cc0878df6ecca6eb3440ce5e1cf4023bee56b4fba8c97199355"]);
            assert(f.calls().some((call) => call.includes("exec") && call.at(-1) === "update-ca-certificates"));
            assert(f.calls().some((call) => call.includes("exec") && call.slice(-3).join(" ") === "rm -f /etc/ld.so.cache"));
            assert(f.calls().some((call) => call[0] === "query" && call.at(-1) === "/1.0/instances?all-projects=true&recursion=1"));
            assertEquals(f.state().images.flatMap((image: {project: string; aliases: {name: string}[]}) =>
                image.aliases.map((alias) => `${image.project}/${alias.name}`)).filter((name: string) => name.includes("orbit-instance-nested")), []);
        } finally {
            f.cleanup();
        }
    },
});

const aliasesOf = (state: {images: {project: string; aliases: {name: string}[]}[]}) =>
    state.images.flatMap((image) => image.aliases.map((alias) => `${image.project}/${alias.name}`));

Deno.test({
    name: "a switched-on Node builds the nested-fleet image in its project from the verified pinned packages",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir", nested: true });
        try {
            const result = await f.run();
            assert(result.ok, result.output);
            const aliases = aliasesOf(f.state());
            for (const alias of ["orbit-instances/orbit-instance", "orbit-instances/orbit-instance-aaaaaaaaaaaa",
                "orbit-instances-nested/orbit-instance-nested", "orbit-instances-nested/orbit-instance-nested-aaaaaaaaaaaa"]) {
                assert(aliases.includes(alias), JSON.stringify(aliases));
            }
            const nested = f.calls().filter((call) => call.includes("orbit-instances-nested"));
            const exec = (words: string[]) => nested.filter((call) => call[0] === "exec" && words.every((word) => call.includes(word)));
            assertEquals(nested.find((call) => call.includes("init") && call.includes("orbit-instance-build")),
                ["--project", "orbit-instances-nested", "init", "ubuntu-26.04-instance-stock", "orbit-instance-build",
                    "--config", "security.idmap.isolated=true", "--device", "eth0,ipv4.address=10.253.0.254"]);
            assertEquals(exec(["apt-get", "install"]).map((call) => call.slice(call.indexOf("--yes"))), [
                ["--yes", "--no-install-recommends", "--", "acl", "git", "nftables", "wireguard-tools", "sqlite3", "sudo"],
                ["--yes", "--no-install-recommends", "--", "/tmp/incus-base.deb", "/tmp/incus-client.deb"],
            ]);
            // The service block is in place for the package scripts only, and gone before the image is published.
            const order = nested.map((call) => call.join(" "));
            const blocked = order.findIndex((line) => line.endsWith("--mode 0755 - orbit-instance-build/usr/sbin/policy-rc.d"));
            const installed = order.findIndex((line) => line.includes("/tmp/incus-base.deb") && line.includes("apt-get"));
            const unblocked = order.findIndex((line) => line.includes("rm -- /usr/sbin/policy-rc.d /tmp/incus-base.deb /tmp/incus-client.deb"));
            const published = order.findIndex((line) => line.includes("publish orbit-instance-build"));
            assert(blocked >= 0 && blocked < installed && installed < unblocked && unblocked < published, order.join("\n"));
            for (const pin of f.instances.nested!.image.incus) {
                assertEquals(Deno.readTextFileSync(`${f.folder}/debs/${pin.name}_${pin.sha256}.deb`), `fixture ${pin.name}`);
                assert(nested.some((call) => call.join(" ") ===
                    `file push --project orbit-instances-nested ${f.folder}/debs/${pin.name}_${pin.sha256}.deb orbit-instance-build/tmp/${pin.name}.deb`));
            }
            assertEquals(f.state().files["orbit-instance-build/usr/sbin/policy-rc.d"], { content: "#!/bin/sh\nexit 101\n", mode: "0755", uid: "0", gid: "0" });
            assertEquals(f.state().files["orbit-instance-build/etc/sudoers.d/orbit-agent"],
                { content: "agent ALL=(ALL:ALL) NOPASSWD: ALL\n", mode: "0440", uid: "0", gid: "0" });
            const audit = exec(["sh"]).find((call) => call.at(-1)!.includes("getent passwd ubuntu"))!.at(-1)!;
            for (const check of ["/etc/sudoers.d/orbit-agent", "incus-admin:[^:]*:[0-9]*:agent", "visudo -cqf", "runuser -u agent -- sudo -n true",
                "runuser -u orbit -- sudo -n true 2>/dev/null && echo orbit:sudo"]) {
                assertStringIncludes(audit, check);
            }
            // The normal build audits as before: no grant at all.
            const normalAudit = f.calls().filter((call) => call.includes("orbit-instances") && call[0] === "exec" && call.at(-1)!.includes("getent passwd ubuntu"));
            assertEquals(normalAudit.map((call) => call.at(-1)!.includes("orbit-agent")), [false]);
            assertEquals(nestedRecord(f.folder), null);
            const repeat = await f.run();
            assert(repeat.ok, repeat.output);
            assertStringIncludes(repeat.output, "changed=0");
        } finally {
            f.cleanup();
        }
    },
});

/** The record a failed nested-fleet build leaves on the Node; a nested-fleet create reads it (instance-container-phase.yml). */
const nestedRecord = (folder: string) => {
    try {
        return JSON.parse(Deno.readTextFileSync(`${folder}/nested-image.json`));
    } catch (error) {
        if (error instanceof Deno.errors.NotFound) return null;
        throw error;
    }
};

// Two different failing tasks of the nested build: the pinned package fetch, and the sudo audit of the built image.
// The first also proves that a later good converge clears the record; one repair run keeps the second case within its lane.
const nestedFailures: [string, string, boolean, (f: Awaited<ReturnType<typeof fixture>>) => void][] = [
    ["a wrong pinned digest", "Fetch and verify pinned artifact", true, (f) => {
        const broken = structuredClone(f.instances);
        broken.nested!.image.incus[1].sha256 = "b".repeat(64);
        f.write(broken);
    }],
    ["a sudo path in the nested-fleet image", "Require an image without a sudo path", false, (f) => {
        const state = f.state();
        state.sudo_paths_by_project = { "orbit-instances-nested": "agent:syntax" };
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
    }],
];

for (const [failure, task, repair, breakIt] of nestedFailures) {
    Deno.test({
        name: `${failure} fails only the nested-fleet image: the role succeeds and records the failed task${repair ? ", and a later good build clears it" : ""}`,
        ignore: !hasAnsible,
        async fn() {
            const f = await fixture({ pool: "dir", nested: true });
            try {
                const good = structuredClone(f.instances);
                breakIt(f);
                const result = await f.run();
                assert(result.ok, result.output.slice(-6000));
                // The normal part is complete: its image and stable alias, and the end checks ran after the nested build.
                const aliases = aliasesOf(f.state());
                for (const alias of ["orbit-instances/orbit-instance", "orbit-instances/orbit-instance-aaaaaaaaaaaa"]) {
                    assert(aliases.includes(alias), JSON.stringify(aliases));
                }
                assertStringIncludes(result.output, "TASK [Require a healthy bridge endpoint refresh]");
                assertEquals(aliases.filter((alias) => alias.startsWith("orbit-instances-nested/orbit-instance-nested")), []);
                assert(!f.calls().some((call) => call.includes("publish") && call.includes("orbit-instances-nested") && call.includes("orbit-instance-build")));
                assertEquals(f.state().instances, []);
                assertEquals(nestedRecord(f.folder),
                    { error: "nested image build failed", alias: "orbit-instance-nested-aaaaaaaaaaaa", task });
                assertStringIncludes(result.output, `nested image build failed: ${task}`);
                if (!repair) return;
                // A later converge with a good build publishes the image and clears the record.
                const state = f.state();
                delete state.sudo_paths_by_project;
                Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
                f.write(good);
                const repaired = await f.run();
                assert(repaired.ok, repaired.output);
                assert(aliasesOf(f.state()).includes("orbit-instances-nested/orbit-instance-nested-aaaaaaaaaaaa"));
                assertEquals(nestedRecord(f.folder), null);
            } finally {
                f.cleanup();
            }
        },
    });
}

Deno.test({
    name: "a failed normal image build on a switched-on Node still fails the role and builds no nested-fleet image",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir", nested: true });
        try {
            const state = f.state();
            state.sudo_paths_by_project = { "orbit-instances": "user:ubuntu" };
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            const result = await f.run();
            assert(!result.ok, result.output);
            assertStringIncludes(result.output, "the image keeps a sudo path: user:ubuntu");
            assertEquals(aliasesOf(f.state()).filter((alias) => alias.split("/")[1].startsWith("orbit-instance")), []);
            assert(!f.calls().some((call) => call.includes("orbit-instances-nested") && call.includes("orbit-instance-build")));
            assertEquals(nestedRecord(f.folder), null);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a hosting intent without the nested-fleet image builds only the normal image",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir", nested: true });
        try {
            f.write({ ...f.instances, nested: { project: f.instances.nested!.project } });
            const result = await f.run();
            assert(result.ok, result.output);
            assertEquals(aliasesOf(f.state()).filter((alias) => alias.includes("orbit-instance-nested")), []);
            // The hosting change still prepares the nested project; no image build runs there.
            assert(!f.calls().some((call) => call.includes("orbit-instances-nested") && call.includes("orbit-instance-build")));
        } finally {
            f.cleanup();
        }
    },
});

// The shipped nested-fleet audit text, run by a real shell against stand-ins for the image's commands and its sudoers.d.
const nestedAudit = (() => {
    type Task = { name?: string; block?: Task[]; vars?: Record<string, string> };
    const tasks = parse(Deno.readTextFileSync(new URL("../ansible/tasks/instance-image.yml", import.meta.url))) as Task[];
    const all = (list: Task[]): Task[] => list.flatMap((task) => [task, ...all(task.block ?? [])]);
    return all(tasks).find((task) => task.name === "Read the sudo paths of the image")!.vars!.image_nested_audit;
})();

const runNestedAudit = (visudo: string) => {
    const folder = Deno.makeTempDirSync();
    try {
        Deno.mkdirSync(`${folder}/bin`);
        Deno.mkdirSync(`${folder}/sudoers.d`);
        Deno.writeTextFileSync(`${folder}/sudoers.d/README`, "");
        Deno.writeTextFileSync(`${folder}/sudoers.d/orbit-agent`, "agent ALL=(ALL:ALL) NOPASSWD: ALL\n");
        const stubs: Record<string, string> = {
            getent: '[ "$1 $2" = "group incus-admin" ] && echo incus-admin:x:994:agent; [ "$1" = group ] || exit 2',
            runuser: '[ "$2" = agent ]',
            visudo,
        };
        for (const [name, body] of Object.entries(stubs)) {
            Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\n${body}\n`, { mode: 0o755 });
        }
        const output = new Deno.Command("sh", {
            args: ["-c", nestedAudit.replaceAll("/etc/sudoers.d", `${folder}/sudoers.d`)],
            env: { PATH: `${folder}/bin:/usr/bin:/bin` },
            stdout: "piped",
            stderr: "null",
        }).outputSync();
        assert(output.success);
        return new TextDecoder().decode(output.stdout);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
};

Deno.test({
    name: "the nested-fleet audit judges visudo by its exit code, not by what it prints",
    fn() {
        // visudo-rs ignores -q and reports a clean file on stdout.
        assertEquals(runNestedAudit('echo "$2: parsed OK"'), "");
        assertEquals(runNestedAudit('echo "$2: parsed OK"; exit 1'), "agent:syntax\n");
    },
});
