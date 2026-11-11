/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import {
    assert,
    assertEquals,
    assertRejects,
    assertStringIncludes,
} from "jsr:@std/assert@1";
import {
    bridgeAdmits,
    bridgeAnswersDns,
    caddy,
    fixture,
    GiB,
    hasAnsible,
    nodeAddresses,
    peer,
} from "./_support/instance_host.ts";

// Rescue old implementations without ever signalling a reused PID.
function rescueFixtureChild(folder: string, pidFile: string) {
    new Deno.Command("python3", {
        args: [
            "-c",
            `import os, signal
try:
    pid = int(open(${JSON.stringify(pidFile)}).read())
    fd = os.pidfd_open(pid)
    if ${JSON.stringify(folder)} in open(f"/proc/{pid}/cmdline").read():
        signal.pidfd_send_signal(fd, signal.SIGKILL)
    os.close(fd)
except (FileNotFoundError, ProcessLookupError):
    pass
`,
        ],
    }).outputSync();
}

Deno.test("a stuck Instance host playbook throws with its last task and kills a descendant holding stdout", async () => {
    const f = await fixture();
    try {
        const stub = `${f.folder}/bin/ansible-playbook`;
        // The leader exits on TERM, but its child ignores it and keeps the output pipe open.
        // timeout --kill-after cannot finish collecting output in that case: its leader has already exited.
        Deno.writeTextFileSync(
            `${f.folder}/stuck.py`,
            `import os, signal, subprocess, sys
signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
child = subprocess.Popen([sys.executable, "-c", '''import os, signal
signal.signal(signal.SIGTERM, signal.SIG_IGN)
open(${JSON.stringify(f.folder + "/stuck.pid")}, "w").write(str(os.getpid()))
print("TASK [fixture barrier never returns]", flush=True)
signal.pause()
'''])
child.wait()
`,
        );
        Deno.writeTextFileSync(
            stub,
            `#!/bin/sh\nexec python3 '${f.folder}/stuck.py'\n`,
        );
        Deno.chmodSync(stub, 0o755);
        const error = await assertRejects(
            () => f.run(1),
            Error,
            "Instance host playbook timed out after 1s",
        );
        assertStringIncludes(
            error.message,
            "TASK [fixture barrier never returns]",
        );
        const pid = Deno.readTextFileSync(`${f.folder}/stuck.pid`).trim();
        const status = new TextDecoder().decode(
            new Deno.Command("ps", { args: ["-o", "stat=", "-p", pid] })
                .outputSync().stdout,
        ).trim();
        // An orphan killed with its parent can briefly be a zombie until PID 1 reaps it.
        assert(
            status === "" || status.startsWith("Z"),
            `descendant ${pid} survived: ${status}`,
        );
    } finally {
        f.cleanup();
    }
});

Deno.test("a late playbook gets only the shared command budget left, then no new playbook starts", () => {
    const directory = Deno.makeTempDirSync();
    try {
        const script = `${directory}/budget.ts`;
        Deno.writeTextFileSync(
            script,
            String.raw`
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1";
let clock = 0;
Date.now = () => clock;
Deno.env.delete("ORBIT_INSTANCE_HOST_DEADLINE_MS");
const { fixture } = await import(${
                JSON.stringify(
                    new URL("./_support/instance_host.ts", import.meta.url)
                        .href,
                )
            });
const f = await fixture();
try {
    Deno.writeTextFileSync(f.folder + "/bin/ansible-playbook", '#!/bin/sh\necho started >> "$HOST_FIXTURE/starts"\nexec sleep 60\n');
    Deno.chmodSync(f.folder + "/bin/ansible-playbook", 0o755);
    clock = 719_900;
    const started = performance.now();
    await assertRejects(() => f.run(), Error, "timed out after 0.1s");
    assert(performance.now() - started < 4500, "late playbook received a fresh allowance");
    clock = 720_001;
    await assertRejects(() => f.run(), Error, "exhausted its 720s budget");
    assertEquals(Deno.readTextFileSync(f.folder + "/starts"), "started\n");
} finally {
    f.cleanup();
}
`,
        );
        const result = new Deno.Command(Deno.execPath(), {
            args: ["run", "-A", script],
        }).outputSync();
        assert(result.success, new TextDecoder().decode(result.stderr));
    } finally {
        Deno.removeSync(directory, { recursive: true });
    }
});

