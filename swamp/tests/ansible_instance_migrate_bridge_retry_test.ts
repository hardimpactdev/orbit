import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const docker of [false, true]) for (const failures of [1, 9]) {
  Deno.test({
    name: `${docker ? "Docker" : "direct"} bridge endpoint add with ${failures === 1 ? "transient" : "persistent"} missing-veth failure stays before the migration probe and downtime`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.container.bridge_endpoints = [{host: "10.44.0.7", port: 5432, docker}];
        f.migrate.databases = [{host: "10.44.0.7", port: 5432}];
        f.write();
        const state = f.state();
        state.bridgeVethFailures = failures;
        state.bridgeProbe = {host: "10.44.0.7", port: 5432,
          translatedHost: docker ? "172.17.0.2" : "10.44.0.7", translatedPort: docker ? 5433 : 5432};
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        if (docker) Deno.writeTextFileSync(`${f.folder}/nat.txt`, "-A DOCKER -d 10.44.0.7/32 ! -i docker0 -p tcp -m tcp --dport 5432 -j DNAT --to-destination 172.17.0.2:5433\n");
        const before = Deno.readTextFileSync(`${f.checkout}/.env`);
        const result = f.run("container");
        assertEquals(result.ok, failures === 1, result.output);
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
        assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), before);
        const calls = Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[]);
        const writes = calls.filter((call) => call[0] === "curl" && call.includes("PUT"));
        assertEquals(writes.length, failures === 1 ? 2 : 5);
        const probe = calls.findIndex((call) => call.some((arg) => arg.includes("/dev/tcp/$1/$2")));
        if (failures === 1) assert(probe > calls.findIndex((call) => call === writes.at(-1)));
        else assertEquals(probe, -1, "a failed bridge admission must not probe or quiesce");
      } finally { f.cleanup(); }
    },
  });
}

