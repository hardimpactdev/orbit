import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
const endpoint = Deno.env.get("ORBIT_REMOVAL_HTTP_FIXTURE")!;
export const fixtureHttp = gatewayHttp(
  endpoint,
  "/etc/ssl/certs/ca-certificates.crt",
);

export const fixtureMetrics = async (
  phase: string,
  node: { node_id: number },
  _metrics: string,
  before?: unknown,
) => {
  await fetch(endpoint + "/_metrics?phase=" + phase + "&node=" + node.node_id);
  if (phase === "restore" && !before) throw new Error("Snapshot missing");
  return phase === "snapshot"
    ? { node_exporter: {}, cadvisor: {}, service: { config: null }, pools: [] }
    : {};
};
export const fixtureMetricsRuntime = {
  credential: async () => {
    await fetch(endpoint + "/_metrics?phase=credentials");
    return { verified: true };
  },
  runtime: async () => {
    await fetch(endpoint + "/_metrics?phase=runtime");
    return { changed: true };
  },
};
