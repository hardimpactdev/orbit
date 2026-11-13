/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
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
            const images = f.state().images.filter((i: { project: string; aliases: { name: string }[] }) =>
                i.aliases.some((alias) => alias.name.startsWith("orbit-instance-")) && i.project === "orbit-instances"
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
    name: "nested fleet host keeps the normal project strict and builds both image aliases from pinned packages",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({pool: "dir", nested: true});
        try {
            const result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            const normal = state.projects.find((p: {name: string}) => p.name === "orbit-instances").config;
            const nested = state.projects.find((p: {name: string}) => p.name === "orbit-instances-nested").config;
            assertEquals(normal["restricted.containers.nesting"], undefined);
            assertEquals(normal["restricted.containers.lowlevel"], undefined);
            assertEquals(nested, {...normal, "restricted.containers.nesting": "allow", "restricted.containers.interception": "allow", "restricted.containers.lowlevel": "allow"});
            assertEquals(nested["restricted.containers.privilege"], "isolated");
            assertEquals(nested["limits.virtual-machines"], "0");
            assertEquals(state.profiles["orbit-instances-nested"], state.profiles["orbit-instances"]);
            for (const [project, alias] of [["orbit-instances", "orbit-instance"], ["orbit-instances-nested", "orbit-instance-nested"]]) {
                assert(state.images.some((image: {project: string; aliases: {name: string}[]}) => image.project === project && image.aliases.some((row) => row.name === alias)));
            }
            const calls = f.calls().filter((call) => call.includes("orbit-instances-nested"));
            const init = calls.find((call) => call.includes("init") && call.includes("orbit-instance-build"))!;
            for (const key of ["security.nesting=true", "security.syscalls.intercept.mknod=true", "security.syscalls.intercept.setxattr=true", "security.idmap.size=101000000", "limits.memory=12GiB"]) assert(init.includes(key));
            assert(calls.some((call) => call.includes("apt-get") && call.includes("wireguard-tools") && call.includes("sqlite3")));
            assert(calls.some((call) => call.includes("apt-get") && call.includes("/tmp/incus-base.deb") && call.includes("/tmp/incus-client.deb")));
            for (const packagePin of f.instances.nested!.image.incus) {
                assert(Deno.statSync(`${f.folder}/debs/${packagePin.name}_${packagePin.sha256}.deb`).size > 0);
            }
            assertEquals(state.files["orbit-instance-build/etc/sudoers.d/orbit-agent"].content, "agent ALL=(ALL:ALL) NOPASSWD: ALL\n");
            assert(calls.some((call) => call.some((arg) => arg.includes("runuser -u orbit -- sudo -n true"))));
            const repeat = await f.run();
            assert(repeat.ok, repeat.output);
            assertStringIncludes(repeat.output, "changed=0");
        } finally { f.cleanup(); }
    },
});

Deno.test({
    name: "nested fleet image refuses a bad pinned package digest before publication",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({pool: "dir", nested: true});
        try {
            const broken = structuredClone(f.instances);
            broken.nested!.image.incus[0].sha256 = "b".repeat(64);
            f.write(broken);
            const result = await f.run();
            assert(!result.ok, result.output);
            assert(!f.calls().some((call) => call.includes("publish") && call.includes("orbit-instance-nested-aaaaaaaaaaaa")));
            assertEquals(f.state().instances, []);
        } finally { f.cleanup(); }
    },
});

Deno.test({
    name: "nested fleet image preserves a prior builder after the normal variant built successfully",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({pool: "dir", nested: true});
        try {
            const state = f.state();
            const prior = {name: "orbit-instance-build", project: "orbit-instances-nested", status: "Stopped"};
            state.instances.push(prior);
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            const result = await f.run();
            assert(!result.ok, result.output);
            assertStringIncludes(result.output, "orbit-instances-nested/orbit-instance-build requires an operator");
            assert(f.state().images.some((image: {project: string; aliases: {name: string}[]}) => image.project === "orbit-instances" && image.aliases.some((alias) => alias.name === "orbit-instance")));
            assertEquals(f.state().instances, [prior]);
            assert(!f.calls().some((call) => call.includes("delete") && call.includes("orbit-instances-nested") && call.includes("orbit-instance-build")));
        } finally {f.cleanup();}
    },
});

Deno.test({
  name: "a captured hosting intent without the nested image remains valid after the image upgrade",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture({pool: "dir", nested: true});
    try {
      const nested = (f.instances as Record<string, any>).nested;
      f.write({...f.instances, nested: {project: nested.project, config: nested.config}});
      const result = await f.run();
      assert(result.ok, result.output);
      assertEquals(f.state().projects.map((row: {name: string}) => row.name), ["orbit-instances", "orbit-instances-nested"]);
      const aliases = f.state().images.flatMap((row: {project: string; aliases: {name: string}[]}) =>
        row.aliases.map(alias => `${row.project}/${alias.name}`));
      assert(aliases.includes("orbit-instances/orbit-instance"), JSON.stringify(aliases));
      assert(!aliases.includes("orbit-instances-nested/orbit-instance-nested"), JSON.stringify(aliases));
    } finally { f.cleanup(); }
  },
});
