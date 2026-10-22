// Stopgap extension of @swamp/agent-runner: run Codex through an
// OpenAI-compatible model provider (CLIProxyAPI / AI Router).
// The built-in codex provider passes --ignore-user-config and needs
// CODEX_API_KEY, so it always talks to api.openai.com (swamp-club lab #3769).
// This keeps its hermetic flags and adds only the routing as -c overrides.
// Remove it once agent-runner accepts a model provider for codex.
import { z } from "npm:zod@4";

import { AuthSchema, codexArgs } from "./_lib/codex_args.ts";

export const extension = {
  type: "@swamp/agent-runner",
  methods: [{
    run_codex: {
      description: "Run a Codex prompt in a directory through a custom model provider; writes runResult",
      arguments: z.object({
        key: z.string().describe("Idempotency key; names the runResult instance"),
        prompt: z.string(),
        cwd: z.string().describe("Directory Codex works in (-C)"),
        model: z.string().default("gpt-6.1-sol"),
        effort: z.enum(["low", "medium", "high", "xhigh", "max"]).optional(),
        readOnly: z.boolean().default(false),
        providerId: z.string().default("cliproxyapi"),
        baseUrl: z.string(),
        wireApi: z.string().default("responses"),
        auth: z.string().describe("JSON {command, args} of the provider's token helper; pass via vault.get").meta({ sensitive: true }),
      }),
      execute: async (
        args: {
          key: string; prompt: string; cwd: string; model: string; effort?: string; readOnly: boolean;
          providerId: string; baseUrl: string; wireApi: string; auth: string;
        },
        ctx: {
          writeResource: (s: string, n: string, d: Record<string, unknown>, o?: { tags?: Record<string, string> }) => Promise<{ name: string }>;
          logger: { info: (m: string, p?: Record<string, unknown>) => void };
          signal?: AbortSignal;
        },
      ) => {
        const outputPath = await Deno.makeTempFile({ prefix: "codex-last-" });
        const argv = codexArgs({
          ...args, sandbox: args.readOnly ? "read-only" : "workspace-write", outputPath, auth: AuthSchema.parse(JSON.parse(args.auth)),
        });
        const env: Record<string, string> = { TERM: "dumb" };
        for (const k of ["PATH", "HOME", "USER", "LANG", "TMPDIR"]) {
          const v = Deno.env.get(k);
          if (v !== undefined) env[k] = v;
        }
        ctx.logger.info("codex {model} in {cwd} via {provider}", { model: args.model, cwd: args.cwd, provider: args.providerId });
        const out = await new Deno.Command("codex", {
          args: argv, clearEnv: true, env, stdin: "null", stdout: "piped", stderr: "piped", signal: ctx.signal,
        }).output();
        let output = "";
        try {
          output = await Deno.readTextFile(outputPath);
        } finally {
          await Deno.remove(outputPath).catch(() => {});
        }
        const h = await ctx.writeResource("runResult", `run-${args.key}`, { output, exitCode: out.code }, {
          tags: { provider: "codex", model: args.model, exitCode: String(out.code) },
        });
        return { dataHandles: [h] };
      },
    },
  }],
};
