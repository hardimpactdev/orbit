import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { processControl } from "./process-control.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

Deno.test("production create default uses native system commands rather than the frozen agent", async () => {
  const id = 12, name = "orbit-process-12-native-first-visit.service";
  const directory = "/nonexistent-orbit-native-process-create-proof";
  const content = "# Orbit Process 12\n[Service]\nExecStart=/usr/bin/true\n";
  const content_hash = Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(content)),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  const common = {
    process_id: id,
    name,
    runtime: "systemd" as const,
    on_demand: false,
    agent_sha256: "a".repeat(64),
    directory,
  };
  const input = {
    control: { ...common, vite: null },
    installation: {
      ...common,
      desired: "running" as const,
      content_hash,
      activation_pending: false,
      retired: null,
    },
  };
  const reports: Record<string, unknown>[] = [];
  let privateReads = 0;
  const http: Http = (method, path, body) => {
    if (path.includes("/private-material")) {
      privateReads++;
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            installation: {
              runtime: "systemd",
              content,
              environment: "# Orbit Process 12\nTOKEN=private-create-proof\n",
            },
          },
        },
      });
    }
    if (method === "POST") reports.push(body as Record<string, unknown>);
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 17,
          node_id: 9,
          kind: "process.update",
          status: "queued",
          input,
          cancel_requested: false,
          result: null,
        },
      },
    });
  };
  // Only the HTTP boundary is replaced. All Process action defaults are production code.
  const behavior = processControl(() => http);
  await assertRejects(
    () =>
      behavior.methods.installCreate.execute({
        operation_id: 17,
        node_id: 9,
        kind: "process.update",
        run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
        preparation: { vite: false, proceed: true },
        publication_status: "skipped",
      }, {
        globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/unused" },
      }),
    Error,
    "process.release_unavailable",
  );
  assertEquals(privateReads, 1);
  assertEquals(reports.at(-1)!.result, {
    error_code: "process.release_unavailable",
  });
  assertEquals(JSON.stringify(reports).includes("private-create-proof"), false);
});
