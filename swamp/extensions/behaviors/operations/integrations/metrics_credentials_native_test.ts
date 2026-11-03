/** Native credential DAG, real loopback HTTP and official SSH; no fleet contact. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { sha256, type Task } from "../../../../tests/_support/node_host.ts";

const NAME = "operation-metrics-credentials-reset";
const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  const active = `fixture-active-"\\${crypto.randomUUID()}`, pending = `fixture-pending-${crypto.randomUUID()}`;
  repo.secrets = [active, pending, JSON.stringify({ active_password: active, pending_password: pending })];
  const state = { current: active, successStatus: 200, applied: 0, failApply: false, failConfirm: false };
  const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen: () => {} }, async (request) => {
    const authorized = request.headers.get("Authorization") === "Basic " + btoa("admin:" + state.current);
    let status = authorized ? state.successStatus : 401;
    if (request.method === "PUT") {
      const body = await request.json();
      if (state.failApply) status = 503;
      else if (authorized && body.oldPassword === state.current && body.newPassword === pending) {
        state.current = pending;
        state.applied++;
        status = 200;
      } else status = 401;
    } else if (state.failConfirm && state.applied && status === 200) status = 503;
    return new Response(status === 204 ? null : '{"public":true}', { status });
  });
  const port = (server.addr as Deno.NetAddr).port;
  try {
    await repo.pull("@swamp/ssh");
    const callsPath = `${repo.dir}/calls.jsonl`;
    const ssh = await repo.fakeBinary(
      "fixture-ssh",
      `
import {shellSplit, readStdinBytes, logCall, passThrough} from ${JSON.stringify(tool)};
const argv = shellSplit(Deno.args.at(-1)!);
logCall(${
        JSON.stringify(callsPath)
      }, argv.some((v) => v.endsWith("/password")) ? "apply" : argv.includes("--fail") ? "confirm" : "probe");
await passThrough(argv, await readStdinBytes());`,
    );
    await repo.workflow(NAME, (flow) => {
      for (const step of flow.jobs.flatMap((j) => j.steps)) {
        const task = step.task as Task;
        if (task.behaviorType === "@swamp/ssh") {
          task.globalArgs!.sshBinary = ssh;
          task.inputs!.argv = (task.inputs!.argv as string[]).map((s) => s.replace(":3000/", `:${port}/`));
        }
      }
    });
    await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
    const quote = (s: string) => JSON.stringify(s);
    for (const action of ["probe", "apply", "confirm"]) {
      let config = `user = ${quote("admin:" + (action === "apply" ? active : pending))}\n`;
      if (action === "apply") {
        config += 'request = PUT\nheader = "Content-Type: application/json"\ndata = ' +
          quote(JSON.stringify({ oldPassword: active, newPassword: pending, confirmNewPassword: pending })) + "\n";
      }
      await repo.cli(["vault", "put", "orbit-control-inputs", `metrics-credentials-operation-12-${action}`], {
        input: config,
      });
    }
    const inputs = {
      operation_id: 12,
      node_id: 9,
      kind: "metrics.credentials_reset",
      intent: {
        ssh_execution: {
          identity_file: "/approved",
          target: {
            host: "127.0.0.1",
            user: "orbit",
            port: 22,
            host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"],
          },
        },
        address: "127.0.0.1",
        pending_fingerprint: await sha256(pending),
      },
      execution_secrets: Object.fromEntries(
        ["probe", "apply", "confirm"].map((
          action,
        ) => [action, { vault: "orbit-control-inputs", key: `metrics-credentials-operation-12-${action}` }]),
      ),
    };
    const run = async (success = true) => {
      await repo.cli(["workflow", "validate", NAME]);
      return await repo.runWorkflow(NAME, inputs, { success });
    };
    return {
      repo,
      state,
      active,
      pending,
      run,
      calls: () => readCalls<string>(callsPath),
      async [Symbol.asyncDispose]() {
        await server.shutdown();
        await repo[Symbol.asyncDispose]();
      },
    };
  } catch (error) {
    await server.shutdown();
    await repo[Symbol.asyncDispose]();
    throw error;
  }
}
Deno.test("credential reset changes and verifies without public secret bytes", async () => {
  await using f = await fixture();
  assertEquals((await f.run()).run.status, "succeeded");
  assertEquals(f.state.current, f.pending);
  assertEquals(f.state.applied, 1);
  assertEquals(f.calls(), ["probe", "apply", "confirm"]);
});
Deno.test("already applied credentials skip the password write", async () => {
  await using f = await fixture();
  f.state.current = f.pending;
  assertEquals((await f.run()).run.status, "succeeded");
  assertEquals(f.state.applied, 0);
  assertEquals(f.calls(), ["probe", "confirm"]);
});
Deno.test("refused credential write has no confirmation or false success", async () => {
  await using f = await fixture();
  f.state.failApply = true;
  assertEquals((await f.run(false)).run.status, "failed");
  assertEquals(f.state.current, f.active);
  assertEquals(f.calls(), ["probe", "apply"]);
});
Deno.test("fresh reset after uncertain confirmation observes the same pending password", async () => {
  await using f = await fixture();
  f.state.failConfirm = true;
  assertEquals((await f.run(false)).run.status, "failed");
  assertEquals(f.state.applied, 1);
  f.state.failConfirm = false;
  assertEquals((await f.run()).run.status, "succeeded");
  assertEquals(f.state.applied, 1);
});
Deno.test("native credential assertion rejects an unexpected success status", async () => {
  await using f = await fixture();
  f.state.current = f.pending;
  f.state.successStatus = 204;
  assertEquals((await f.run(false)).run.status, "failed");
  assertEquals(f.state.applied, 0);
});
