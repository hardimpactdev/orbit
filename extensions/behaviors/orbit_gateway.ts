// @nckrtl/orbit-gateway — the stage work of the orbit-groups factory.
// `stage` ensures one Gateway task operation per driver key and never waits:
// see _lib/gateway_stage.ts. The Gateway authenticates this Node by its
// WireGuard address, so the transport only needs the Orbit root CA.
import { z } from "npm:zod@4";
import { type Http, type Job, runStage, STAGES } from "./_lib/gateway_stage.ts";

const GlobalArgsSchema = z.object({
  gatewayUrl: z.string().default("https://gateway.orbit"),
  caFile: z.string().default("/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"),
  timeoutSeconds: z.number().int().default(60),
});
type GlobalArgs = z.infer<typeof GlobalArgsSchema>;

const JobSchema = z.object({
  key: z.string(),
  workItem: z.string(),
  stage: z.enum(STAGES),
  group: z.number(),
  subtask: z.number().nullable(),
  state: z.enum(["running", "succeeded", "failed"]),
  launches: z.number(),
  launchedNow: z.boolean(),
  request: z.object({ path: z.string(), body: z.record(z.string(), z.unknown()), idempotencyKey: z.string() }).nullable(),
  operationId: z.number().nullable(),
  operationStatus: z.string().nullable(),
  lastError: z.string().nullable(),
  inputIdentity: z.string().nullable(),
  nextReadAt: z.string().nullable(),
  artifacts: z.record(z.string(), z.record(z.string(), z.unknown())),
  evidence: z.record(z.string(), z.unknown()).nullable(),
});

/** HTTP through curl, which trusts the Orbit root CA without Deno's unstable client flags. */
export function curlHttp(g: GlobalArgs): Http {
  return async (method, path, body, idempotencyKey) => {
    const args = [
      "-sS", "--max-time", String(g.timeoutSeconds), "--cacert", g.caFile, "-X", method,
      "-H", "Accept: application/json", "-w", "\n%{http_code}",
    ];
    if (idempotencyKey) args.push("-H", `Idempotency-Key: ${idempotencyKey}`);
    if (body !== undefined) args.push("-H", "Content-Type: application/json", "--data-binary", "@-");
    args.push(`${g.gatewayUrl}${path}`);
    const child = new Deno.Command("curl", { args, stdin: body !== undefined ? "piped" : "null", stdout: "piped", stderr: "piped" }).spawn();
    if (body !== undefined) {
      const w = child.stdin.getWriter();
      await w.write(new TextEncoder().encode(JSON.stringify(body)));
      await w.close();
    }
    const out = await child.output();
    const text = new TextDecoder().decode(out.stdout);
    const cut = text.lastIndexOf("\n");
    const status = Number(text.slice(cut + 1)) || 0;
    if (!out.success || status === 0) {
      return { status: 0, body: { error: { code: "transport", message: new TextDecoder().decode(out.stderr).trim() } } };
    }
    let parsed: Record<string, unknown> | null = null;
    try {
      parsed = JSON.parse(text.slice(0, cut)) as Record<string, unknown>;
    } catch { /* empty or non-JSON body */ }
    return { status, body: parsed };
  };
}

export const behavior = {
  type: "@nckrtl/orbit-gateway",
  version: "2026.10.23.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    job: {
      description: "One Gateway task operation per driver key, with its stage products",
      schema: JobSchema,
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    stage: {
      description: "Ensure the Gateway operation of one stage entry: start it once, then report its result",
      arguments: z.object({
        workItem: z.string().describe("tg-<task group id>"),
        stage: z.enum(STAGES),
        key: z.string().describe("<workItem>.<stage>.c<cycle>"),
        change: z.unknown().optional(),
        handoff: z.unknown().optional(),
        review: z.unknown().optional(),
      }),
      execute: async (
        args: { workItem: string; stage: (typeof STAGES)[number]; key: string; change?: unknown; handoff?: unknown; review?: unknown },
        context: {
          globalArgs: GlobalArgs;
          definition: { name: string };
          queryData: (predicate: string, select?: string) => Promise<unknown[]>;
          writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
          logger: { info: (msg: string, props?: Record<string, unknown>) => void };
        },
      ) => {
        let handle: { name: string } | null = null;
        const job = await runStage(args, {
          http: curlHttp(context.globalArgs),
          readJob: async () => {
            const rows = (await context.queryData(
              `modelName == "${context.definition.name}" && name == "job-${args.key}"`,
              "attributes",
            )) as Job[];
            return rows[0] ?? null;
          },
          writeJob: async (j) => {
            handle = await context.writeResource("job", `job-${args.key}`, j as unknown as Record<string, unknown>);
          },
        });
        context.logger.info("{key}: {state} op={op} {status} {err}", {
          key: args.key,
          state: job.state,
          op: job.operationId ?? "-",
          status: job.operationStatus ?? "",
          err: job.lastError ?? "",
        });
        return { dataHandles: handle ? [handle] : [] };
      },
    },
  },
};
