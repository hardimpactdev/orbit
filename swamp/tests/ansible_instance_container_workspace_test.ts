import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

Deno.test({
  name: "the workspace stays writable for its group before and after the deploy steps, whoever installed last",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace"]) assert(f.run(phase).ok);
      const workspace = `${f.folder}/containers/instance-7/srv/workspace`;
      // An install as `orbit` asks for 0755 folders and 0644 files, as Bun does in node_modules.
      Deno.mkdirSync(`${workspace}/node_modules/react`, { recursive: true, mode: 0o755 });
      Deno.chmodSync(`${workspace}/node_modules`, 0o755);
      Deno.chmodSync(`${workspace}/node_modules/react`, 0o755);
      Deno.writeTextFileSync(`${workspace}/node_modules/react/index.js`, "x");
      Deno.chmodSync(`${workspace}/node_modules/react/index.js`, 0o644);
      const mode = (path: string) => Deno.statSync(`${workspace}/${path}`).mode! & 0o777;
      const processes = f.run("processes");
      assert(processes.ok, processes.output);
      assertEquals([mode("node_modules"), mode("node_modules/react"), mode("node_modules/react/index.js")], [0o775, 0o775, 0o664]);
      // Nothing left to change: a rerun changes nothing.
      assertEquals(f.run("processes").changed, 0);

      // A later install as `agent` leaves 0755 folders again; the next deployment's checkout opens them before its steps run.
      Deno.mkdirSync(`${workspace}/node_modules/.vite`, { mode: 0o755 });
      Deno.chmodSync(`${workspace}/node_modules/.vite`, 0o755);
      assert(f.run("fetch").ok);
      const checkout = f.run("checkout");
      assert(checkout.ok, checkout.output);
      assertEquals(mode("node_modules/.vite"), 0o775);
    } finally {
      f.cleanup();
    }
  },
});
