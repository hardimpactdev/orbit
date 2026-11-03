import { taskRemote } from "./task_remote.ts";

export function piFixture(token: string, workspace?: string) {
  const state = {
    calls: [] as [string, Record<string, unknown> | null][], disconnect: false, redirect: false,
    malformed: "", usage: { total: 4 } as Record<string, unknown>, role: "implementer", agentText: "agent changed",
    omitReceipt: false, thread: 14,
  };
  const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen() {} }, async (request) => {
    const path = new URL(request.url).pathname;
    if (request.method === "POST") {
      const body = await request.json();
      state.calls.push([path, body]);
      if (workspace && path.endsWith("/messages")) {
        await Deno.writeTextFile(`${workspace}/file`, state.agentText + "\n");
        if (!state.omitReceipt) {
          await Deno.writeTextFile(`${workspace}/.git/orbit/receipt.json`, JSON.stringify({
            thread: state.thread, outcome: state.role === "reviewer" ? "approved" : "ready_for_review", summary: state.agentText,
          }));
        }
      }
      if (state.redirect) return new Response(null, { status: 302, headers: { Location: "/credential-destination" } });
      if (request.headers.get("Authorization") !== `Bearer ${token}`) return new Response(null, { status: 401 });
      return Response.json({ accepted: true });
    }
    if (request.headers.get("Authorization") !== `Bearer ${token}`) return new Response(null, { status: 401 });
    state.calls.push([path, null]);
    const lines = [JSON.stringify({ state: "working", turnId: "older" })];
    if (state.malformed) lines.push(state.malformed);
    if (!state.disconnect) lines.push(JSON.stringify({ state: "done", turnId: "operation-15", usage: state.usage }));
    return new Response(lines.join("\n") + "\n");
  });
  return { state, port: server.addr.port, url: `http://127.0.0.1:${server.addr.port}`, [Symbol.asyncDispose]: () => server.shutdown() };
}

export async function nativeTurn() {
  const test = await taskRemote();
  const { repo, workspace, git, inputs } = test;
  await repo.copyBehaviors("operations/task-turn");
  await Deno.copyFile(
    new URL("../../extensions/behaviors/operations/tasks/turn.ts", import.meta.url),
    `${repo.dir}/extensions/behaviors/operations/tasks/turn.ts`,
  );
  await Deno.writeTextFile(`${workspace}/file`, "original\n");
  await Deno.writeTextFile(`${workspace}/.gitignore`, "ignored-tracked\n");
  await Deno.writeTextFile(`${workspace}/ignored-tracked`, "tracked despite ignore\n");
  await git("add", "-f", "ignored-tracked");
  await git("add", ".");
  await git("commit", "-qm", "fixture");
  const head = await git("rev-parse", "HEAD");
  const token = ["synthetic-pi-test-token-", "with-at-least-32-characters"].join("");
  repo.secrets.push(token);
  const pi = piFixture(token, workspace);
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await repo.cli(["vault", "put", "orbit-control-inputs", "task-vm-pi-token-1"], { input: token });
  Object.assign(inputs, {
    operation_id: 15, node_id: 30,
    execution_secrets: { pi_token: { vault: "orbit-control-inputs", key: "task-vm-pi-token-1" } },
  });
  Object.assign(inputs.intent, {
    operation_id: 15, new_session: true, group_id: 10, instance_id: 12, node_id: 30, subtask: 11, thread_id: 14,
    session_id: "session-14", role: "implementer", candidate: null, deliverables: [], analysis_evidence: null,
    model: "orbit-task-vm/gpt-6.1-sol", effort: "high", prompt: "Complete the task $(touch never).",
    pi_host: "127.0.0.1", pi_port: pi.port,
  });
  await test.workflow("turn");
  return {
    test, repo, workspace, git, inputs, head, pi: pi.state,
    [Symbol.asyncDispose]: async () => { await pi[Symbol.asyncDispose](); await test[Symbol.asyncDispose](); },
  };
}