Deno.test("the shared command budget stays exhausted across Deno test file isolates", () => {
    const directory = Deno.makeTempDirSync();
    try {
        const clockFile = `${directory}/clock`;
        Deno.writeTextFileSync(clockFile, "0");
        const files = ["first_test.ts", "second_test.ts"].map((name, index) => {
            const file = `${directory}/${name}`;
            Deno.writeTextFileSync(
                file,
                `
import { assert, assertRejects } from "jsr:@std/assert@1";
let clock = Number(Deno.readTextFileSync(${JSON.stringify(clockFile)}));
Date.now = () => 1_000_000_000_000 + clock;
Object.defineProperty(performance, "now", { value: () => clock });
const { fixture } = await import(${
                    JSON.stringify(
                        new URL("./_support/instance_host.ts", import.meta.url)
                            .href,
                    )
                });
Deno.test("${
                    index === 0
                        ? "spend the command budget"
                        : "refuse a launch in the next isolate"
                }", async () => {
    const f = await fixture();
    try {
        Deno.writeTextFileSync(f.folder + "/bin/ansible-playbook", '#!/bin/sh\\necho started > "$HOST_FIXTURE/started"\\n');
        Deno.chmodSync(f.folder + "/bin/ansible-playbook", 0o755);
        ${
                    index === 0
                        ? `
        assert((await f.run()).ok);
        Deno.removeSync(f.folder + "/started");
        clock = 720_001;
        Deno.writeTextFileSync(${JSON.stringify(clockFile)}, String(clock));
        `
                        : ""
                }
        await assertRejects(() => f.run(), Error, "exhausted its 720s budget");
        assert(!f.has("started"), "a playbook launched after the shared budget expired");
    } finally {
        f.cleanup();
    }
});
`,
            );
            return file;
        });
        const result = new Deno.Command(Deno.execPath(), {
            args: ["test", "-A", ...files],
            env: {
                DENO_JOBS: "1",
                ORBIT_INSTANCE_HOST_DEADLINE_MS: "1000000720000",
            },
        }).outputSync();
        const decoder = new TextDecoder();
        assert(
            result.success,
            decoder.decode(result.stdout) + decoder.decode(result.stderr),
        );
    } finally {
        Deno.removeSync(directory, { recursive: true });
    }
});

Deno.test("an ordinary playbook refusal preserves its exit status, including 124", async () => {
    const f = await fixture();
    try {
        Deno.writeTextFileSync(
            `${f.folder}/bin/ansible-playbook`,
            "#!/bin/sh\necho fixture-refusal >&2\nexit 124\n",
        );
        Deno.chmodSync(`${f.folder}/bin/ansible-playbook`, 0o755);
        const result = await f.run();
        assert(!result.ok);
        assertStringIncludes(result.output, "fixture-refusal");
    } finally {
        f.cleanup();
    }
});

// These leaders disappear before cleanup can discover their descendants by ancestry.
for (const duringTerm of [false, true]) {
    Deno.test(`deadline reaps a detached stdout holder forked ${duringTerm ? "during TERM" : "before leader exit"}`, async () => {
        const f = await fixture();
        let timer: ReturnType<typeof setTimeout> | undefined;
        let running: Promise<unknown> | undefined;
        try {
            Deno.writeTextFileSync(
                `${f.folder}/orphan.py`,
                `import os, signal, subprocess, sys

def detach(*_):
    child = subprocess.Popen([sys.executable, "-c", '''import os, signal, time
os.setsid()
signal.signal(signal.SIGTERM, signal.SIG_IGN)
with open(${JSON.stringify(f.folder + "/orphan.pid")}, "w") as output:
    output.write(str(os.getpid()))
print("TASK [detached stdout holder]", flush=True)
time.sleep(60)
'''])
    sys.exit(0)

${
                    duringTerm
                        ? "signal.signal(signal.SIGTERM, detach)\nsignal.pause()"
                        : "detach()"
                }
`,
            );
            Deno.writeTextFileSync(
                `${f.folder}/bin/ansible-playbook`,
                `#!/bin/sh\nexec python3 '${f.folder}/orphan.py'\n`,
            );
            Deno.chmodSync(`${f.folder}/bin/ansible-playbook`, 0o755);
            running = f.run(1);
            const error = await assertRejects(
                () =>
                    Promise.race([
                        running!,
                        new Promise((_, reject) => {
                            timer = setTimeout(
                                () =>
                                    reject(new Error("cleanup exceeded 4.5s")),
                                4500,
                            );
                        }),
                    ]),
                Error,
                "Instance host playbook timed out after 1s",
            );
            assertStringIncludes(
                error.message,
                "TASK [detached stdout holder]",
            );
            const pid = Deno.readTextFileSync(`${f.folder}/orphan.pid`).trim();
            let exists = true;
            try {
                Deno.statSync(`/proc/${pid}`);
            } catch (error) {
                if (!(error instanceof Deno.errors.NotFound)) throw error;
                exists = false;
            }
            assert(!exists, `orphan ${pid} was not reaped`);
        } finally {
            clearTimeout(timer);
            rescueFixtureChild(f.folder, `${f.folder}/orphan.pid`);
            await running?.catch(() => {});
            f.cleanup();
        }
    });
}

