import { gatewayHttp } from "../automatic-deploy/gateway.ts";
import type { NativeReadCall } from "./doctor_native.ts";
const endpoint = Deno.env.get("ORBIT_DOCTOR_HTTP_FIXTURE")!;
export const fixtureHttp = gatewayHttp(
  endpoint,
  "/etc/ssl/certs/ca-certificates.crt",
);
/** Only local host reads are synthetic. Gateway binding and report callbacks use real HTTP. */
export async function fixtureObservation(call: NativeReadCall) {
  const response = await fetch(endpoint + "/_observation", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ node_id: call.node.id, name: call.name }),
  });
  if (!response.ok) throw new Error("Synthetic Node read refused");
  return await response.json();
}
export const fixtureLocal = () => Promise.resolve("");
