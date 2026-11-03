import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
const names = [
  "operation-analytics-disable-direct",
  "route-target-retire",
  "route-create-site",
  "route-create-dns",
  "route-certificate-remove",
  "route-remove-check",
];
for (const fail of [false, true]) {
  Deno.test(`analytics disable chart and failure stop: fail=${fail}`, async () => {
    await using repo = await nativeRepo();
    repo.timeoutMs = 180_000;
    await repo.copyBehaviors("operations/certificates");
    await repo.pull("@swamp/ssh");
    const identity = `${repo.dir}/identity`;
    await Deno.writeTextFile(identity, "synthetic identity", { mode: 0o600 });
    const nodes = {
      "9": {
        host: "node-9.invalid",
        user: "orbit",
        port: 22,
        identity_file: identity,
        host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
        account: "orbit",
      },
    };
    const retire = {
      ...Object.fromEntries(
        [
          "acl",
          "links",
          "php_files",
          "php_tests",
          "php_reloads",
          "caddy",
          "url_lines",
          "env_files",
          "trust",
          "lan",
          "leaf_indexes",
          "leaves",
          "site_nodes",
        ].map((k) => [k, []]),
      ),
      root_certificate: "",
      nodes,
    };
    if (fail) {
      Object.assign(retire, { site_nodes: [9], php_tests: [{ node_id: 9, argv: ["/usr/sbin/php-fpm8.5", "-t"] }] });
    }
    const intent = {
      leaves: [],
      leaf_indexes: [],
      root_certificate: "",
      nodes,
      dns: [],
      cleanup: [],
      lan_absent: [],
      retire_nodes: fail ? [9] : [],
      retire,
      check_nodes: fail ? [9] : [],
      lan_remove: [],
      ingress_close: [],
    };
    const ssh = await repo.fakeBinary(
      "ssh",
      `if (!Deno.args.includes("-M") && !Deno.args.includes("-O")) Deno.exit(${fail ? 1 : 0});`,
    );
    for (const name of names) {
      await repo.workflow(name, (flow) => {
        for (const step of flow.jobs.flatMap((j) => j.steps)) {
          const task = step.task as { behaviorType?: string; globalArgs?: object };
          if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: ssh });
        }
      });
    }
    const { run, steps, history } = await repo.runWorkflow(names[0], {
      operation_id: 76,
      node_id: 9,
      kind: "analytics.disable",
      execution_secrets: {},
      intent,
    }, { success: !fail });
    assertFalse(JSON.stringify(history).includes("No such key"));
    assertEquals(run.status, fail ? "failed" : "succeeded");
    if (fail) {
      assertEquals(steps["Withdraw sites, certs-0"], "failed");
      assertEquals(
        new Set(
          Object.entries(steps).filter(([name]) => name.startsWith("Check the hosts are gone")).map(([, s]) => s),
        ),
        new Set(["skipped"]),
      );
    } else {
      await repo.cli(["workflow", "validate", names[0]]);
      const flow = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${names[0]}.yaml`)) as {
        jobs: { steps: { name: string }[] }[];
      };
      assertEquals(flow.jobs[0].steps.map((s) => s.name), [
        "Remove private DNS",
        "Withdraw sites, certs",
        "Check the hosts are gone",
      ]);
    }
  });
}
