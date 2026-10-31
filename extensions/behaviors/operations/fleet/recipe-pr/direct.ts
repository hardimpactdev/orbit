import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  type DirectIntent,
  directOperation,
  GatewayOperation,
  Globals,
} from "../../_lib/direct.ts";
import { OpenPlan, type OpenPlanStore, RecipePrAction } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { Repository } from "./policy.ts";

const Args = DirectArgs.extend({
  kind: z.literal("fleet.recipe_pr"),
  node_id: z.number().int().positive(),
});
const Result = z.union([
  z.object({
    completed: z.literal(true),
    outcome: z.enum(["checked", "absent"]),
    url: z.string().regex(
      /^https:\/\/github\.com\/hardimpactdev\/orbit\/pull\/[1-9][0-9]*$/,
    ).optional(),
  }).strict(),
  z.object({
    error_code: z.enum([
      "fleet.recipe_pr_stale",
      "fleet.recipe_pending_deployment",
      "fleet.recipe_pr_policy_refused",
      "fleet.recipe_pr_credential_unavailable",
    ]),
  }).strict(),
]);
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<unknown>;
  writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
type Gateway = Pick<GatewayOperation, "read" | "project" | "withGitHubToken">;
export function recipePr(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  makeGateway: (http: Http, args: DirectIntent) => Gateway = (http, args) =>
    new GatewayOperation(http, args),
  makeAction = (token: string, plans: OpenPlanStore) =>
    new RecipePrAction(new RecipeGitHub(token), undefined, plans),
) {
  return {
    type: "@nckrtl/fleet-recipe-pr",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      open_plan: {
        description: "The first public candidate prepared before GitHub writes",
        schema: OpenPlan,
        lifetime: "2d",
        garbageCollection: 1,
      },
    },
    methods: {
      execute: {
        description:
          "Maintain one admitted recipe PR directly through a scoped GitHub credential",
        arguments: Args,
        execute: (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = makeGateway(makeHttp(ctx), args);
          return directOperation(
            args,
            gateway,
            async (input) => {
              try {
                return await gateway.withGitHubToken(async (token, grant) => {
                  if (
                    !(grant.repository === Repository &&
                      grant.permissions.contents === "write" &&
                      grant.permissions.pull_requests === "write" &&
                      grant.permissions.checks === "read")
                  ) {
                    return { error_code: "fleet.recipe_pr_policy_refused" };
                  }
                  const name = `recipe-pr-open-${args.operation_id}`;
                  return await makeAction(token, {
                    read: () => ctx.readResource(name),
                    write: async (plan) => {
                      await ctx.writeResource("open_plan", name, plan);
                    },
                  }).execute(input);
                });
              } catch (error) {
                if (
                  error instanceof Error &&
                  error.message === "operation.github_token_unavailable"
                ) {
                  return {
                    error_code: "fleet.recipe_pr_credential_unavailable",
                  };
                }
                throw error;
              }
            },
            Result.parse,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = recipePr();
