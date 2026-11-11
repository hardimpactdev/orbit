import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { has, hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a database server that listens is reached from the container",
  ignore: !hasAnsible || !has("timeout"),
  async fn() {
    const f = fixture();
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const accepting = (async () => {
      for await (const connection of listener) connection.close();
    })();
    try {
      f.migrate.databases.push({ host: "127.0.0.1", port: (listener.addr as Deno.NetAddr).port });
      f.write();
      const done = await new Promise<void>((resolve, reject) => {
        try {
          f.build();
          resolve();
        } catch (error) {
          reject(error);
        }
      });
      assertEquals(done, undefined);
    } finally {
      listener.close();
      await accepting.catch(() => {});
      f.cleanup();
    }
  },
});
