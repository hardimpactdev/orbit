import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, load, plays, play, GiB, Task, local, transform, fixture } from "./_support/ansible_task_container_host_prepare.ts";

Deno.test({
  name:
    "a container host on a native pool gets the ARC cap now and after reboot, and keeps it when containers go",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ native: true });
    try {
      let result = f.run();
      assert(result.ok, result.output);
      // No cap ran before, so one eighth of 32 GiB applies, on a pool where a VM host changes no ARC.
      assertEquals(f.read("arc").trim(), String(4 * GiB));
      const conf =
        `# Orbit task container host: reserve RAM for host services.\noptions zfs zfs_arc_max=${
          4 * GiB
        }\n`;
      assertEquals(f.read("arc.conf"), conf);
      assertStringIncludes(
        f.calls().map((c) => c.join(" ")).join("\n"),
        "storage get orbit-tasks zfs.pool_name",
      );
      result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(result.output, "changed=0");

      const intent = JSON.parse(f.read("intent.json"));
      delete intent.host.containers;
      Deno.writeTextFileSync(`${f.folder}/intent.json`, JSON.stringify(intent));
      result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(result.output, "changed=0");
      assertEquals(f.read("arc.conf"), conf);
      assertEquals(f.read("arc").trim(), String(4 * GiB));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the container rule never raises a cap the host already runs",
  ignore: !hasAnsible,
  fn() {
    // Beast: a loop pool with its 2 GiB cap and 128 GiB of memory. A native pool with a 3 GiB cap of the operator's.
    for (
      const [label, options, cap] of [
        [
          "loop pool with 2 GiB",
          { memory: 128 * GiB, arc: String(2 * GiB) },
          2 * GiB,
        ],
        [
          "native pool with 3 GiB",
          { native: true, arc: String(3 * GiB) },
          3 * GiB,
        ],
      ] as [string, Parameters<typeof fixture>[0], number][]
    ) {
      const f = fixture(options);
      try {
        const result = f.run();
        assert(result.ok, `${label}: ${result.output}`);
        assertEquals(f.read("arc").trim(), String(cap), label);
        assertStringIncludes(f.read("arc.conf"), `zfs_arc_max=${cap}\n`, label);
      } finally {
        f.cleanup();
      }
    }
  },
});

