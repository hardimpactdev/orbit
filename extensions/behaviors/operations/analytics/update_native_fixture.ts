import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { agentReply, type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
const path = "update-native.json";
const read = () => JSON.parse(Deno.readTextFileSync(path));
const save = (state: unknown) =>
  Deno.writeTextFileSync(path, JSON.stringify(state));
// Exercise the actual production HTTP client; the native test supplies a loopback TLS Gateway.
const endpoint = read();
export const http = gatewayHttp(endpoint.gatewayUrl, endpoint.caFile);
export async function invoke(call: NodeCall) {
  const state = read();
  state.calls.push({ name: call.name, image: call.input.image ?? null });
  save(state);
  if (state.throwPrivate) {
    throw new Error(
      String(
        (call.input.environment as Record<string, string>).SECRET_KEY_BASE,
      ),
    );
  }
  if (state.privatePayload) {
    return {
      ignored:
        (call.input.environment as Record<string, string>).SECRET_KEY_BASE,
    };
  }
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
