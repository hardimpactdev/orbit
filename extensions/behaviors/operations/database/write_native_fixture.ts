import { gatewayHttp } from "../../automatic-deploy/gateway.ts";

export function fixtureHttp() {
  return gatewayHttp(
    Deno.env.get("ORBIT_WRITE_FIXTURE_URL")!,
    Deno.env.get("ORBIT_WRITE_FIXTURE_CA")!,
  );
}

/** Test-only SQL boundary; real private HTTP transport is unchanged. */
export async function fixturePdo(material: Record<string, unknown>) {
  const http = gatewayHttp(
    Deno.env.get("ORBIT_WRITE_FIXTURE_URL")!,
    Deno.env.get("ORBIT_WRITE_FIXTURE_CA")!,
  );
  const result = await http("POST", "/fixture-effect", material);
  if (result.status !== 200) throw new Error(JSON.stringify(result.body));
  return result.body;
}
