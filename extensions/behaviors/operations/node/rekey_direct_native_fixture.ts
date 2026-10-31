import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { agentRekeyAction } from "./rekey_local.ts";

// Only the agent transport is synthetic. Admission, private storage and projection
// use the production HTTP client against the isolated test server.
const endpoint = Deno.env.get("ORBIT_REKEY_HTTP_FIXTURE")!;
export const fixtureHttp = gatewayHttp(
  endpoint,
  "/etc/ssl/certs/ca-certificates.crt",
);
export const fixtureAction = agentRekeyAction(async (call) => {
  if (
    call.name !== "agent.configure" || call.input.secret_hash !== null ||
    call.input.worker_credentials !== null ||
    call.input.allow_secret_rotation !== true || !call.sudo
  ) throw new Error("Unexpected recovery transport input");
  const response = await fetch(`${endpoint}/_agent`);
  return await response.json();
});
