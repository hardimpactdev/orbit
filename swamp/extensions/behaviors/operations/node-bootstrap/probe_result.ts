import { z } from "npm:zod@4.4.3";

const Args = z.object({
    bootstrap: z.object({
        platform: z.enum(["linux", "macos"]),
        expected_architecture: z.string().nullable(),
    }),
    observation: z.object({ stdout: z.string(), exitCode: z.literal(0) }),
    host_key: z.object({
        algorithm: z.string(),
        publicKey: z.string(),
        fingerprint: z.string(),
    }),
});
export function bootstrapProbeResult(raw: unknown) {
    const { bootstrap, observation, host_key } = Args.parse(raw);
    const [platform, machine] = observation.stdout.trim().split(/\s+/);
    if (platform !== (bootstrap.platform === "linux" ? "Linux" : "Darwin")) {
        throw new Error("node.platform_mismatch");
    }
    const architecture =
        ({ amd64: "x86_64", aarch64: "arm64" } as Record<string, string>)[
            machine
        ] ?? machine;
    if (
        !["x86_64", "arm64"].includes(architecture) ||
        (bootstrap.platform === "linux" && architecture !== "x86_64")
    ) {
        throw new Error("node.architecture_unsupported");
    }
    if (
        bootstrap.expected_architecture !== null &&
        architecture !== bootstrap.expected_architecture
    ) {
        throw new Error("node.architecture_mismatch");
    }
    return {
        stdout: JSON.stringify({
            probed: true,
            architecture,
            host_type: host_key.algorithm,
            public_key: host_key.publicKey.split(/\s+/)[1],
            host_key_fingerprint: host_key.fingerprint,
        }),
    };
}
const Result = z.object({ stdout: z.string() });
/** Translate official SSH observations; no execution, transport or Gateway client. */
export const behavior = {
    type: "@nckrtl/node-bootstrap-probe-result",
    version: "2026.10.31.1",
    resources: {
        result: { schema: Result, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Project official SSH observations into the bootstrap result",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const result = bootstrapProbeResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
