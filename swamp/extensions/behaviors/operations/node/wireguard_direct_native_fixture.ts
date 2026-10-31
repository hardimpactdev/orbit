import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import type { NetworkSystem } from "./network_system.ts";
import type { BootstrapSsh } from "../node-bootstrap/bootstrap_ssh.ts";
const endpoint = Deno.env.get("ORBIT_WIREGUARD_HTTP_FIXTURE")!;
export const fixtureHttp = gatewayHttp(
  endpoint,
  "/etc/ssl/certs/ca-certificates.crt",
);
/** Only fixed system subprocesses are synthetic; private HTTP uses the real client. */
export const fixtureSystem: NetworkSystem = async (argv, stdin) => {
  if (argv.length !== 3 || argv[0] !== "/usr/bin/python3" || argv[1] !== "-c") {
    throw new Error("Unexpected hub system input");
  }
  const response = await fetch(endpoint + "/_system", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: stdin,
  });
  return {
    success: response.ok,
    stdout: JSON.stringify(await response.json()),
  };
};

/** The SSH boundary is synthetic; the shared native method and private HTTP are real. */
export const fixtureSsh: BootstrapSsh = async (
  target,
  identity,
  _env,
  argv,
  stdin,
) => {
  const response = await fetch(endpoint + "/_ssh", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ target, identity, argv, input: JSON.parse(stdin!) }),
  });
  if (!response.ok) throw new Error("Scratch SSH effect failed");
  return JSON.stringify(await response.json());
};
