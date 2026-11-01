import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { DependencyProjectsSchema } from "../extensions/behaviors/_lib/dependency_projects.ts";
import { runStage, type Http, type Job, type StageArgs } from "../extensions/behaviors/_lib/gateway_stage.ts";
import { SECOND_PROJECT, WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

Deno.test("dependency project config requires worker paths and rejects the retired script field", () => {
  assert(DependencyProjectsSchema.safeParse([WEBSITE_PROJECT, SECOND_PROJECT]).success);
  assertEquals(DependencyProjectsSchema.safeParse([WEBSITE_PROJECT, WEBSITE_PROJECT]).success, false);
  const { worker: _worker, ...missing } = WEBSITE_PROJECT;
  assertEquals(DependencyProjectsSchema.safeParse([missing]).success, false);
  assertEquals(DependencyProjectsSchema.safeParse([{ ...WEBSITE_PROJECT, script: "scripts/dependencies" }]).success, false);
});

Deno.test("worker configuration refuses escaping, duplicate and unconfigured policy paths", () => {
  const worker = { composerPaths: ["."], jsPaths: [], policy: { ".": { minimumReleaseAgeDays: 7 } } };
  assert(DependencyProjectsSchema.safeParse([{ ...WEBSITE_PROJECT, worker }]).success);
  for (const patch of [{ composerPaths: [] }, { composerPaths: ["../other"] }, { composerPaths: ["/host"] }, { composerPaths: [".", "."] }, { policy: { "unknown": { minimumReleaseAgeDays: 7 } } }]) {
    assertEquals(DependencyProjectsSchema.safeParse([{ ...WEBSITE_PROJECT, worker: { ...worker, ...patch } }]).success, false);
  }
});
