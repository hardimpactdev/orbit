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
    await git("init", "-b", "main");
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
    await git(
      "config",
      `url.${root}.insteadOf`,
      "https://github.com/nckrtl/horizon-new-dawn.git",
    );
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

Deno.test("detection refreshes origin/main and rejects a stale checkout or failed fetch before writing a plan", async () => {
  const root = await Deno.makeTempDir({ prefix: "horizon-stale-clone-" });
  const remote = `${root}/remote`, checkout = `${root}/checkout`;
  await Deno.mkdir(remote);
  const git = async (cwd: string, ...args: string[]) => {
    const out = await new Deno.Command("git", {
      cwd,
      args,
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(out.success, true, new TextDecoder().decode(out.stderr));
    return new TextDecoder().decode(out.stdout).trim();
  };
  const commit = () =>
    git(
      remote,
      "-c",
      "user.name=Fixture",
      "-c",
      "user.email=fixture@example.test",
      "-c",
      "commit.gpgsign=false",
      "commit",
      "--allow-empty",
      "-m",
      "upstream",
    );
  const writes: string[] = [];
  const ctx = {
    writeResource: (spec: string, name: string) => {
      writes.push(spec);
      return Promise.resolve({ name });
    },
  };
  try {
    await git(remote, "init", "-b", "main");
    await commit();
    await git(root, "clone", remote, checkout);
    await git(
      checkout,
      "remote",
      "set-url",
      "origin",
      "https://github.com/nckrtl/horizon-new-dawn.git",
    );
    await git(
      checkout,
      "config",
      `url.${remote}.insteadOf`,
      "https://github.com/nckrtl/horizon-new-dawn.git",
    );
    await behavior.methods.detect.execute({ cwd: checkout }, ctx);
    assertEquals(writes, ["plan"]);
    const oldHead = await git(checkout, "rev-parse", "HEAD");
    await commit();
    const latest = await git(remote, "rev-parse", "HEAD");
    assertEquals(await git(checkout, "rev-parse", "origin/main"), oldHead);
    writes.length = 0;
    await assertRejects(
      () => behavior.methods.detect.execute({ cwd: checkout }, ctx),
      Error,
      "checkout of the fetched default branch",
    );
    assertEquals(await git(checkout, "rev-parse", "origin/main"), latest);
    assertEquals(await git(checkout, "rev-parse", "HEAD"), oldHead);
    assertEquals(writes, []);
    await Deno.rename(remote, `${root}/unavailable`);
    await assertRejects(
      () => behavior.methods.detect.execute({ cwd: checkout }, ctx),
      Error,
      "Could not refresh Horizon origin/main",
    );
    assertEquals(writes, []);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

Deno.test("Gateway HTML and empty bodies produce clear status-bearing errors", async () => {
  let body = "<html>upstream unavailable</html>";
  const caFile = await Deno.makeTempFile({ prefix: "horizon-http-ca-" });
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    () => new Response(body, { status: 502 }),
  );
  const ctx = {
    globalArgs: {
      gatewayUrl: `http://127.0.0.1:${server.addr.port}`,
      caFile,
      timeoutSeconds: 5,
    },
    definition: { name: "orbit-gateway" },
    queryData: () =>
      Promise.resolve([{
        tokenName: "task-group-456-1",
        ready: true,
        revoked: false,
      }]),
    writeResource: () => {
      throw new Error("must not write resources");
    },
  };
  try {
    for (const value of ["<html>upstream unavailable</html>", ""]) {
      body = value;
      const error = await assertRejects(
        () =>
          extension.methods[0].horizon_worker.execute({
            group: 456,
            worker: "task-group-456-1",
          }, ctx),
        Error,
      );
      assertEquals(
        error.message,
        "Horizon Gateway returned an empty or non-JSON body (HTTP 502); reconcile before retrying",
      );
    }
  } finally {
    await server.shutdown();
    await Deno.remove(caFile);
  }
});