Deno.test({
    name:
        "a real Ansible deadline kills a stalled command after its worker detaches",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        const pidFile = `${f.folder}/stalled.pid`;
        try {
            Deno.writeTextFileSync(
                `${f.folder}/play.yml`,
                JSON.stringify([{
                    hosts: "localhost",
                    gather_facts: false,
                    tasks: [{
                        name: "real Ansible child stalls after worker detaches",
                        "ansible.builtin.command": {
                            argv: [
                                "{{ ansible_playbook_python }}",
                                "-c",
                                `import os, signal, time
signal.signal(signal.SIGTERM, signal.SIG_IGN)
with open(${JSON.stringify(pidFile)}, "w") as output:
    output.write(str(os.getpid()))
time.sleep(60)
`,
                            ],
                        },
                    }],
                }]),
            );
            const error = await assertRejects(
                () => f.run(5),
                Error,
                "Instance host playbook timed out after 5s",
            );
            assertStringIncludes(
                error.message,
                "TASK [real Ansible child stalls after worker detaches]",
            );
            const pid = Number(Deno.readTextFileSync(pidFile).trim());
            // A killed orphan can wait briefly for PID 1 to reap it.
            for (let attempt = 0; attempt < 100; attempt++) {
                try {
                    Deno.statSync(`/proc/${pid}`);
                } catch (error) {
                    if (error instanceof Deno.errors.NotFound) return;
                    throw error;
                }
                await new Promise((resolve) => setTimeout(resolve, 10));
            }
            throw new Error(
                `stalled Ansible command ${pid} survived its deadline`,
            );
        } finally {
            rescueFixtureChild(f.folder, pidFile);
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "a build-list Node gets the Instance project, bridge, folders and image, then a rerun changes nothing",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            const project = state.projects.find((p: { name: string }) =>
                p.name === "orbit-instances"
            );
            assertEquals(project.config, {
                "features.images": "true",
                "features.profiles": "true",
                "features.networks": "false",
                restricted: "true",
                "restricted.networks.access": "orbitinst0",
                "restricted.containers.privilege": "isolated",
                "restricted.devices.disk": "allow",
                "restricted.devices.proxy": "allow",
                "restricted.snapshots": "allow",
                "limits.virtual-machines": "0",
            });
            // Every process in a container, `incus exec` sessions too, gets the runtime folder's environment and the egress proxy.
            assertEquals(state.profiles["orbit-instances"].config, {
                "environment.VP_HOME": "/var/lib/orbit/vite-plus-home",
                "environment.VP_NO_UPDATE_CHECK": "1",
                "environment.HTTPS_PROXY": "http://10.253.0.1:3128",
            });
            assertEquals(state.profiles["orbit-instances"].devices, {
                root: { type: "disk", path: "/", pool: "orbit-instances" },
                eth0: {
                    type: "nic",
                    name: "eth0",
                    network: "orbitinst0",
                    "security.port_isolation": "true",
                    "security.acls": "orbitinst0-proxy",
                    "security.acls.default.egress.action": "reject",
                    "security.acls.default.ingress.action": "reject",
                },
                "orbit-runtime": {
                    type: "disk",
                    source: `${f.folder}/opt/orbit-runtime`,
                    path: `${f.folder}/opt/orbit-runtime`,
                    readonly: "true",
                    shift: "true",
                },
                "orbit-agent": {
                    type: "disk",
                    source: `${f.folder}/opt/orbit-agent`,
                    path: `${f.folder}/opt/orbit-agent`,
                    readonly: "true",
                    shift: "true",
                },
            });
            const bridge = state.networks.find((n: { name: string }) =>
                n.name === "orbitinst0"
            );
            assertEquals(bridge.config, {
                "ipv4.address": "10.253.0.1/24",
                "ipv4.dhcp.ranges": "10.253.0.2-10.253.0.253",
                "ipv4.nat": "true",
                "ipv6.address": "none",
                "raw.dnsmasq": "port=0",
                "security.acls": "orbitinst0-egress",
                "security.acls.default.ingress.action": "reject",
                "security.acls.default.egress.action": "reject",
            });
            // No DNS: the bridge's dnsmasq serves DHCP only, whatever the Node's input rules admit.
            assert(!bridgeAnswersDns(state));
            const acls = Object.fromEntries(
                state["network-acls"].map((a: { name: string }) => [a.name, a]),
            );
            // The common NIC ACL reaches only the model forwarder and the egress proxy. The profile protects a build container with no own ACL and no
            // route of its own: it goes out through the proxy, as every container does.
            assertEquals(acls["orbitinst0-egress"].egress, [{
                action: "allow",
                destination: "10.253.0.1/32",
                protocol: "tcp",
                destination_port: "8319",
                state: "enabled",
            }, {
                action: "allow",
                destination: "10.253.0.1/32",
                protocol: "tcp",
                destination_port: "3128",
                state: "enabled",
            }]);
            assertEquals(acls["orbitinst0-build"], undefined);
            // Into a container, the bridge admits what a container's own ACL may admit: the Node's Caddy to FrankenPHP and the
            // development ports, and T3's clients. Everything else gets the default reject.
            assert(bridgeAdmits(state, { source: caddy, port: 8080 }));
            assert(bridgeAdmits(state, { source: caddy, port: 5173 }));
            assert(bridgeAdmits(state, { source: peer, port: 3773 }));
            assert(!bridgeAdmits(state, { source: peer, port: 8080 }));
            assert(!bridgeAdmits(state, { source: peer, port: 5173 }));
            assert(!bridgeAdmits(state, { source: caddy, port: 22 }));
            // The input rules have the names of the firewall catalog: DHCP and the proxy, and no DNS.
            for (const name of ["dhcp", "egress"]) {
                assert(f.has(`firewall-orbit_instances-${name}.json`), name);
            }
            for (const name of ["dns-udp", "dns-tcp", "build"]) {
                assert(!f.has(`firewall-orbit_instances-${name}.json`), name);
            }
            assertEquals(
                JSON.parse(
                    Deno.readTextFileSync(
                        `${f.folder}/firewall-orbit_instances-egress.json`,
                    ),
                ),
                // `interface_in` alone: the real module refuses `direction` beside it (tests/ansible_instance_module_args_test.py).
                {
                    rule: "allow",
                    interface_in: "orbitinst0",
                    proto: "tcp",
                    to_ip: "10.253.0.1",
                    to_port: "3128",
                    comment: "orbit:instances-egress",
                },
            );
            // The proxy: the package's own unit masked, the table and Squid's configuration in place, and Squid as `proxy` only while
            // its table is loaded.
            assertEquals(
                Deno.readLinkSync(`${f.folder}/systemd/squid.service`),
                "/dev/null",
            );
            assertEquals(
                Deno.readTextFileSync(`${f.folder}/egress/egress.nft`),
                "table inet orbit_instance_egress\n",
            );
            assertEquals(
                Deno.readTextFileSync(
                    `${f.folder}/egress/orbit-instance-egress.conf`,
                ),
                "http_port 10.253.0.1:3128\n",
            );
            const proxyUnit = Deno.readTextFileSync(
                `${f.folder}/systemd/orbit-instance-egress.service`,
            );
            for (
                const line of [
                    "User=proxy\n",
                    "BindsTo=orbit-instance-egress-table.service\n",
                    "ExecStartPre=+/usr/sbin/nft list table inet orbit_instance_egress\n",
                    `ExecStart=/usr/sbin/squid -N -d 1 -f ${f.folder}/egress/orbit-instance-egress.conf\n`,
                ]
            ) assertStringIncludes(proxyUnit, line);
            assertStringIncludes(
                Deno.readTextFileSync(
                    `${f.folder}/systemd/orbit-instance-egress-table.service`,
                ),
                "ExecStop=/usr/sbin/nft delete table inet orbit_instance_egress\n",
            );
            // No modules to load: an Instance container runs no WireGuard or Docker.
            assertEquals([...Deno.readDirSync(`${f.folder}/modules-load`)], []);
            // The table of agent development ports is written as the Gateway rendered it, and its unit loads it at boot.
            assertEquals(
                Deno.readTextFileSync(`${f.folder}/dev-ports.nft`),
                "table inet orbit_instance_dev_ports\ndelete table inet orbit_instance_dev_ports\ntable inet orbit_instance_dev_ports {}\n",
            );
            assert(
                Deno.readTextFileSync(
                    `${f.folder}/systemd/orbit-instance-dev-ports.service`,
                )
                    .includes(
                        `ExecStart=/usr/sbin/nft --file ${f.folder}/dev-ports.nft`,
                    ),
            );
            assert(
                Deno.readTextFileSync(`${f.folder}/nft-calls`).includes(
                    "list table inet orbit_instance_dev_ports",
                ),
            );
            assertEquals(
                Deno.readTextFileSync(
                    `${f.folder}/sysctl/fs.inotify.max_user_watches`,
                ),
                "1048576 /etc/sysctl.d/60-orbit-instances.conf",
            );
            assertEquals(
                Deno.readTextFileSync(`${f.folder}/arc`).trim(),
                String(4 * GiB),
            );

            // The folders: each program links into its part, `current` names the version, and the agent's notes are beside it.
            const runtime = `${f.folder}/opt/orbit-runtime`;
            assertEquals(
                Deno.readLinkSync(`${runtime}/current`),
                "111111111111",
            );
            assertEquals(
                Deno.readLinkSync(`${runtime}/111111111111/bin/tool`),
                "../tool/tool-1.0/tool",
            );
            assertEquals(
                new TextDecoder().decode(
                    new Deno.Command(`${runtime}/current/bin/tool`).outputSync()
                        .stdout,
                ),
                "tool 1.0\n",
            );
            assertEquals(
                Deno.readLinkSync(`${runtime}/111111111111/bin/single`),
                "../single/single-1.0.phar",
            );
            assertEquals(
                Deno.statSync(`${runtime}/111111111111/single/single-1.0.phar`)
                    .mode! &
                    0o777,
                0o755,
            );
            const agent = `${f.folder}/opt/orbit-agent/222222222222`;
            assertEquals(
                Deno.readLinkSync(`${agent}/agent/bin/claude`),
                "../claude-code/package/claude",
            );
            // Others cannot open the agent's programs; the notes are public.
            assertEquals(Deno.statSync(`${agent}/agent`).mode! & 0o007, 0);
            assertEquals(
                Deno.readTextFileSync(`${agent}/notes/web-app.md`),
                "# This environment\n",
            );
            assertEquals(
                Deno.statSync(`${agent}/notes/web-app.md`).mode! & 0o004,
                4,
            );

            // The image: built once from the stock image at the build address, published, and named by the stable alias.
            const project_calls = f.calls().filter((c) =>
                c.includes("orbit-instances") &&
                c.includes("orbit-instance-build")
            );
            assertEquals(project_calls[1], [
                "--project",
                "orbit-instances",
                "init",
                "ubuntu-26.04-instance-stock",
                "orbit-instance-build",
                "--config",
                "security.idmap.isolated=true",
                "--device",
                "eth0,ipv4.address=10.253.0.254",
            ]);
            assert(
                f.calls().some((c) =>
                    c.includes("install") && c.includes("nftables") &&
                    c.includes("acl")
                ),
            );
            // The build ran the Gateway's real setup script. A started stock container has cloud-init's group `lxd` on gid 1000, and the
            // fixture's `groupadd` refuses a taken gid, as the real one does: the script must delete the group first.
            assert(
                f.instances.image.prepare.includes("groupadd --gid 1000 orbit"),
            );
            // Cloud-init's first boot ends before anything runs in the build container, so it cannot add a sudo user after the setup.
            const inBuild = f.calls().filter((c) =>
                c[0] === "exec" || c.includes("exec")
            ).map((c) => c.slice(c.indexOf("--") + 1).join(" "));
            assertEquals(inBuild[0], "cloud-init status --wait");
            assert(inBuild.indexOf("apt-get update") > 0);
            assertEquals(
                state.files["orbit-instance-build/etc/nftables.conf"],
                {
                    content: "flush ruleset\n",
                    mode: "0640",
                    uid: "0",
                    gid: "0",
                },
            );
            assertEquals(
                state.images.map((i: { aliases: unknown }) => i.aliases).at(-1),
                [
                    { name: "orbit-instance-aaaaaaaaaaaa" },
                    { name: "orbit-instance" },
                ],
            );
            // The build container booted, so it had a machine-id; the image has none, and each container writes its own.
            assertEquals(state.images.at(-1).machine_id, "");
            assertEquals(
                state.instances.map((i: { name: string }) => i.name),
                [],
            );

            const before = f.calls().length;
            result = await f.run();
            assert(result.ok, result.output);
            assertStringIncludes(result.output, "changed=0");
            // A rerun builds and publishes nothing; only the clone probe runs again.
            assert(
                !f.calls().slice(before).some((c) =>
                    c.includes("publish") || c.includes("orbit-instance-build")
                ),
            );

            // A Node prepared before these rules, as Beast was: the bridge's dnsmasq answers DNS, and the ACL rejects all that goes to
            // a container. The next converge gives the existing bridge and ACL both.
            const old = f.state();
            delete old.networks.find((n: { name: string }) =>
                n.name === "orbitinst0"
            ).config["raw.dnsmasq"];
            old["network-acls"].find((a: { name: string }) =>
                a.name === "orbitinst0-egress"
            ).ingress = [];
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify(old),
            );
            assert(bridgeAnswersDns(f.state()));
            assert(!bridgeAdmits(f.state(), { source: caddy, port: 8080 }));
            assert(!bridgeAdmits(f.state(), { source: peer, port: 3773 }));
            result = await f.run();
            assert(result.ok, result.output);
            assert(!bridgeAnswersDns(f.state()));
            assert(bridgeAdmits(f.state(), { source: caddy, port: 8080 }));
            assert(bridgeAdmits(f.state(), { source: peer, port: 3773 }));
            assert(!bridgeAdmits(f.state(), { source: peer, port: 8080 }));
            assertEquals(f.state().networks, state.networks);
            assertEquals(f.state()["network-acls"], state["network-acls"]);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "a task VM host keeps the ZFS ARC its task side sets: the Instance side writes no cap",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            f.write({ ...f.instances, arc: false });
            const result = await f.run();
            assert(result.ok, result.output);
            assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
            assert(!f.has("arc.conf"));
            // The rest of the Instance side is there.
            assert(
                f.state().networks.some((n: { name: string }) =>
                    n.name === "orbitinst0"
                ),
            );
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "an Instance bridge range that another interface of the Node holds refuses before any change",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({
            addresses: [
                ...nodeAddresses.slice(0, 2),
                "6: orbitci0    inet 10.253.0.1/24 scope global orbitci0\\       valid_lft forever preferred_lft forever",
            ],
        });
        try {
            const result = await f.run();
            assert(!result.ok);
            assertStringIncludes(
                result.output,
                "instance.host_unavailable: the Instance bridge range 10.253.0.0/24 overlaps orbitci0 10.253.0.1/24",
            );
            assertEquals(f.state().projects, []);
            assertEquals(f.state().networks, []);
            assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "bridge remains reject after late unsafe attachments, and profile NICs use only the proxy ACL",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir" });
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            const calls = f.calls();
            const profile = calls.findIndex((call) => call.includes("profile") && call.includes("edit"));
            const audit = calls.findIndex((call) => call.includes("/1.0/instances?all-projects=true&recursion=1"));
            assert(profile >= 0 && profile < audit);
            assert(!calls.some((call) => call.includes("security.acls.default.egress.action=allow")));
            assertEquals(calls.filter((call) => call.includes("/1.0/instances?all-projects=true&recursion=1")).length, 2);
            // No own ACL: even the image builder inherits only the common proxy paths, with reject defaults.
            const state = f.state();
            const nic = state.profiles["orbit-instances"].devices.eth0;
            assertEquals([nic["security.acls"], nic["security.acls.default.egress.action"]], ["orbitinst0-proxy", "reject"]);
            assertEquals(state["network-acls"].find((acl: {name: string}) => acl.name === nic["security.acls"]).egress.map((rule: {destination_port: string}) => rule.destination_port), ["8319", "3128"]);
            for (const status of ["Running", "Stopped"]) {
                const broken = f.state();
                broken.instances.push({name: status === "Running" ? "profile-only-instance" : "orbit-instance-build", project: "orbit-instances", status,
                    devices: {eth0: {type: "nic", network: "orbitinst0", "security.acls": ""}}});
                // Keep the actual converged network state; do not reset it before this regression.
                Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(broken));
                const before = f.calls().length;
                result = await f.run();
                assert(!result.ok, result.output);
                assertStringIncludes(result.output, `${status === "Running" ? "profile-only-instance" : "orbit-instance-build"}/eth0 needs an effective ACL`);
                assertEquals(f.state().networks.find((network: {name: string}) => network.name === "orbitinst0").config["security.acls.default.egress.action"], "reject");
                assert(!f.calls().slice(before).some((call) => call.includes("security.acls.default.egress.action=allow")));
                // Removing the whole local device restores the profile gate; individual missing properties do not inherit.
                const fixed = f.state();
                delete fixed.instances.at(-1).devices.eth0;
                Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(fixed));
                result = await f.run();
                assert(result.ok, result.output);
            }
        } finally { f.cleanup(); }
    },
});

