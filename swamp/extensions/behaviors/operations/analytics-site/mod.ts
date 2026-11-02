import { z } from "npm:zod@4.4.3";
import { Site } from "../analytics/plausible.ts";
import { reconcile, Refusal } from "./site.ts";
const Input = z.object({
    base_url: z.string().url(),
    site: Site,
    env: z.object({ ORBIT_ANALYTICS_CREDENTIALS: z.string() }).strict(),
}).strict();
const Reconciled = z.object({
    ready: z.literal(true),
    domain: Site.shape.domain,
    timezone: z.string(),
    readable: z.literal(true),
}).strict();
const Common = z.object({
    ready: z.literal(true),
    domain: Site.shape.domain.nullable(),
    timezone: z.string().nullable(),
});
const Enabled = Common.extend({
    enabled: z.literal(true),
    readable: z.literal(true),
    domain: Site.shape.domain,
    timezone: z.string().min(1).max(100),
}).strict();
const Disabled = Common.extend({
    enabled: z.literal(false),
    detached: z.literal(true),
}).strict();
const Result = z.discriminatedUnion("enabled", [Enabled, Disabled]);
const ResultOutput = z.discriminatedUnion("enabled", [
    Enabled.extend({ stdout: z.string() }),
    Disabled.extend({ stdout: z.string() }),
]);
type Context = {
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
/** One site action group; the credentials stay in memory and never reach a resource or an error. */
export async function site(input: z.infer<typeof Input>) {
    const { base_url, site, env } = Input.parse(input);
    let secret: unknown;
    try {
        secret = JSON.parse(env.ORBIT_ANALYTICS_CREDENTIALS);
    } catch {
        throw new Refusal(
            "analytics.credentials_invalid",
            "Plausible credentials are not JSON.",
        );
    }
    try {
        return await reconcile(base_url, site, secret);
    } catch (error) {
        // Only code-owned public diagnostics; never provider bodies or exception values.
        if (error instanceof Refusal) {
            console.error(error.detail);
            throw new Error(error.code);
        }
        console.error(
            `Plausible site action failed (${
                (error as Error)?.name ?? "Error"
            }).`,
        );
        throw new Error("analytics.provider_failed");
    }
}
export function result(raw: string) {
    const value = Result.parse(JSON.parse(raw));
    return { ...value, stdout: JSON.stringify(value) };
}
export const behavior = {
    type: "@nckrtl/analytics-site",
    version: "2026.11.02.1",
    resources: {
        site: { schema: Reconciled, lifetime: "1d", garbageCollection: 10 },
        result: {
            schema: ResultOutput,
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        site: {
            description:
                "Reconcile one Plausible site over HTTP and close its management session",
            arguments: Input,
            execute: async (args: z.infer<typeof Input>, ctx: Context) => {
                const value = await site(args);
                await ctx.writeResource("site", "site", value);
                return value;
            },
        },
        result: {
            description:
                "Return the admitted public enable or disable terminal result",
            arguments: z.object({ result_json: z.string() }).strict(),
            execute: async (args: { result_json: string }, ctx: Context) => {
                const value = result(args.result_json);
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
