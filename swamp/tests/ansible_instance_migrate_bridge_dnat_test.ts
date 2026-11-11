import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const mappings of [0, 2]) {
  Deno.test({
    name: `${mappings} local Docker DNAT mappings refuse migration before a probe or downtime with the named error`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.container.bridge_endpoints = [{host: "10.44.0.7", port: 3306, docker: true}];
        f.migrate.databases = [{host: "10.44.0.7", port: 3306}];
        f.write();
        Deno.writeTextFileSync(`${f.folder}/nat.txt`, Array.from({length: mappings}, (_, i) => `-A DOCKER -d 10.44.0.7/32 ! -i docker0 -p tcp -m tcp --dport 3306 -j DNAT --to-destination 172.17.0.${i + 2}:3306`).join("\n"));
        const result = f.run("container");
        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.container_endpoint_unresolved", result.output);
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
        const calls = Deno.readTextFileSync(`${f.folder}/calls`);
        assert(!calls.includes('"PUT"'));
        assert(!calls.includes('/dev/tcp/$1/$2'));
        assertEquals(JSON.parse(Deno.readTextFileSync(`${f.folder}/bridge-policy/health.json`)).error_code, "instance.container_endpoint_unresolved");
      } finally {f.cleanup();}
    },
  });
}
