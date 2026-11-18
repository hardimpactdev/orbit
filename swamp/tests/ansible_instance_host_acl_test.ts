/** Exercise host ACL convergence and effective NIC audits against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture as rawFixture, hasAnsible } from "./_support/instance_host.ts";
import { readBoundaries, restore } from "./_support/host_image_boundary.ts";

const boundaries = readBoundaries();

async function fixture(options: Parameters<typeof rawFixture>[0]) {
    const f = await rawFixture(options);
    try {
        const path = `${f.folder}/tasks/instance-host.yml`;
        const tasks = JSON.parse(Deno.readTextFileSync(path)) as { name: string }[];
        const names = [
            "Read the common NIC ACL", "Create the common NIC ACL", "Converge the common NIC ACL",
            "Prepare the Instance bridge, project and profile", "Move existing NICs off the bridge union ACL",
            "Reconcile the bridge endpoint union from Gateway intent", "Bring the existing containers to the image's shared files",
            "Check every Instance bridge NIC at the end of the role", "Require bridge default reject and unchanged ingress isolation",
        ];
        const selected = tasks.filter((task) => names.includes(task.name));
        assertEquals(selected.map((task) => task.name), names);
        restore(f, boundaries["dir-system"]);
        f.calls();
        Deno.writeTextFileSync(path, JSON.stringify([
            { "ansible.builtin.include_vars": `${f.folder}/boundary-facts.json` }, ...selected,
        ]));
    } catch (error) { f.cleanup(); throw error; }
    return f;
}

// Keep the initial ACL converge and the later audit on the same real network state.
for (const status of ["Running", "Stopped"]) {
    const name = status === "Running" ? "profile-only-instance" : "orbit-instance-build";
    Deno.test({
        name: `bridge remains reject after a late unsafe ${status.toLowerCase()} attachment, and profile NICs use only the proxy ACL`,
        ignore: !hasAnsible,
        async fn() {
            const f = await fixture({ pool: "btrfs" });
            try {
                let result = await f.run();
                assert(result.ok, result.output);
                const calls = f.calls();
                const profile = calls.findIndex((call) => call.includes("profile") && call.includes("edit"));
                const audit = calls.findIndex((call) => call.includes("/1.0/instances?all-projects=true&recursion=1"));
                assert(profile >= 0 && profile < audit);
                assert(!calls.some((call) => call.includes("security.acls.default.egress.action=allow")));
                // Moving NICs, reconciling container files, and the final NIC audit each read all projects.
                assertEquals(calls.filter((call) => call.includes("/1.0/instances?all-projects=true&recursion=1")).length, 3);
                // No own ACL: even the image builder inherits only the common proxy paths, with reject defaults.
                const state = f.state();
                const nic = state.profiles["orbit-instances"].devices.eth0;
                assertEquals([nic["security.acls"], nic["security.acls.default.egress.action"]], ["orbitinst0-proxy", "reject"]);
                assertEquals(state["network-acls"].find((acl: {name: string}) => acl.name === nic["security.acls"]).egress.map((rule: {destination_port: string}) => rule.destination_port), ["8319", "3128"]);
                const broken = f.state();
                broken.instances.push({name, project: "orbit-instances", status,
                    devices: {eth0: {type: "nic", network: "orbitinst0", "security.acls": ""}}});
                // Keep the actual converged network state; do not reset it before this regression.
                Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(broken));
                const before = f.calls().length;
                result = await f.run();
                assert(!result.ok, result.output);
                assertStringIncludes(result.output, `${name}/eth0 needs an effective ACL`);
                assertEquals(f.state().networks.find((network: {name: string}) => network.name === "orbitinst0").config["security.acls.default.egress.action"], "reject");
                assert(!f.calls().slice(before).some((call) => call.includes("security.acls.default.egress.action=allow")));
            } finally { f.cleanup(); }
        },
    });
    Deno.test({
        name: `a ${status.toLowerCase()} attachment without its own NIC device passes the audit through the profile gate`,
        ignore: !hasAnsible,
        async fn() {
            const f = await fixture({ pool: "btrfs" });
            try {
                restore(f, boundaries["btrfs-finish"]);
                // Removing the whole local device restores the profile gate; individual missing properties do not inherit.
                const fixed = f.state();
                fixed.instances.push({name, project: "orbit-instances", status, devices: {}});
                Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(fixed));
                const result = await f.run();
                assert(result.ok, result.output);
            } finally { f.cleanup(); }
        },
    });
}

const endpoint = (host: string, port: string) => ({action: "allow", source: "10.253.0.0/24", destination: host + "/32", protocol: "tcp", destination_port: port, state: "enabled", description: "orbit:endpoint:1800000000000000"});
const legacy = (acls: string) => ({name: "legacy-stopped", project: "orbit-instances", status: "Stopped", devices: {eth0: {type: "nic", network: "orbitinst0", "security.port_isolation": "true", "security.acls": acls, "security.acls.default.egress.action": "reject", "security.acls.default.ingress.action": "reject"}}});

async function converged() {
    const f = await fixture({pool: "btrfs"});
    f.instances.bridge_endpoints = [{host: "10.44.0.7", port: 3306, docker: false}, {host: "10.44.0.3", port: 5434, docker: false}];
    f.write(f.instances);
    const result = await f.run();
    assert(result.ok, result.output);
    return f;
}

Deno.test({
    name: "host reconciles the endpoint union without wiping other rules and moves existing NICs without an open gate",
    ignore: !hasAnsible,
    async fn() {
        const f = await converged();
        try {
            const old = f.state();
            const bridge = old["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
            const foreign = {action: "reject", destination: "192.0.2.0/24", state: "enabled"};
            bridge.egress.push({...endpoint("10.44.0.55", "5432"), description: "orbit:endpoint:1799999999999999"}, foreign);
            old.instances.push(legacy("orbitinst0-egress,instance-legacy"));
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(old));
            const result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            const final = state["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
            assert(final.egress.some((rule: unknown) => JSON.stringify(rule) === JSON.stringify(foreign)));
            assertEquals(final.egress.filter((rule: {source?: string}) => rule.source === "10.253.0.0/24"), [endpoint("10.44.0.3", "5434"), endpoint("10.44.0.7", "3306")]);
            assertEquals(state.instances.at(-1).devices.eth0["security.acls"], "orbitinst0-proxy,instance-legacy");
            assertEquals(state.instances.at(-1).devices.eth0["security.acls.default.egress.action"], "reject");
            assertEquals(state.profiles["orbit-instances"].devices.eth0["security.acls"], "orbitinst0-proxy");
            assert(!f.calls().some((call) => call.includes("security.acls.default.egress.action=allow")));
        } finally {f.cleanup();}
    },
});

Deno.test({
    name: "a legacy allow bridge with an unsafe stopped second NIC is closed before the failed audit",
    ignore: !hasAnsible,
    async fn() {
        const f = await converged();
        try {
            // The moved legacy NIC of the endpoint-union case, plus a legacy allow bridge and an unsafe stopped second NIC.
            const state = f.state();
            state.instances.push(legacy("orbitinst0-proxy,instance-legacy"));
            state.networks.find((network: {name: string}) => network.name === "orbitinst0").config["security.acls.default.egress.action"] = "allow";
            state.instances.at(-1).devices.eth1 = {type: "nic", network: "orbitinst0"};
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            const result = await f.run();
            assert(!result.ok, result.output);
            assertStringIncludes(result.output, "legacy-stopped/eth1 needs an effective ACL");
            assertEquals(f.state().networks.find((network: {name: string}) => network.name === "orbitinst0").config["security.acls.default.egress.action"], "reject");
        } finally {f.cleanup();}
    },
});
