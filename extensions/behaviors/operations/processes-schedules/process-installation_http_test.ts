import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { directOperation, GatewayOperation } from "../_lib/direct.ts";
import { installProcess, ProcessInstallation } from "./process-installation.ts";

Deno.test("real Gateway HTTP keeps Process private material out of native inputs and projections, including errors", async () => {
  const args = {
    operation_id: 17,
    node_id: 9,
    kind: "process.create",
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  };
  const content =
    "# Orbit Process 7\n[Service]\nEnvironment=PASSWORD=private-loopback-canary\n";
  const content_hash = Array.from(
    new Uint8Array(
      await crypto.subtle.digest(
        "SHA-256",
        new TextEncoder().encode(content),
      ),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
  const input = {
    process_id: 7,
    runtime: "systemd" as const,
    name: "orbit-process-7-proof.service",
    desired: "stopped" as const,
    on_demand: false,
    retired: null,
    activation_pending: false,
    agent_sha256: "a".repeat(64),
    content_hash,
  };
  const posts: unknown[] = [];
  let reads = 0;
  let privateReads = 0;
  const current = {
    id: args.operation_id,
    node_id: 9,
    kind: args.kind,
    input,
    status: "queued",
    result: null,
    cancel_requested: false,
  };
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    async (request) => {
      const url = new URL(request.url);
      assertEquals(
        url.searchParams.get("run_id") ?? (await request.clone().json()).run_id,
        args.run_id,
      );
      if (url.pathname.endsWith("private-material")) {
        privateReads++;
        return Response.json({
          data: { installation: { runtime: "systemd", content } },
        }, { headers: { "Cache-Control": "no-store" } });
      }
      if (request.method === "GET" && reads++ === 0) {
        return Response.json({ error: { code: "operation.run_unbound" } }, {
          status: 409,
        });
      }
      if (request.method === "POST") posts.push(await request.json());
      return Response.json({ data: current });
    },
  );
  try {
    const gateway = new GatewayOperation(
      gatewayHttp(
        `http://127.0.0.1:${server.addr.port}`,
        "/etc/ssl/certs/ca-certificates.crt",
      ),
      args,
      () => Promise.resolve(),
    );
    let effects = 0;
    assertEquals(
      await directOperation(args, gateway, async (safe) => {
        assertEquals(reads, 2);
        return await installProcess(
          ProcessInstallation.parse(safe),
          gateway,
          undefined,
          (name, specification) => {
            effects++;
            if (name === "process.stage-unit") {
              assertEquals(specification.content, content);
              return Promise.resolve({
                staged: true,
                previous: false,
                state: null,
              });
            }
            return Promise.resolve(
              name === "process.status"
                ? { state: "inactive", private: content }
                : { backup_left: false },
            );
          },
        );
      }, (result) => result),
      { status: "succeeded" },
    );
    assertEquals(effects, 3);
    assertEquals(privateReads, 1);
    assertEquals(
      JSON.stringify({ input, posts }).includes("private-loopback-canary"),
      false,
    );
    const projected = posts.at(-1) as Record<string, unknown>;
    assertEquals(projected.status, "succeeded");
    assertEquals(projected.result, { ready: true, state: "inactive" });
    await assertRejects(
      () =>
        installProcess(input, gateway, undefined, () => {
          throw new Error(content);
        }),
      Error,
      "Private Process installation did not complete",
    );
    assertEquals(
      JSON.stringify(posts).includes("private-loopback-canary"),
      false,
    );
  } finally {
    await server.shutdown();
  }
});
