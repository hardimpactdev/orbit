import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { localAgent, type LocalCommand } from "./agent.ts";
import { processControl } from "./process-control.ts";
import { scheduleControl } from "./control.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

Deno.test("local Process and Schedule preflight refuses a different admitted build without sudo", async () => {
  for (
    const command of ["process.remove", "schedule.remove"] as LocalCommand[]
  ) {
    assertEquals(await localAgent(command, {}, "0".repeat(64)), {
      error_code: "operation.agent_invalid",
    });
  }
});

Deno.test("a failed build preflight projects the native refusal before any Process or Schedule command", async () => {
  for (const kind of ["process.destroy", "schedule.destroy"] as const) {
    const args = {
      operation_id: 17,
      node_id: 9,
      kind,
      run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
    };
    const input = kind === "process.destroy"
      ? {
        process_id: 7,
        agent_sha256: "0".repeat(64),
        runtime: "systemd",
        name: "orbit-process-7-proof.service",
        on_demand: false,
        vite: null,
      }
      : {
        id: "9a067a27-c2ce-45c5-83f5-735e03dc5b2f",
        agent_sha256: "0".repeat(64),
        group: "orbit",
        cascade: false,
      };
    const posts: Record<string, unknown>[] = [];
    const http: Http = (method, _path, body) => {
      if (method === "POST") posts.push(body as Record<string, unknown>);
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            node_id: 9,
            kind,
            input,
            status: "queued",
            cancel_requested: false,
            result: null,
          },
        },
      });
    };
    const ctx = {
      globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "unused" },
    };
    if (kind === "process.destroy") {
      await assertRejects(
        () =>
          processControl(() => http).methods.control.execute(
            { ...args, kind },
            ctx,
          ),
        Error,
        "operation.agent_invalid",
      );
    } else {
      await assertRejects(
        () =>
          scheduleControl(() => http).methods.control.execute(
            { ...args, kind },
            ctx,
          ),
        Error,
        "operation.agent_invalid",
      );
    }
    assertEquals(posts.map((post) => post.status), ["running", "failed"]);
    assertEquals(posts[1].result, { error_code: "operation.agent_invalid" });
    assertEquals(posts[1].error_code, "operation.agent_invalid");
  }
});
