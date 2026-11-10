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
            // The bridge reaches only the model forwarder and the egress proxy on its own address. The build container has no ACL and no
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

