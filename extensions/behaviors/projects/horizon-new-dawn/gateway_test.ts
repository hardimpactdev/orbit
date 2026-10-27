import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { extension, verifyWorker } from "./gateway.ts";
import { behavior } from "./mod.ts";
import { project } from "./config.ts";
import { type Http } from "./_lib/queue.ts";

Deno.test("worker admission checks the current enrollment, package repo and merge policy without changing either", async () => {
  const args = { group: 456, worker: "task-group-456-2" };
  let session: Record<string, unknown> = {
    tokenName: args.worker,
    ready: true,
    revoked: false,
  };
  let repo = "git@github.com:nckrtl/horizon-new-dawn.git",
    review = false,
    status = "running";
  const calls: string[] = [];
  const http: Http = (method, path) => {
    assertEquals(method, "GET");
    calls.push(path);
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("/task-groups/")
          ? { project_id: 123, orchestration: "external", status }
          : { repository_url: repo, review_and_merge: review },
      },
    });
  };
  const read = (predicate: string, select: string) => {
    assertEquals(
      predicate,
      'modelName == "orbit-gateway" && name == "worker-456"',
    );
    assertEquals(select, "attributes");
    return Promise.resolve([session]);
  };
  const run = () =>
    verifyWorker(args, "orbit-gateway", project.repository, read, http);
  await run();
  assertEquals(calls, ["/api/v1/task-groups/456", "/api/v1/projects/123"]);
  for (
    const change of [{ tokenName: "task-group-456-1" }, { ready: false }, {
      revoked: true,
    }]
  ) {
    session = {
      tokenName: args.worker,
      ready: true,
      revoked: false,
      ...change,
    };
    await assertRejects(run, Error, "current enrolled worker");
  }
  session = { tokenName: args.worker, ready: true, revoked: false };
  repo = "https://github.com/nckrtl/horizon-new-dawn-website.git";
  await assertRejects(run, Error, "package repository");
  repo = "https://github.com/nckrtl/horizon-new-dawn.git";
  review = true;
  await assertRejects(run, Error, "wait for Nick");
  review = false;
  status = "completed";
  await assertRejects(run, Error, "open external group");
  assertEquals(extension.type, "@nckrtl/orbit-gateway");
});
Deno.test("configuration and missing-ledger baseline writes conform to declared resource schemas", async () => {
  const root = await Deno.makeTempDir({ prefix: "horizon-behavior-" });
  const writes: {
    spec: string;
    name: string;
    data: Record<string, unknown>;
  }[] = [];
  const ctx = {
    writeResource: (
      spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      behavior.resources[spec as keyof typeof behavior.resources].schema.parse(
        data,
      );
      writes.push({ spec, name, data });
      return Promise.resolve({ name });
    },
  };
  const git = async (...args: string[]) => {
    const out = await new Deno.Command("git", {
      cwd: root,
      args,
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(out.success, true, new TextDecoder().decode(out.stderr));
  };
  try {
    await behavior.methods.configuration.execute({}, ctx);
    assertEquals(writes[0].data, project);
    await git("init");
    await git(
      "remote",
      "add",
      "origin",
      "https://github.com/nckrtl/horizon-new-dawn.git",
    );
    await git(
      "-c",
      "user.name=Fixture",
      "-c",
      "user.email=fixture@example.test",
      "-c",
      "commit.gpgsign=false",
      "commit",
      "--allow-empty",
      "-m",
      "baseline",
    );
    await git("update-ref", "refs/remotes/origin/main", "HEAD");
    await behavior.methods.detect.execute({ cwd: root }, ctx);
    assertEquals(writes[1].spec, "plan");
    assertEquals(writes[1].data.baseline, true);
    assertEquals((writes[1].data.tasks as unknown[]).length, 1);
    await Deno.writeTextFile(
      `${root}/${project.ledger}`,
      '{"synced_tag":"v5.50.1"}',
    );
    await assertRejects(
      () => behavior.methods.detect.execute({ cwd: root }, ctx),
      Error,
      "Commit",
    );
    await git(
      "remote",
      "set-url",
      "origin",
      "https://github.com/nckrtl/horizon-new-dawn-website.git",
    );
    await assertRejects(
      () => behavior.methods.detect.execute({ cwd: root }, ctx),
      Error,
      "checkout must match",
    );
    assertEquals(writes.length, 2);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
