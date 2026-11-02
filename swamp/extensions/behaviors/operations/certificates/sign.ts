import { z } from "npm:zod@4.4.3";

const Globals = z.object({
    gatewayUrl: z.string().url().default("https://gateway.orbit"),
    caFile: z.string().min(1).default(
        "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
    ),
});
const Args = z.object({
    operation_id: z.number().int().positive(),
    run_id: z.string().min(1),
    node_id: z.number().int().positive(),
    scope: z.string().min(1),
    request: z.string().min(1),
    restoring: z.boolean().default(false),
});
const Signed = z.object({
    data: z.object({
        certificates: z.array(z.object({
            node_id: z.number().int(),
            scope: z.string(),
            certificate_base64: z.string(),
            root_certificate_base64: z.string(),
        })),
    }),
});
const Result = z.object({ stdout: z.string() });
type Context = {
    globalArgs: z.infer<typeof Globals>;
    signal?: AbortSignal;
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};

const pem = (encoded: string) =>
    new TextDecoder("utf-8", { fatal: true }).decode(
        Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0)),
    );

/** Ask the Gateway sign primitive for one Node-made CSR. The Orbit CA key stays in the Gateway.
 * Returns the signed leaf, then the Orbit root, as PEM; official file steps install them. */
export async function sign(
    ctx: Context,
    args: z.infer<typeof Args>,
    fetch: typeof globalThis.fetch = globalThis.fetch,
    connect = (ca: string): Deno.HttpClient =>
        Deno.createHttpClient({ caCerts: [ca] }),
) {
    const client = connect(await Deno.readTextFile(ctx.globalArgs.caFile));
    try {
        const response = await fetch(
            new URL(
                `/api/v1/direct-operations/${args.operation_id}/certificates/sign`,
                ctx.globalArgs.gatewayUrl,
            ),
            {
                method: "POST",
                redirect: "error",
                client,
                signal: ctx.signal,
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                },
                body: JSON.stringify({
                    run_id: args.run_id,
                    requests: [{
                        node_id: args.node_id,
                        scope: args.scope,
                        state: "request",
                        request_base64: btoa(args.request.trim() + "\n"),
                    }],
                    ...(args.restoring ? { restoring: true } : {}),
                }),
            },
        );
        if (!response.ok) {
            await response.body?.cancel();
            throw new Error(
                `app-dev.certificate_sign_failed: ${response.status}`,
            );
        }
        const leaf = Signed.parse(await response.json()).data.certificates.find(
            (item) =>
                item.node_id === args.node_id && item.scope === args.scope,
        );
        if (!leaf) throw new Error("app-dev.certificate_sign_failed: missing");
        return {
            stdout: pem(leaf.certificate_base64) +
                pem(leaf.root_certificate_base64),
        };
    } finally {
        client.close();
    }
}

export const behavior = {
    type: "@nckrtl/certificate-sign",
    version: "2026.11.02.2",
    globalArguments: Globals,
    resources: {
        result: { schema: Result, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        sign: {
            description:
                "Send one Node-made CSR to the Gateway sign primitive; return the leaf and the Orbit root as PEM",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "result",
                        "sign",
                        await sign(ctx, args),
                    ),
                ],
            }),
        },
    },
};
