import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { FirewallProgram } from "../routes/firewall-program.ts";
import type { NetworkSystem } from "./network_system.ts";
const endpoint = Deno.env.get("ORBIT_NETWORK_ROOT_FIXTURE")!;
export const fixtureHttp = gatewayHttp(
  endpoint,
  "/etc/ssl/certs/ca-certificates.crt",
);
export const fixtureSystem: NetworkSystem = async (argv, stdin) => {
  if (argv.length !== 3 || argv[0] !== "/usr/bin/python3" || argv[1] !== "-c") {
    throw new Error("Unexpected network system input");
  }
  const data = JSON.parse(stdin ?? "");
  const call = argv[2] === FirewallProgram
    ? { name: "firewall.rules", input: data }
    : data;
  const response = await fetch(endpoint + "/_system", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(call),
  });
  return {
    success: response.ok,
    stdout: JSON.stringify(await response.json()),
  };
};
