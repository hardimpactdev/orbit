import { z } from "npm:zod@4.4.3";

const Observation = z.object({
    stdout: z.string().max(65536),
    exitCode: z.literal(0),
});
const Args = z.object({
    expected_architecture: z.enum(["x86_64", "arm64"]).nullable(),
    address: z.string(),
    platform: Observation,
    network: Observation,
});
const Result = z.object({
    architecture: z.enum(["x86_64", "arm64"]).nullable(),
    verified: z.boolean(),
    error_code: z.string().nullable(),
});

/** Decode public SSH observations. No transport or runtime ownership checks. */
export function macosInspection(raw: unknown) {
    const args = Args.parse(raw);
    const [platform, machine] = args.platform.stdout.trim().split(/\s+/);
    const architecture = z.enum(["x86_64", "arm64"]).safeParse(machine).data ??
        null;
    const addresses = [
        ...args.network.stdout.matchAll(/(?:^|\n)\s*inet\s+([0-9.]+)\s/g),
    ].map((match) => match[1]);
    const error_code = platform !== "Darwin"
        ? "node.platform_mismatch"
        : architecture === null
        ? "node.architecture_unsupported"
        : args.expected_architecture !== null &&
                architecture !== args.expected_architecture
        ? "node.architecture_mismatch"
        : !addresses.includes(args.address)
        ? "node.macos_wireguard_required"
        : null;
    return { architecture, verified: error_code === null, error_code };
}
export const behavior = {
    type: "@nckrtl/node-bootstrap-macos-result",
    version: "2026.11.02.1",
    resources: {
        result: { schema: Result, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        inspect: {
            description: "Decode the Mac's platform and interface observations",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = macosInspection(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
