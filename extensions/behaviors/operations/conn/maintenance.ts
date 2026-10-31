import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";

/** Admission is periodic so a new registration cannot be lost behind an active cleanup. */
export async function maintainConn(http: Http, _tick: string) {
  const response = await http("GET", "/api/v1/conn/environments");
  if (
    response.status === 403 &&
    z.object({ error: z.object({ code: z.literal("extension.disabled") }) })
      .safeParse(response.body).success
  ) return { admitted: [], busy: 0 };
  if (response.status !== 200) throw new Error("conn.maintenance_read_failed");
  const environments = z.object({
    data: z.array(
      z.object({
        environment_id: z.string().max(255),
        status: z.string(),
        cleanup_pending: z.boolean().optional(),
        cleanup_version: z.number().int().nonnegative(),
      }),
    ).max(4096),
  }).parse(response.body).data;
  const admitted: number[] = [];
  let busy = 0;
  for (const environment of environments) {
    if (!environment.cleanup_pending || environment.status !== "registered") {
      continue;
    }
    const digest = await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(
        JSON.stringify([
          environment.environment_id,
          environment.cleanup_version,
        ]),
      ),
    );
    const key = "conn.cleanup:" +
      Array.from(
        new Uint8Array(digest),
        (byte) => byte.toString(16).padStart(2, "0"),
      ).join("");
    const result = await http(
      "POST",
      `/api/v1/conn/environments/${
        encodeURIComponent(environment.environment_id)
      }/sessions/cleanup`,
      {},
      key,
    );
    if (result.status === 204) continue;
    if (
      result.status === 409 &&
      z.object({
        error: z.object({ code: z.literal("operation.target_busy") }),
      }).safeParse(result.body).success
    ) {
      busy++;
      continue;
    }
    if (result.status !== 202) throw new Error("conn.cleanup_admission_failed");
    admitted.push(
      z.object({
        data: z.object({
          id: z.number().int().positive(),
          kind: z.literal("conn.gateway_sessions_revoke"),
        }),
      }).parse(result.body).data.id,
    );
  }
  return { admitted, busy };
}
