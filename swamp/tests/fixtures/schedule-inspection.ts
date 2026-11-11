import { behavior } from "../../extensions/behaviors/operations/process-commands/assets.ts";

/** Exercise the production emitter; derive native keys from its actual write arguments.
 * Pinned Swamp data_writer.ts uses type/model/method/spec/instance/field,
 * drops @ and replaces path separators with dashes. No Swamp account is used. */
export async function scheduleInspectionFixture(output: string) {
    const request = crypto.randomUUID();
    const modelId = crypto.randomUUID();
    const directory = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    const value = JSON.stringify({ state: "active", output });
    const vaulted: { vault: string; key: string; value: unknown }[] = [];
    let reference: unknown;
    const methodName = "inspection";
    await Deno.mkdir(directory, { mode: 0o700 });
    try {
        await Deno.writeTextFile(`${directory}/result.json`, value);
        await behavior.methods[methodName].execute({ directory, request }, {
            modelId,
            writeResource(spec, name, data) {
                if (spec === "reference") reference = data;
                const schema = behavior.resources[
                    spec as keyof typeof behavior.resources
                ].schema;
                for (
                    const [field, definition] of Object.entries(schema.shape)
                ) {
                    const metadata = definition.meta();
                    if (metadata?.sensitive !== true) continue;
                    const key = [
                        behavior.type,
                        modelId,
                        methodName,
                        spec,
                        name,
                        field,
                    ].join("/").replaceAll("@", "").replace(/[/\\]/g, "-");
                    vaulted.push({
                        vault: String(metadata.vaultName),
                        key,
                        value: (data as Record<string, unknown>)[field],
                    });
                }
                return Promise.resolve({ spec, name });
            },
        });
        return { request, modelId, value, vaulted, reference };
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
}

if (import.meta.main) {
    console.log(
        JSON.stringify(await scheduleInspectionFixture(Deno.args[0] ?? "")),
    );
}