Deno.test({
    name: "host reconciles the endpoint union without wiping other rules and moves existing NICs without an open gate",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({pool: "dir"});
        const endpoint = (host: string, port: string) => ({action: "allow", source: "10.253.0.0/24", destination: host + "/32", protocol: "tcp", destination_port: port, state: "enabled"});
        try {
            f.instances.bridge_endpoints = [{host: "10.44.0.7", port: 3306}, {host: "10.44.0.3", port: 5434}];
            f.write(f.instances);
            let result = await f.run();
            assert(result.ok, result.output);
            const old = f.state();
            const bridge = old["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
            const foreign = {action: "reject", destination: "192.0.2.0/24", state: "enabled"};
            bridge.egress.push(endpoint("10.44.0.55", "5432"), foreign);
            old.instances.push({name: "legacy-stopped", project: "orbit-instances", status: "Stopped", devices: {eth0: {type: "nic", network: "orbitinst0", "security.port_isolation": "true", "security.acls": "orbitinst0-egress,instance-legacy", "security.acls.default.egress.action": "reject", "security.acls.default.ingress.action": "reject"}}});
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(old));
            result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            const final = state["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
            assert(final.egress.some((rule: unknown) => JSON.stringify(rule) === JSON.stringify(foreign)));
            assertEquals(final.egress.filter((rule: {source?: string}) => rule.source === "10.253.0.0/24"), [endpoint("10.44.0.7", "3306"), endpoint("10.44.0.3", "5434")]);
            assertEquals(state.instances.at(-1).devices.eth0["security.acls"], "orbitinst0-proxy,instance-legacy");
            assertEquals(state.instances.at(-1).devices.eth0["security.acls.default.egress.action"], "reject");
            assertEquals(state.profiles["orbit-instances"].devices.eth0["security.acls"], "orbitinst0-proxy");
            assert(!f.calls().some((call) => call.includes("security.acls.default.egress.action=allow")));
            // Reproduce a legacy allow bridge plus an unsafe stopped second NIC. Failed audit must close the bridge first.
            state.networks.find((network: {name: string}) => network.name === "orbitinst0").config["security.acls.default.egress.action"] = "allow";
            state.instances.at(-1).devices.eth1 = {type: "nic", network: "orbitinst0"};
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            result = await f.run();
            assert(!result.ok, result.output);
            assertStringIncludes(result.output, "legacy-stopped/eth1 needs an effective ACL");
            assertEquals(f.state().networks.find((network: {name: string}) => network.name === "orbitinst0").config["security.acls.default.egress.action"], "reject");
        } finally {f.cleanup();}
    },
});
