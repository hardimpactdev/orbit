import { agentReply, type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
const path = "update-native.json";
const read = () => JSON.parse(Deno.readTextFileSync(path));
const save = (state: unknown) =>
  Deno.writeTextFileSync(path, JSON.stringify(state));
export async function http(method: string, _path: string, body?: unknown) {
  const state = read();
  if (method === "POST") {
    const report = body as { status: string; result?: unknown };
    state.operation.status = report.status;
    state.operation.result = report.result ?? null;
    if (state.loseProjection && report.status === "succeeded") {
      state.loseProjection = false;
      save(state);
      throw new Error("lost committed projection reply");
    }
  }
  save(state);
  return {
    status: 200,
    body: {
      data: {
        ...state.operation,
        input: state.operation.status === "queued" ? {} : state.operation.input,
      },
    },
  };
}
export async function invoke(call: NodeCall) {
  const state = read();
  state.calls.push({ name: call.name, image: call.input.image ?? null });
  save(state);
  if (state.devAgent) {
    // Disposable Docker integration only: never changes the installed agent.
    const input = structuredClone(call.input);
    if (state.refuseHealth && input.health) {
      input.health = {
        url: "http://127.0.0.1:1/",
        attempts: 1,
        interval_seconds: 0,
      };
    }
    const child = new Deno.Command("/usr/bin/sudo", {
      args: ["-n", "--", state.devAgent, "command", call.name],
      stdin: "piped",
      stdout: "piped",
      stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(
      JSON.stringify({
        agent_sha256: call.pin,
        deadline_ms: call.deadlineMs,
        input,
      }) + "\n",
    ));
    await writer.close();
    const result = await child.output();
    return agentReply(result.stdout, result.success);
  }
  if (state.liveHealth) {
    if (call.name !== "http.await-healthy") {
      throw new Error("Canary is health-only");
    }
    return await runNodeAgent(call);
  }
  if (state.refuseHealth && state.calls.length === 1) {
    return { error_code: "process.health_failed", restored: true };
  }
  return {};
}
