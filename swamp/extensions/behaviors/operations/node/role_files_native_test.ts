import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { type Flow } from "../../../../tests/_support/node_host.ts";

async function fixture() {
  const repo = await nativeRepo();
  await repo.copyBehaviors("operations", "node-worker", "automatic-deploy");
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/native-role-fixture.ts`,
    'import {z} from "npm:zod@4.4.3";\nexport const behavior={type:"@fixture/native-role-files",version:"2026.11.02.1",resources:{data:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{\nplan:{description:"Public admission fixture",arguments:z.object({snapshot:z.record(z.string(),z.unknown())}),execute:async(args,ctx)=>{const data={snapshot:args.snapshot,ready:true,started_at:"2026-11-02T00:00:00Z",refusal:null};await ctx.writeResource("data","data",data);return data;}},\napply:{description:"Harmless child transport boundary",arguments:z.object({fail:z.boolean()}),execute:async(args)=>{if(args.fail)throw new Error("Native child failed");return {};}}\n}};',
  );
  const source = await repo.workflow("operation-node-role-relocate-direct");
  const jobs = (source as unknown as Flow).jobs.filter((j) =>
    ["private_dns_job", "php_runtime_job", "tmpfiles_job", "route_residue_job"].includes(j.name)
  );
  assertEquals(jobs.length, 4);
  for (const job of jobs) {
    job.dependsOn = [{ job: "admission", condition: { type: "succeeded" } }];
    for (const step of job.steps) {
      const task = step.task;
      if (task.type === "workflow") {
        (task.inputs!.intent as Record<string, unknown>).fixture_fail = "${{ inputs.fail }}";
      } else if (task.behaviorType === "@swamp/cfgmgmt/file") {
        step.task = {
          type: "behavior",
          behaviorType: "@fixture/native-role-files",
          behaviorName: "tmpfiles-fixture",
          methodName: "apply",
          inputs: { fail: "${{ inputs.fail }}" },
        };
      }
    }
  }
  await Deno.remove(`${repo.dir}/workflows/workflow-operation-node-role-relocate-direct.yaml`);
  await Deno.writeTextFile(
    `${repo.dir}/workflows/workflow-role-files-native.yaml`,
    JSON.stringify({
      id: source.id,
      name: "role-files-native",
      inputs: {
        type: "object",
        properties: {
          operation_id: { type: "integer" },
          node_id: { type: "integer" },
          kind: { type: "string" },
          snapshot: { type: "object" },
          fail: { type: "boolean" },
        },
        required: ["operation_id", "node_id", "kind", "snapshot", "fail"],
      },
      jobs: [{
        name: "admission",
        steps: ["admit", "prepare_footprint"].map((name) => ({
          name,
          task: {
            type: "behavior",
            behaviorType: "@fixture/native-role-files",
            behaviorName: name + "-fixture",
            methodName: "plan",
            inputs: { snapshot: "${{ inputs.snapshot }}" },
          },
        })),
      }, ...jobs],
    }),
  );
  // Preserve each child input schema while holding its host effects.
  for (const name of ["node-private-dns", "node-php-runtime", "node-route-residue"]) {
    await repo.workflow(name, (child) => {
      child.jobs = [{
        name: "native",
        steps: [{
          name: "apply",
          task: {
            type: "behavior",
            behaviorType: "@fixture/native-role-files",
            behaviorName: name + "-fixture",
            methodName: "apply",
            inputs: { fail: "${{ inputs.intent.fixture_fail }}" },
          },
        }],
      }] as unknown as typeof child.jobs;
    });
  }
  return repo;
}
const snapshot = {
  "schema_version": 1,
  "node_id": 9,
  "agent_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "assignment_id": 11,
  "role": "app-dev",
  "created": false,
  "user": "orbit",
  "publication": { "caddy_node_ids": [], "php_node_ids": [], "dns_node_ids": [] },
  "prerequisites": { "packages": [], "releases": ["resolute"], "apps_directory": false, "composer_home": false },
  "firewall": null,
  "cli": { "user": "orbit", "release": null, "profile": "", "skip_reason": "release_unavailable", "plan_error": null },
  "ssh": { "target": { "host": "fixture", "user": "orbit", "port": 22 }, "identity_file": "/synthetic" },
  "role_files": {
    "dns": {},
    "residue": {},
    "home": "/home/orbit",
    "php": { "8.5": "public ini" },
    "php_versions": ["8.5"],
    "tmpfiles": "e /tmp/orbit-* - - - 1d",
  },
  "footprint": {
    "schema_version": 1,
    "node_id": 9,
    "agent_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "force_artifacts": false,
    "first_visit": false,
    "network": null,
    "refusal": null,
    "payloads": {},
    "artifacts": [{
      "name": "private-dns",
      "digest": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
      "required": true,
      "repair": false,
    }, {
      "name": "php-runtime",
      "digest": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
      "required": true,
      "repair": false,
    }, {
      "name": "route-residue",
      "digest": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
      "required": true,
      "repair": false,
    }, {
      "name": "tmpfiles",
      "digest": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
      "required": true,
      "repair": false,
    }],
  },
};
for (const fail of [false, true]) {
  Deno.test(`required role files with child failure ${fail}`, async () => {
    await using repo = await fixture();
    await repo.cli(["workflow", "validate", "role-files-native"]);
    const { run, steps } = await repo.runWorkflow("role-files-native", {
      operation_id: 7,
      node_id: 9,
      kind: "node.role_add",
      snapshot,
      fail,
    }, { success: !fail });
    assertEquals(run.status, fail ? "failed" : "succeeded");
    if (!fail) assertEquals(steps["native_php_runtime-8.5"], "succeeded");
    for (const name of ["private_dns", "php_runtime", "tmpfiles"]) {
      assertEquals(steps[name + "_proven"], fail ? "failed" : "succeeded");
    }
    assertEquals(steps.route_residue_proven, "succeeded");
  });
}
