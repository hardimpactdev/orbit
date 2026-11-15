/**
 * Swamp names a sensitive field's vault key from the METHOD that writes it (data_writer.ts at the pinned db3f6373):
 * `<type>-<modelId>-<method>-<spec>-<instance>-<field>`, unless the field sets a static `vaultKey`. A workflow step that runs such a
 * behavior under a new or renamed method stores its secret under a key that no Gateway grant allows, and that the Gateway does not read.
 * #3794 did that (`create_register`); release 2086's pre-check caught it (docs/reference/implementation-loop.md, Swamp checks).
 * Every step that runs a behavior with a method-named sensitive field names either a method that a Gateway write grant spells out for
 * that behavior, or a method listed below as writing no sensitive field. tests/instance_register_grants_native_test.ts runs the T3
 * registrations under the enforced grants.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

type Step = { name: string; task?: { behaviorType?: unknown; methodName?: unknown } };
const operations = new URL("./", import.meta.url);
const escape = (text: string) => text.replaceAll(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** Methods of a method-named type that write no sensitive field, so their keys need no grant. A new method must be granted or listed. */
const writesNoSensitiveField: Record<string, string[]> = {
    "@nckrtl/process-command-assets": ["lifecycle"],
    "@nckrtl/vault-sync-plan": ["account", "collection", "encode", "inventory", "organization", "plan", "probe", "result", "setup", "writeReceipt"],
};

/** The `@nckrtl/<name>` types of each behavior folder that declares a sensitive field without a static `vaultKey` beside it. */
async function methodNamedTypes() {
    const types = new Set<string>();
    for await (const folder of Deno.readDir(operations)) {
        if (!folder.isDirectory) continue;
        let methodNamed = false;
        const found: string[] = [];
        for await (const file of Deno.readDir(new URL(`${folder.name}/`, operations))) {
            if (!file.name.endsWith(".ts") || file.name.endsWith("_test.ts")) continue;
            const source = await Deno.readTextFile(new URL(`${folder.name}/${file.name}`, operations));
            // A field's metadata: `sensitive: true` and, for a static key, `vaultKey` in the same object (the next few lines).
            for (const match of source.matchAll(/sensitive: ?true/g)) {
                if (!/vaultKey:/.test(source.slice(match.index, match.index + 200).split("}")[0])) methodNamed = true;
            }
            for (const match of source.matchAll(/type: ["'](@nckrtl\/[a-z0-9-]+)["']/g)) found.push(match[1]);
        }
        if (methodNamed) for (const type of found) types.add(type);
    }
    return types;
}

Deno.test("each workflow step that writes a method-named sensitive field names a method that a Gateway write grant allows", async () => {
    const types = await methodNamedTypes();
    for (const type of ["@nckrtl/instance-t3", "@nckrtl/conn-session", "@nckrtl/conn-pairing", "@nckrtl/vault-sync-plan", "@nckrtl/process-command-assets"]) {
        assert(types.has(type), `${type} not found among ${[...types].join(" ")}`);
    }
    assertEquals(types.has("@nckrtl/instance-env-import"), false, "its one sensitive field has a static vaultKey");
    const grants = JSON.parse(await Deno.readTextFile(new URL("../../../config/gateway-workflow-grants.json", import.meta.url))) as
        { vaults: { key_pattern: string; actions: string[] }[] };
    const writes = grants.vaults.filter((grant) => grant.actions.includes("write")).map((grant) => grant.key_pattern);
    const workflows = new URL("../../../workflows/", import.meta.url);
    let seen = 0;
    for await (const file of Deno.readDir(workflows)) {
        if (!file.name.endsWith(".yaml")) continue;
        const flow = parse(await Deno.readTextFile(new URL(file.name, workflows))) as { jobs?: { steps: Step[] }[] };
        for (const step of (flow.jobs ?? []).flatMap((job) => job.steps)) {
            const type = step.task?.behaviorType;
            if (typeof type !== "string") continue;
            assert(!type.includes("${{") || !/@nckrtl\//.test(type), `${file.name} ${step.name}: a computed behaviorType hides its key`);
            if (!types.has(type)) continue;
            seen++;
            const method = String(step.task!.methodName);
            if (writesNoSensitiveField[type]?.includes(method)) continue;
            // The key is `<sanitized type>-<model uuid>-<method>-…`: the method follows the uuid's closing quantifier, alone or as an alternative.
            const prefix = `^${type.replace("@", "").replace("/", "-")}-`;
            const named = new RegExp(`\\}-\\(?(?:\\?:)?(?:.*\\|)?${escape(method)}-`);
            assert(writes.some((pattern) => pattern.startsWith(prefix) && named.test(pattern)),
                `${file.name} ${step.name}: ${type} method ${method} stores a sensitive field under a key no Gateway write grant allows ` +
                    `(grant it, or list the method in writesNoSensitiveField if it writes none)`);
        }
    }
    assert(seen >= 15, `${seen} steps`);
});
