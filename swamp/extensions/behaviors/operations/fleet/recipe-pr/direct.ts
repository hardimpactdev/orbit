import { z } from "npm:zod@4.4.3";
import { RecipeIntent, type RecipePlan, RecipePrPlanner } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { effect } from "./effects.ts";
import { Repository, requirePolicy } from "./policy.ts";
const Globals = z.object({
  gatewayUrl: z.string().url().default("https://gateway.orbit"),
  caFile: z.string().min(1).default("/usr/local/share/ca-certificates/orbit-root.crt"),
});
const Args = z.object({
  operation_id: z.number().int().positive(),
  node_id: z.literal(209),
  kind: z.literal("fleet.recipe_pr"),
  run_id: z.uuid(),
  intent: RecipeIntent,
  plan: z.record(z.string(), z.unknown()).optional(),
  tree: z.string().optional(),
  commit: z.string().optional(),
  url: z.string().optional(),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
/** The mint-only primitive needs private in-memory response handling, not persisted HTTP data. */
async function mint(ctx: Context, args: z.infer<typeof Args>) {
  const client = Deno.createHttpClient({
    caCerts: [await Deno.readTextFile(ctx.globalArgs.caFile)],
  });
  try {
    const response = await fetch(
      new URL(
        `/api/v1/direct-operations/${args.operation_id}/github-token`,
        ctx.globalArgs.gatewayUrl,
      ),
      {
        method: "POST",
        redirect: "error",
        client,
        signal: ctx.signal,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ run_id: args.run_id }),
      },
    );
    if (!response.ok) {
      await response.body?.cancel();
      throw new Error("fleet.recipe_pr_credential_unavailable");
    }
    return await response.json();
  } finally {
    client.close();
  }
}
export function recipePr(
  requestMint = mint,
  makeApi = (token: string) => new RecipeGitHub(token),
  makePlanner: (api: RecipeGitHub) => Pick<RecipePrPlanner, "prepare"> = (
    api: RecipeGitHub,
  ) => new RecipePrPlanner(api),
  revoke: typeof fetch = fetch,
) {
  return {
    type: "@nckrtl/fleet-recipe-pr",
    version: "2026.11.01.1",
    globalArguments: Globals,
    resources: {
      result: {
        schema: z.record(z.string(), z.unknown()),
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: Object.fromEntries(
      [
        "prepare",
        "branch",
        "file",
        "pull",
        "tree",
        "commit",
        "ref",
        "close",
        "merge",
        "comment",
        "result",
      ].map((method) => [method, {
        description: "Recipe PR " + method,
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          let output: Record<string, unknown>;
          if (method === "result") {
            const plan = args.plan!;
            output = {
              stdout: JSON.stringify({
                completed: true,
                outcome: plan.outcome ?? "checked",
                ...(args.url || plan.url ? { url: args.url || plan.url } : {}),
              }),
            };
          } else {
            let token: string | undefined;
            try {
              const minted = await requestMint(ctx, args);
              const raw = z.object({
                data: z.object({
                  github: z.object({
                    token: z.string().min(1),
                    expires_at: z.string(),
                    repository: z.string(),
                    permissions: z.record(
                      z.string(),
                      z.string(),
                    ),
                  }),
                }),
              }).parse(minted).data.github;
              token = raw.token;
              requirePolicy(
                raw.repository === Repository &&
                  JSON.stringify(
                      Object.entries(raw.permissions)
                        .sort(),
                    ) ===
                    JSON.stringify(
                      Object.entries({
                        contents: "write",
                        pull_requests: "write",
                        checks: "read",
                      }).sort(),
                    ) &&
                  Date.parse(raw.expires_at) > Date.now() &&
                  Date.parse(raw.expires_at) <=
                    Date.now() + 3605000,
              );
              const api = makeApi(token);
              output = method === "prepare"
                ? await makePlanner(api).prepare(args.intent)
                : await effect(
                  method,
                  api,
                  args.plan as RecipePlan,
                  args.tree,
                  args.commit,
                );
              requirePolicy(
                !JSON.stringify(output).includes(token),
              );
            } catch (error) {
              const code = error instanceof Error &&
                  /^fleet\.recipe_(?:pr_(?:stale|policy_refused|credential_unavailable)|pending_deployment|github_unavailable|github_truncated)$/
                    .test(error.message)
                ? error.message
                : "fleet.recipe_pr_policy_refused";
              throw new Error(code);
            } finally {
              if (token) {
                try {
                  const response = await revoke(
                    "https://api.github.com/installation/token",
                    {
                      method: "DELETE",
                      redirect: "error",
                      headers: {
                        Authorization: "Bearer " +
                          token,
                      },
                    },
                  );
                  await response.body?.cancel();
                } catch {
                  /* Short-lived credential; no action retry. */
                }
              }
            }
          }
          return {
            dataHandles: [
              await ctx.writeResource("result", method, output),
            ],
          };
        },
      }]),
    ),
  };
}
export const behavior = recipePr();
