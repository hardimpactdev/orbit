import { z } from "npm:zod@4.4.3";

export const InspectionArgs = z.object({
    directory: z.string().regex(/^\/tmp\/orbit-ansible-[a-f0-9-]{36}$/),
    request: z.string().uuid(),
}).strict();
export const Inspection = z.object({
    value: z.string().max(8 * 1024 * 1024).meta({
        sensitive: true,
        vaultName: "orbit-control-inputs",
    }),
});
export const Reference = z.object({
    state: z.string().max(32),
    inspection: z.object({
        vault: z.literal("orbit-control-inputs"),
        key: z.string(),
    }),
});

/** A container Process read (docs/reference/development-containers.md#processes): its journal lines go only to the vault, and the
 * run publishes the unit state and the vault key. */
export async function storeInspection(
    args: z.infer<typeof InspectionArgs>,
    ctx: {
        modelId: string;
        writeResource(
            spec: string,
            name: string,
            data: unknown,
        ): Promise<unknown>;
    },
) {
    const input = InspectionArgs.parse(args);
    const value = await Deno.readTextFile(input.directory + "/result.json");
    const observed = z.object({ state: z.string().max(32), output: z.string() }).strict().parse(
        JSON.parse(value),
    );
    const spec = "inspection";
    const name = `inspection-${input.request}`;
    // Native sensitive-field keys include method, spec AND resource instance.
    const key =
        `nckrtl-process-command-assets-${ctx.modelId}-inspection-${spec}-${name}-value`;
    const secret = await ctx.writeResource(
        spec,
        name,
        Inspection.parse({ value }),
    );
    const reference = Reference.parse({
        state: observed.state,
        inspection: { vault: "orbit-control-inputs", key },
    });
    const result = await ctx.writeResource("reference", "result", reference);
    return { dataHandles: [secret, result] };
}
