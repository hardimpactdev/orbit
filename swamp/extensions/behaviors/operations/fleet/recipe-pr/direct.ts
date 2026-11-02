import { z } from "npm:zod@4.4.3";
import { RecipeIntent, type RecipePlan, RecipePrPlanner } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { effect } from "./effects.ts";
import { requirePolicy } from "./policy.ts";
const Args = z.object({
  operation_id: z.number().int().positive(),
  node_id: z.literal(209),
  kind: z.literal("fleet.recipe_pr"),
  intent: RecipeIntent,
  plan: z.record(z.string(), z.unknown()).optional(),
  url: z.string().optional(),
  // The Gateway minted this scoped token at run start; the step reads it from the vault.
  env: z.object({ GITHUB_TOKEN: z.string().min(1) }).strict().optional(),
});
type Context = {
  writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
/** One chart action: each GitHub write the action needs, in order. Writes carry their own expected head. */
const Actions: Record<string, string[]> = {
  open: ["branch", "file", "pull"],
  update: ["tree", "commit", "ref", "comment"],
  merge: ["merge"],
  retire: ["close", "comment"],
};
export function recipePr(
  makeApi = (token: string) => new RecipeGitHub(token),
  makePlanner: (api: RecipeGitHub) => Pick<RecipePrPlanner, "prepare"> = (
    api: RecipeGitHub,
  ) => new RecipePrPlanner(api),
) {
  async function act(method: string, args: z.infer<typeof Args>) {
    const token = args.env?.GITHUB_TOKEN;
    requirePolicy(token !== undefined);
    const api = makeApi(token!);
    let output: Record<string, unknown> = { completed: true };
    try {
      if (method === "prepare") output = await makePlanner(api).prepare(args.intent);
      else {
        const plan = args.plan as RecipePlan;
        let tree: string | undefined, commit: string | undefined;
        for (const write of Actions[method]) {
          if (write === "comment") {
            // The explanation comment is best effort, as before; the action itself already landed.
            if (plan.comment !== undefined) await effect(write, api, plan).catch(() => undefined);
            continue;
          }
          const result = await effect(write, api, plan, tree, commit);
          if (write === "tree") tree = z.string().parse(result.sha);
          if (write === "commit") commit = z.string().parse(result.sha);
          if (write === "pull") output = result;
        }
      }
      requirePolicy(!JSON.stringify(output).includes(token!));
    } catch (error) {
      throw new Error(
        error instanceof Error &&
            /^fleet\.recipe_(?:pr_(?:stale|policy_refused)|pending_deployment|github_unavailable|github_truncated)$/
              .test(error.message)
          ? error.message
          : "fleet.recipe_pr_policy_refused",
      );
    }
    return output;
  }
  return {
    type: "@nckrtl/fleet-recipe-pr",
    version: "2026.11.02.1",
    resources: {
      result: {
        schema: z.record(z.string(), z.unknown()),
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: Object.fromEntries(
      ["prepare", "open", "update", "merge", "retire", "result"].map((method) => [method, {
        description: "Recipe PR " + method,
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const output = method === "result"
            ? {
              stdout: JSON.stringify({
                completed: true,
                outcome: args.plan!.outcome ?? "checked",
                ...(args.url || args.plan!.url ? { url: args.url || args.plan!.url } : {}),
              }),
            }
            : await act(method, args);
          return { dataHandles: [await ctx.writeResource("result", method, output)] };
        },
      }]),
    ),
  };
}
export const behavior = recipePr();
