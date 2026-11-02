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
export const behavior = {
    type: "@nckrtl/analytics-site",
    version: "2026.11.02.2",
    resources: {
        site: { schema: Reconciled, lifetime: "1d", garbageCollection: 10 },
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
    },
};
