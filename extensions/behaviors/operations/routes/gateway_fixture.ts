/** Runs the real family workflows against a PHP test's in-memory Gateway through JSON lines. */
import { routes } from "./mod.ts";
import { publication } from "./publication.ts";
import { certificates } from "../certificates/mod.ts";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Context } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const decoder = new TextDecoder();
let pending = "";
function read(): unknown {
  const buffer = new Uint8Array(65536);
  while (true) {
    const end = pending.indexOf("\n");
    if (end !== -1) {
      const line = pending.slice(0, end);
      pending = pending.slice(end + 1);
      return JSON.parse(line);
    }
    const count = Deno.stdin.readSync(buffer);
    if (count === null) throw new Error("Gateway fixture input closed");
    pending += decoder.decode(buffer.subarray(0, count), { stream: true });
  }
}
function send(value: unknown) {
  Deno.stdout.writeSync(new TextEncoder().encode(JSON.stringify(value) + "\n"));
}
const resources = new Map<string, unknown>();
const http: Http = (method, path, body) => {
  send({ type: "request", method, path, body });
  return Promise.resolve(read() as Awaited<ReturnType<Http>>);
};
const { context } = createModelTestContext({
  globalArgs: { gatewayUrl: "https://fixture.invalid", caFile: "/unused" },
});
const ctx = Object.assign(context, {
  globalArgs: { gatewayUrl: "https://fixture.invalid", caFile: "/unused" },
  readResource: (name: string) => Promise.resolve(resources.get(name) ?? null),
  writeResource: (_spec: string, name: string, value: unknown) => {
    resources.set(name, value);
    return Promise.resolve({ name });
  },
}) as Context;
while (true) {
  const args = read() as {
    kind: string;
    worker: string;
    foreign?: boolean;
    direct?: boolean;
    operation_id: number;
    node_id: number;
    run_id: string;
  };
  try {
    let result: unknown;
    if (
      args.direct === true
    ) {
      try {
        result = await publication(() => http).methods.publish.execute(
          args as Parameters<
            ReturnType<typeof publication>["methods"]["publish"]["execute"]
          >[0],
          ctx,
        );
      } catch (error) {
        const response = await http(
          "GET",
          `/api/v1/route-publications/${args.operation_id}?run_id=${args.run_id}`,
        );
        const op = (response.body as { data: { status: string } }).data;
        if (!["failed", "cancelled"].includes(op.status)) throw error;
        result = { status: op.status };
      }
    } else if (args.foreign) {
      const response = await http(
        "POST",
        "/api/v1/operation-executor/claim",
        args,
      );
      const op = (response.body as { data: { id: number } }).data;
      const path = `/api/v1/operation-executor/${op.id}`;
      const run = await http("POST", `${path}/run`, {
        worker: args.worker,
        key: "fixture-proof",
        transport: "gateway",
        name: "route-fixture.prove-child",
        input: { proof: "ready" },
      });
      if (run.status !== 200) throw new Error(JSON.stringify(run.body));
      const receipt = (run.body as { data: Record<string, unknown> }).data;
      const failed = typeof receipt.error_code === "string";
      await http("POST", `${path}/step`, {
        worker: args.worker,
        key: "fixture-proof-finish",
        name: "fixture-proof",
        status: failed ? "failed" : "succeeded",
        result: receipt,
      });
      const completion = await http("POST", `${path}/complete`, {
        worker: args.worker,
        status: failed ? "failed" : "succeeded",
        result: receipt,
        ...(failed ? { error_code: receipt.error_code } : {}),
      });
      if (completion.status !== 200) {
        throw new Error(JSON.stringify(completion.body));
      }
      result = { status: failed ? "failed" : "succeeded" };
    } else {
      result = await (args.kind.startsWith("certificate.")
        ? certificates(() =>
          http
        )
        : routes(() => http)).methods.execute.execute(args, ctx);
    }
    send({ type: "done", result });
  } catch (error) {
    send({ type: "error", message: String(error) });
  }
}
