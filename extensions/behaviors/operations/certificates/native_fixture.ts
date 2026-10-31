import type { CertificateSystemCall } from "./system.ts";

const file = "native-certificates.json";
const pem =
  "-----BEGIN CERTIFICATE-----\ncHVibGlj\n-----END CERTIFICATE-----\n";
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile(file));
  if (path.endsWith("/failure")) {
    if (
      !["succeeded", "failed", "cancelled"].includes(state.operation.status)
    ) state.operation.status = "failed";
    state.failures = (state.failures ?? 0) + 1;
    await Deno.writeTextFile("native-certificates.json", JSON.stringify(state));
    return { status: 200, body: { data: state.operation } };
  }
  const body = raw as Record<string, unknown>;
  state.calls.push(path);
  const data: unknown = state.operation;
  if (method === "POST") {
    const result = body.result as Record<string, unknown> | undefined;
    if (result?.certificate_requests) {
      state.requests = result.certificate_requests;
    }
    state.operation.status = body.status;
    if (
      body.status === "succeeded" || body.status === "failed" ||
      body.status === "cancelled"
    ) {
      state.operation.result = result;
      if (state.loseProjection) {
        state.loseProjection = false;
        await Deno.writeTextFile(file, JSON.stringify(state));
        throw new Error("Lost projection response");
      }
    }
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return { status: 200, body: { data } };
}
export async function fixtureAgent(call: CertificateSystemCall) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.commands.push(call.name);
  let result: Record<string, unknown> = {};
  if (call.name === "certificate.request-leaf") {
    state.keys ??= 1;
    result = state.current ? { state: "current" } : {
      state: "request",
      request:
        "-----BEGIN CERTIFICATE REQUEST-----\ncHVibGlj\n-----END CERTIFICATE REQUEST-----\n",
    };
  } else if (call.name === "certificate.publish-leaf") {
    if (call.input.certificate !== pem) {
      throw new Error("Wrong public signature");
    }
    state.present = true;
  } else if (call.name === "certificate.remove-leaf") state.present = false;
  else if (call.name === "certificate.inspect-leaf") {
    result = { present: state.present };
  }
  if (state.loseCommand === call.name) {
    state.loseCommand = null;
    await Deno.writeTextFile(file, JSON.stringify(state));
    throw new Error("Lost local response");
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return result;
}

export async function fixtureSign(_leaves: unknown, rawRequests: unknown) {
  const requests = rawRequests as {
    node_id: number;
    scope: string;
    state: string;
  }[];
  const state = JSON.parse(await Deno.readTextFile(file));
  state.signs++;
  await Deno.writeTextFile(file, JSON.stringify(state));
  return {
    certificates: requests.filter((r) => r.state === "request").map((r) => ({
      node_id: r.node_id,
      scope: r.scope,
      certificate_base64: btoa(pem),
      root_certificate_base64: btoa(pem),
    })),
  };
}
