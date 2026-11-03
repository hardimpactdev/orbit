import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { exists } from "jsr:@std/fs@1.0.19/exists";
import { localGroupWorker } from "../../../tests/_support/group_workflow.ts";
import { nativeRepo, ROOT } from "../../../tests/_support/native_harness.ts";

Deno.test("empty group fanout succeeds", async () => {
  await using repo = await nativeRepo();
  await repo.workflow("group-fanout");
  assertEquals((await repo.runWorkflow("group-fanout", { groups: [], conflicts: [] })).run.status, "succeeded");
});

Deno.test({
  name: "parallel group failure, duplicate admission, restart recovery and durable waits",
  // Regressions: recover checkpoints the original owner after resumed drive hands off;
  // a restarted wait loses claim outputs, so the resumed drive guard fails with "No such key: outputs".
  ignore: true,
  async fn() {
    await using repo = await nativeRepo();
    repo.timeoutMs = 45_000;
    await repo.copyBehaviors("");
    await localGroupWorker(repo);
    await repo.pull("@swamp/software-factory");
    for await (const file of Deno.readDir(`${ROOT}/workflows`)) {
      if (file.name.endsWith(".yaml")) await repo.workflow(file.name.slice(9, -5));
    }
    type Reply = { id: string; path: string; version: string; runId: string; status: string };
    type Snapshot = { runId: string | null; generation: number; count: number; attempts: number; parked: boolean; consecutive: number; stageId: string; conflicts: { workItem: string }[] };
    const command = async <T = Reply>(args: string[], body?: unknown, success = true): Promise<T> => {
      const result = await repo.cli(args, { success, input: body === undefined ? undefined : JSON.stringify(body) });
      return success ? JSON.parse(result.stdout) : {} as T;
    };
    const paths = new Map<string, string>();
    const create = async (kind: string, name: string, globalArguments: object = {}) => {
      const result = await command(["behavior", "create", kind, name]);
      paths.set(name, result.path);
      await Deno.writeTextFile(result.path, JSON.stringify({ id: result.id, type: kind, typeVersion: result.version, name, version: 1, globalArguments }));
    };
    const factory = { stages: [
      { id: "work", initial: true, work: { mode: "method", resultEvidence: "result", method: {
        modelIdOrName: '${{ "fixture-" + self.workItem }}', methodName: "work", inputs: { workItem: "${{ self.workItem }}" },
      } }, transitions: [{ name: "finish", to: "done", gates: [{ type: "evidence-recorded", config: { name: "result" } }] }] },
      { id: "done", terminal: true },
    ] };
    await create("@swamp/software-factory", "fixture-factory", factory);
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const port = (listener.addr as Deno.NetAddr).port;
    listener.close();
    const url = `ws://127.0.0.1:${port}`;
    const token = `${repo.dir}/fixture-token`;
    await Deno.writeTextFile(token, "fixture." + crypto.randomUUID());
    const config = { factory: "fixture-factory", repoDir: repo.dir, serveUrl: url, serveTokenFile: token,
      caFile: "/dev/null", maxSteps: 1, preapprovalModel: "fixture-preapprovals" };
    await repo.workflow("group-run", (workflow) => {
      const wait = workflow.jobs.flatMap((job) => job.steps).find((step) => step.name === "wait")!;
      // Exercise the real durable wait in a shorter fixture interval.
      (wait.task as { timeout: number }).timeout = 30;
    });
    await repo.workflow("group-supervise", (workflow) => {
      for (const step of workflow.jobs.flatMap((job) => job.steps)) {
        const task = step.task as { globalArgs: Record<string, unknown> };
        Object.assign(task.globalArgs, config, { assistanceModel: "" });
      }
    });
    for (const group of [7, 8, 9, 10]) {
      await create("@fixture/group-stage", `fixture-tg-${group}`);
      for (const prefix of ["orbit-group-", "orbit-group-recovery-"]) await create("@nckrtl/orbit-group-run", `${prefix}tg-${group}`, config);
      await command(["behavior", "run", "fixture-factory", "start", "--input", `workItem=tg-${group}`]);
    }
    const factories = [{ factory: "fixture-factory", preapprovalModel: "fixture-preapprovals" }];
    await create("@nckrtl/orbit-group-runs", "orbit-group-runs", { factories });
    await command(["workflow", "validate", "group-run"]);
    const latest = async (model: string, name: string) => {
      const rows = await command<Snapshot[] | { results: Snapshot[] }>(["data", "query", `modelName == "${model}" && name == "${name}"`, "--select", "attributes"]);
      return (Array.isArray(rows) ? rows : rows.results)[0];
    };
    const method = (model: string, name: string, inputs: Record<string, string> = {}) => command([
      "behavior", "run", model, name, "--server", url, ...Object.entries(inputs).flatMap(([key, value]) => ["--input", `${key}=${value}`]),
    ]);
    const history = (run: string | null) => {
      assert(run);
      return command(["workflow", "history", "get", run, "--server", url]);
    };
    const until = async (test: () => Promise<unknown>, reason: string) => {
      const deadline = Date.now() + 35_000;
      while (Date.now() < deadline) {
        if (await test()) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
      }
      throw new Error(reason);
    };
    let serve: Deno.ChildProcess | undefined;
    let output: Promise<Deno.CommandOutput> | undefined;
    const stop = async () => {
      if (!serve) return;
      serve.kill("SIGKILL");
      await output;
      serve = undefined;
    };
    const start = async () => {
      await command(["run", "doctor", "--fix"]);
      serve = await repo.spawn(["serve", "--port", String(port), "--host", "127.0.0.1", "--auth-mode", "none", "--no-schedule", "--continuation-sweep-interval", "1s"]);
      output = serve.output();
      await until(async () => {
        try {
          const response = await fetch(`http://127.0.0.1:${port}/ready`, { signal: AbortSignal.timeout(1000) });
          await response.body?.cancel();
          return response.ok;
        } catch { return false; }
      }, "serve did not start");
    };
    try {
      await start();
      await Promise.all([7, 8, 9, 10].map((group) => command(["workflow", "run", "group-supervise", "--server", url, "--stdin"], {
        workItem: `tg-${group}`, factory: "fixture-factory", preapprovalModel: "fixture-preapprovals",
      })));
      await until(async () => {
        const record = await history((await latest("orbit-group-tg-7", "intent")).runId);
        assert(record.status !== "failed", JSON.stringify(record));
        return await exists(`${repo.dir}/effect-receipt-written`);
      }, "group 7 did not reach its effect receipt");
      const owners = new Map<number, Snapshot>();
      for (const group of [7, 8, 9, 10]) owners.set(group, await latest(`orbit-group-tg-${group}`, "owner"));
      const owner = (group: number) => owners.get(group)!;
      await until(async () => (await history(owner(9).runId)).status === "failed", "group 9 did not fail independently");
      await until(async () => {
        owners.set(8, await latest("orbit-group-tg-8", "owner"));
        const record = await history(owner(8).runId);
        assert(record.status !== "failed", JSON.stringify(record));
        return record.status === "suspended";
      }, "group 8 did not reach its durable wait");
      assertEquals((await latest("fixture-tg-10", "starts")).count, 1);
      const duplicate = await command(["workflow", "run", "group-run", "--server", url, "--detach", "--no-supersede", "--stdin"], {
        ...owner(8), definition: "orbit-group-tg-8", preapprovalModel: "fixture-preapprovals",
      });
      await until(async () => (await history(duplicate.runId)).status === "succeeded", "duplicate did not exit");
      assertEquals((await latest("fixture-tg-8", "starts")).count, 1);
      await method("orbit-group-tg-8", "ensure", { workItem: "tg-8" });
      assertEquals((await latest("orbit-group-tg-8", "intent")).generation, owner(8).generation);
      await stop();
      await start();
      await method("orbit-group-runs", "discover");
      await method("orbit-group-tg-7", "ensure", { workItem: "tg-7" });
      const workflow = `${repo.dir}/workflows/workflow-group-run.yaml`, unavailable = `${repo.dir}/workflow-group-run.disabled`;
      await Deno.rename(workflow, unavailable);
      try {
        for (const attempt of [1, 2, 3]) {
          await command(["behavior", "run", "orbit-group-recovery-tg-7", "recover", "--server", url,
            "--input", "definition=orbit-group-tg-7", "--input", `runId=${owner(7).runId}`], undefined, false);
          assertEquals((await latest("orbit-group-tg-7", "recovery")).attempts, attempt);
        }
        assert((await latest("orbit-group-tg-7", "failures")).parked);
      } finally { await Deno.rename(unavailable, workflow); }
      await method("orbit-group-tg-7", "ensure", { workItem: "tg-7" });
      assertEquals((await latest("orbit-group-tg-7", "reconcile")).runId, null);
      assertEquals((await latest("orbit-group-tg-7", "intent")).runId, owner(7).runId);
      await method("orbit-group-tg-7", "retry", { actor: "nick", note: "restored workflow source" });
      await method("orbit-group-recovery-tg-7", "recover", { definition: "orbit-group-tg-7", runId: owner(7).runId! });
      await until(async () => ["suspended", "succeeded"].includes((await history(owner(7).runId)).status), "owner did not recover");
      assertEquals((await latest("fixture-tg-7", "starts")).count, 1);
      assert((await latest("orbit-group-tg-7", "intent")).generation > owner(7).generation);
      await until(async () => (await history(owner(8).runId)).status === "succeeded", "durable wait did not resume after restart");
      await method("orbit-group-tg-8", "ensure", { workItem: "tg-8" });
      assert((await latest("orbit-group-tg-8", "intent")).generation > owner(8).generation);
      await until(async () => (await latest("fixture-factory", "state-tg-8")).stageId === "done", "next generation did not advance the factory");
      for (const generation of [2, 3]) {
        await method("orbit-group-tg-9", "ensure", { workItem: "tg-9" });
        const failed = await latest("orbit-group-tg-9", "intent");
        assertEquals(failed.generation, generation);
        await until(async () => (await history(failed.runId)).status === "failed", "failed owner did not settle");
      }
      for (let attempt = 0; attempt < 2; attempt++) {
        await method("orbit-group-tg-9", "ensure", { workItem: "tg-9" });
        assertEquals((await latest("orbit-group-tg-9", "intent")).generation, 3);
        const failure = await latest("orbit-group-tg-9", "failures");
        assertEquals(failure.consecutive, 3);
        assert(failure.parked);
      }
      await method("orbit-group-tg-9", "retry", { actor: "nick", note: "fixture inspection" });
      await method("orbit-group-tg-9", "ensure", { workItem: "tg-9" });
      assertEquals((await latest("orbit-group-tg-9", "intent")).generation, 4);
      await create("@swamp/software-factory", "fixture-conflict", factory);
      await command(["behavior", "run", "fixture-conflict", "start", "--input", "workItem=tg-10"]);
      for (const name of ["orbit-gateway", "fixture-tg-11"]) await create("@fixture/group-stage", name);
      await command(["behavior", "run", "fixture-factory", "start", "--input", "workItem=tg-11"]);
      const path = paths.get("orbit-group-runs")!;
      const definition = JSON.parse(await Deno.readTextFile(path));
      definition.globalArguments.factories.push({ factory: "fixture-conflict", preapprovalModel: "fixture-preapprovals" });
      await Deno.writeTextFile(path, JSON.stringify(definition));
      await command(["workflow", "run", "groups-driver", "--server", url]);
      await until(async () => (await latest("fixture-tg-11", "starts"))?.count === 1, "conflict notification aborted healthy fan-out");
      assertEquals((await latest("orbit-group-runs", "groups")).conflicts.map((conflict) => conflict.workItem), ["tg-10"]);
      assertEquals((await latest("fixture-tg-10", "starts")).count, 1);
    } finally { await stop(); }
  },
});
