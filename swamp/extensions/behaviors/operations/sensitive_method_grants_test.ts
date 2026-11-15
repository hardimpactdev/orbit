/**
 * Swamp names a sensitive field's vault key from the METHOD that writes it (data_writer.ts at the pinned db3f6373):
 * `<type>-<modelId>-<method>-<spec>-<instance>-<field>`, unless the field sets a static `vaultKey`. A workflow step that runs such a
 * behavior under a new or renamed method stores its secret under a key that no Gateway grant allows, and that the Gateway does not read.
 * #3794 did that (`create_register`); release 2086's pre-check caught it. Every step that runs a behavior with a method-named sensitive
 * field must name a method that a Gateway write grant spells out for that behavior. tests/instance_register_grants_native_test.ts runs
 * the T3 registrations under the enforced grants.
 */
import { assert } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

type Step = { name: string; task?: { behaviorType?: unknown; methodName?: unknown } };
const operations = new URL("./", import.meta.url);
const escape = (text: string) => text.replaceAll(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** The `@nckrtl/<name>` types whose behaviors declare a sensitive field without a static `vaultKey`. */
async function methodNamedTypes() {
    const types = new Set<string>();
    for await (const folder of Deno.readDir(operations)) {
        if (!folder.isDirectory) continue;
        for await (const file of Deno.readDir(new URL(`${folder.name}/`, operations))) {
            if (!file.name.endsWith(".ts") || file.name.endsWith("_test.ts")) continue;
            const source = await Deno.readTextFile(new URL(`${folder.name}/${file.name}`, operations));
            if (!/sensitive: ?true/.test(source) || /vaultKey:/.test(source)) continue;
            for (const match of source.matchAll(/type: ["'](@nckrtl\/[a-z0-9-]+)["']/g)) types.add(match[1]);
        }
    }
    return types;
}

Deno.test("each workflow step that writes a method-named sensitive field names a method that a Gateway write grant allows", async () => {
    const types = await methodNamedTypes();
    assert(types.has("@nckrtl/instance-t3") && types.has("@nckrtl/conn-session"), [...types].join(" "));
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
            if (typeof type !== "string" || !types.has(type)) continue;
            seen++;
            const method = String(step.task!.methodName);
            // The key's type part is the sanitized type (`nckrtl-instance-t3-`); the method follows the model id, or opens an alternation.
            const prefix = `^${type.replace("@", "").replace("/", "-")}-`;
            const named = new RegExp(`(?:-|\\(\\?:|\\|)${escape(method)}-`);
            assert(writes.some((pattern) => pattern.startsWith(prefix) && named.test(pattern)),
                `${file.name} ${step.name}: ${type} method ${method} stores its sensitive field under a key no Gateway write grant allows`);
        }
    }
    assert(seen >= 5, `${seen} steps`);
});
