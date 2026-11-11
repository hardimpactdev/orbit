import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, load, plays, play, GiB, Task, local, transform, fixture } from "./_support/ansible_task_container_host_prepare.ts";

Deno.test({
  name:
    "a container host is refused before its container side changes: another Incus line, SCTP, subordinate IDs, a taken bridge",
  ignore: !hasAnsible,
  fn() {
    const cases: [string, Parameters<typeof fixture>[0], string?][] = [
      ["incus line", { server: "6.0.5" }],
      ["sctp rule", {
        sctp: "insmod /lib/modules/7.0.0/kernel/net/sctp/sctp.ko",
      }],
      ["sctp loaded", { sctpLoaded: true }],
      // Three places and the base need 404,000,000 IDs.
      [
        "subordinate ids",
        { subids: 400000000 },
        "root needs 404000000 subordinate IDs",
      ],
      ["taken bridge", {}],
    ];
    for (const [label, options, message] of cases) {
      const f = fixture(options);
      try {
        if (label === "taken bridge") {
          const state = f.state();
          state.networks.push({
            name: "orbittaskc0",
            type: "bridge",
            managed: true,
            config: { "ipv4.address": "10.252.99.1/24" },
          });
          Deno.writeTextFileSync(
            `${f.folder}/state.json`,
            JSON.stringify(state),
          );
        }
        const result = f.run();
        assertEquals(result.ok, false, `${label}: ${result.output}`);
        assertStringIncludes(result.output, message ?? "task_vm.setup_failed");
        assert(
          !f.calls().some((c) =>
            c.includes("orbittaskc0") && c[0] !== "query"
          ) &&
            !f.calls().some((c) => c.includes("orbit-task-containers")),
          `${label}: ${JSON.stringify(f.calls())}`,
        );
      } finally {
        f.cleanup();
      }
    }
  },
});

Deno.test({
  name: "a copy that Incus shifted fails the phase and leaves no probe",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ written: String(500 * 1024 * 1024) });
    try {
      const result = f.run();
      assertEquals(result.ok, false, result.output);
      assertStringIncludes(result.output, "task_vm.setup_failed");
      assertEquals(f.state().instances, []);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clone probe that a failed run left is removed before the next probe",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const state = f.state();
      state.instances = [
        {
          name: "orbit-clone-probe",
          project: "orbit-task-containers",
          status: "Stopped",
        },
        {
          name: "orbit-clone-probe-copy",
          project: "orbit-task-containers",
          status: "Running",
        },
      ];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const result = f.run();
      assert(result.ok, result.output);
      const deletes = f.calls().filter((c) => c[2] === "delete").map((c) =>
        c.at(-1)
      );
      assertEquals(deletes.slice(0, 2), [
        "orbit-clone-probe",
        "orbit-clone-probe-copy",
      ]);
      assertEquals(f.state().instances, []);
    } finally {
      f.cleanup();
    }
  },
});
