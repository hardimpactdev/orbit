import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

for (const damage of ["missing ACL", "permissive default", "bridge union ACL"]) {
  Deno.test({
    name: `start refuses a NIC with ${damage} before starting the container`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const made = f.run("container");
        assert(made.ok, made.output);
        assert(f.run("stop").ok);
        const state = f.state();
        const key = damage === "permissive default" ? "security.acls.default.egress.action" : "security.acls";
        const value = damage === "missing ACL" ? "" : damage === "bridge union ACL" ? "orbitinst0-egress,instance-7" : "allow";
        state.containers["instance-7"].devices = state.containers["instance-7"].devices
          .filter((device: string) => !device.startsWith(`eth0,${key}=`));
        state.containers["instance-7"].devices.push(`eth0,${key}=${value}`);
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const result = f.run("start");
        assert(!result.ok, result.output);
        assertEquals(f.result(), { error_code: "instance.container_network_unsafe" }, result.output);
        assertEquals(f.state().containers["instance-7"].status, "Stopped");
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "start refuses an unguarded extra bridge NIC beside guarded eth0",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      assert(f.run("container").ok);
      const state = f.state();
      state.containers["instance-7"].status = "Stopped";
      state.containers["instance-7"].devices.push("eth1,type=nic", "eth1,network=orbitinst0");
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const result = f.run("start");
      assert(!result.ok, result.output);
      assertEquals(f.result(), {error_code: "instance.container_network_unsafe"}, result.output);
      assertEquals(f.state().containers["instance-7"].status, "Stopped");
      // Even a guarded extra NIC is unexpected for an Instance and must refuse.
      const guarded = f.state();
      guarded.containers["instance-7"].devices.push("eth1,security.acls=instance-7", "eth1,security.acls.default.egress.action=reject");
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(guarded));
      assert(!f.run("start").ok);
      assertEquals(f.state().containers["instance-7"].status, "Stopped");
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "first attach adds an exact bridge endpoint, retries ETag conflicts and repeats without changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.bridge_endpoints = [{host: "10.44.0.7", port: 3306, docker: false}];
      f.write();
      const state = f.state();
      state.etagConflicts = 1;
      const concurrent = {action: "allow", source: "10.253.0.0/24", destination: "10.44.0.3/32", protocol: "tcp", destination_port: "5434", state: "enabled"};
      state.concurrentEndpoint = concurrent;
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      let result = f.run("container");
      assert(result.ok, result.output);
      const bridge = f.state()["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
      assertEquals(bridge.egress, [concurrent, {action: "allow", source: "10.253.0.0/24", destination: "10.44.0.7/32", protocol: "tcp", destination_port: "3306", state: "enabled", description: "orbit:endpoint:1800000000000000"}]);
      result = f.run("container");
      assert(result.ok, result.output);
      assertEquals(result.changed, 0, result.output);
      const calls = Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[]);
      assertEquals(calls.filter((call) => call[0] === "curl" && call.includes("PUT")).length, 2);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a copied seed with an unguarded extra local NIC refuses before the clone starts",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const prepared = f.run(phase);
        assert(prepared.ok, prepared.output);
      }
      const state = f.state();
      const source = state.containers["instance-7"];
      source.devices.push("eth1,type=nic", "eth1,network=orbitinst0");
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      Object.assign(f.container, {instance_id: 8, name: "instance-8", address: "10.253.0.3", acl: "instance-8",
        clone: {source: "instance-7", snapshot: source.snapshots[0], t3: {device: "t3", listen: "10.44.0.7", port: 3800, internal_port: 3773}}});
      f.write();
      const result = f.run("clone");
      assert(!result.ok, result.output);
      assertEquals(f.result(), {error_code: "instance.container_network_unsafe"}, result.output);
      assertEquals(f.state().containers["instance-8"].status, "Stopped");
      assert(f.state().containers["instance-8"].devices.includes("eth1,network=orbitinst0"));
    } finally {f.cleanup();}
  },
});
