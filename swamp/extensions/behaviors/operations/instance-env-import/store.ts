import { z } from "npm:zod@4.4.3";

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

export const MaximumBytes = 1048576;

/** Read the private copy once, then delete it; only its size and sha256 leave the method. */
export async function readCopy(path: string) {
    const info = await Deno.lstat(path);
    if (!info.isFile || info.isSymlink || info.size > MaximumBytes) {
        throw new Error("env.import_source_missing");
    }
    try {
        const bytes = await Deno.readFile(path);
        const digest = await crypto.subtle.digest("SHA-256", bytes);
        return {
            content: new TextDecoder("utf-8", { fatal: true }).decode(bytes),
            size: bytes.length,
            sha256: Array.from(
                new Uint8Array(digest),
                (b) => b.toString(16).padStart(2, "0"),
            ).join(""),
        };
    } finally {
        await Deno.remove(path);
    }
}

export const behavior = {
    type: "@nckrtl/instance-env-import",
    version: "2026.11.02.1",
    resources: {
        // Swamp stores each content value in the vault as `instance-env-import.content.<operation>`; data keeps a reference.
        file: {
            schema: z.object({
                size: z.number().int().nonnegative(),
                sha256: z.string().regex(/^[a-f0-9]{64}$/),
                content: z.record(
                    z.string().regex(/^[1-9][0-9]*$/),
                    z.string().meta({
                        sensitive: true,
                        vaultName: "orbit-control-inputs",
                        vaultKey: "instance-env-import",
                    }),
                ),
            }),
            lifetime: "1h",
            garbageCollection: 1,
        },
    },
    methods: {
        store: {
            description:
                "Store the copied .env of one operation in the vault; return only its size and sha256",
            arguments: z.object({
                path: z.string().regex(/^\/tmp\/orbit-env-import-[0-9a-f-]+$/),
                operation: z.number().int().positive(),
            }),
            async execute(
                args: { path: string; operation: number },
                ctx: Context,
            ) {
                const file = await readCopy(args.path);
                await ctx.writeResource("file", "file", {
                    size: file.size,
                    sha256: file.sha256,
                    content: { [String(args.operation)]: file.content },
                });
                return { size: file.size, sha256: file.sha256 };
            },
        },
    },
};
