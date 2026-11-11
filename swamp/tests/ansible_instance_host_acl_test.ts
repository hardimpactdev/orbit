/** Exercise host ACL convergence and effective NIC audits against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

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
        const endpoint = (host: string, port: string) => ({action: "allow", source: "10.253.0.0/24", destination: host + "/32", protocol: "tcp", destination_port: port, state: "enabled", description: "orbit:endpoint:1800000000000000"});
        try {
            f.instances.bridge_endpoints = [{host: "10.44.0.7", port: 3306, docker: false}, {host: "10.44.0.3", port: 5434, docker: false}];
            f.write(f.instances);
            let result = await f.run();
            assert(result.ok, result.output);
            const old = f.state();
            const bridge = old["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
            const foreign = {action: "reject", destination: "192.0.2.0/24", state: "enabled"};
            bridge.egress.push({...endpoint("10.44.0.55", "5432"), description: "orbit:endpoint:1799999999999999"}, foreign);
            old.instances.push({name: "legacy-stopped", project: "orbit-instances", status: "Stopped", devices: {eth0: {type: "nic", network: "orbitinst0", "security.port_isolation": "true", "security.acls": "orbitinst0-egress,instance-legacy", "security.acls.default.egress.action": "reject", "security.acls.default.ingress.action": "reject"}}});
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(old));
            result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            const final = state["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
            assert(final.egress.some((rule: unknown) => JSON.stringify(rule) === JSON.stringify(foreign)));
            assertEquals(final.egress.filter((rule: {source?: string}) => rule.source === "10.253.0.0/24"), [endpoint("10.44.0.3", "5434"), endpoint("10.44.0.7", "3306")]);
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
