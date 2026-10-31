import { z } from "npm:zod@4.4.3";
import { Leaf as CertificateLeaf, Scope } from "../certificates/input.ts";

export const Domain = z.string().regex(/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/)
    .max(253);

/** Public tracking-leaf identity shared by admission payloads and native steps. */
export const Leaf = z.object({
    node_id: z.number().int().positive(),
    route_id: z.number().int().positive(),
    user: Scope.shape.user,
    agent_sha256: Scope.shape.agent_sha256,
    home: Scope.shape.home,
    in_use: z.literal(false).optional(),
    domain: Domain,
    scope: z.string(),
    version: z.string().regex(/^[a-f0-9]{16}$/).optional(),
    root_hash: z.string().regex(/^[a-f0-9]{64}$/).optional(),
}).strict().refine((leaf) => leaf.scope === `route-${leaf.route_id}-router`);

export const PreparingLeaf = Leaf.safeExtend({
    version: z.string().regex(/^[a-f0-9]{16}$/),
    root_hash: z.string().regex(/^[a-f0-9]{64}$/),
});
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const RawRequest = z.union([
    z.object({ state: z.literal("current") }).strict(),
    z.object({
        state: z.literal("request"),
        request_base64: z.string().max(32768).refine((value) => {
            try {
                return /^-----BEGIN CERTIFICATE REQUEST-----\n[A-Za-z0-9+/=\r\n]+-----END CERTIFICATE REQUEST-----\s*$/
                    .test(atob(value));
            } catch {
                return false;
            }
        }),
    }).strict(),
    z.object({ error_code: Code }).strict(),
]);

/** Bind the analytics Route scope before exposing any canonical certificate action. */
export function preparingIntent(rawLeaf: unknown) {
    const { route_id: _route, in_use: _inUse, ...intent } = PreparingLeaf.parse(
        rawLeaf,
    );
    return CertificateLeaf.parse(intent);
}

export const PublicRequest = z.object({
    node_id: z.number().int().positive(),
    scope: z.string(),
    state: z.enum(["current", "request"]),
    request_base64: z.string().max(32768).optional(),
}).strict();

/** Parse one canonical request command; never reads intent or performs an effect. */
export function preparedRequest(rawLeaf: unknown, stdout: string) {
    let code = "analytics.certificate_response_invalid";
    try {
        const leaf = PreparingLeaf.parse(rawLeaf);
        const output = RawRequest.parse(JSON.parse(stdout));
        if ("error_code" in output) {
            code = output.error_code;
            throw new Error();
        }
        return PublicRequest.parse({
            node_id: leaf.node_id,
            scope: leaf.scope,
            state: output.state,
            ...("request_base64" in output
                ? { request_base64: output.request_base64 }
                : {}),
        });
    } catch {
        throw new Error(code);
    }
}

export const behavior = {
    type: "@nckrtl/analytics-host-plan",
    version: "2026.10.31.2",
    resources: {
        intent: {
            description: "Admitted public Router certificate intent",
            schema: z.object({ intent: CertificateLeaf }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        request: {
            description: "Public CSR or current-leaf observation",
            schema: PublicRequest,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        preparing: {
            description:
                "Validate the admitted analytics leaf before its Node-local command",
            arguments: z.object({ leaf: PreparingLeaf }).strict(),
            execute: async (
                args: { leaf: z.infer<typeof PreparingLeaf> },
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = { intent: preparingIntent(args.leaf) };
                await ctx.writeResource("intent", "intent", result);
                return result;
            },
        },
        prepared: {
            description:
                "Shape the canonical local request output for the parent signer",
            arguments: z.object({
                leaf: PreparingLeaf,
                stdout: z.string().max(32768),
            }).strict(),
            execute: async (
                args: { leaf: z.infer<typeof PreparingLeaf>; stdout: string },
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = preparedRequest(args.leaf, args.stdout);
                await ctx.writeResource("request", "request", result);
                return result;
            },
        },
    },
};
