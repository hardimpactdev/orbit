import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./inputs.ts";

const intent = {
    removal_id: "1699c2db-8119-4439-a685-7e555f6a0641",
    instance_id: 71,
    project_id: 11,
    node_id: 9,
    environment: "production" as const,
    members: [{ instance_id: 71 }],
    direct_destroy: {
        runtime: { processes: [] },
        teardown: [{
            instance_id: 71,
            checkout: "/home/orbit-app-11/current/server/web",
            user: "orbit-app-11",
            steps: [{
                name: "cleanup",
                sha256: "a".repeat(64),
                timeout_seconds: 30,
            }],
        }],
    },
};
const ctx = {
    extensionFile: (name: string) => "/cache/removal/" + name,
    writeResource: () => Promise.resolve(undefined),
};
Deno.test("removal keeps full admitted intent without fetching the Gateway", async () => {
    const value = await behavior.methods.read.execute({ intent }, ctx);
    assertEquals(JSON.parse(value.intent_json), intent);
    assertEquals(value.library, "/cache/removal");
});
Deno.test("partial production without a current release skips teardown", async () => {
    const value = await behavior.methods.capture.execute({
        intent,
        stdout: JSON.stringify({
            source_dispositions: [{ instance_id: 71, teardown_required: true }],
            source_inspection: {
                members: [{
                    instance_id: 71,
                    release: null,
                    source: "/home/orbit-app-11",
                }],
            },
        }),
    }, ctx);
    assertEquals(value.teardown, []);
    assertEquals(value.members.length, 1);
});
Deno.test("each admitted teardown program is a separate native iteration", async () => {
    const value = await behavior.methods.capture.execute({
        intent,
        stdout: JSON.stringify({
            source_dispositions: [{ instance_id: 71, teardown_required: true }],
            source_inspection: {
                members: [{
                    instance_id: 71,
                    release: "/home/orbit-app-11/releases/initial",
                    source: "/home/orbit-app-11",
                }],
            },
        }),
    }, ctx);
    assertEquals(value.teardown.length, 1);
    assertEquals(JSON.parse(value.teardown[0].input_json).step.name, "cleanup");
    assertEquals(
        JSON.parse(value.teardown[0].input_json).checkout,
        "/home/orbit-app-11/releases/initial/server/web",
    );
    assertEquals(value.teardown[0].secret_field, "teardown_71_0");
});
Deno.test("capture cannot substitute a different member", async () => {
    await assertRejects(() =>
        behavior.methods.capture.execute({
            intent,
            stdout: JSON.stringify({
                source_dispositions: [{
                    instance_id: 71,
                    teardown_required: true,
                }],
                source_inspection: {
                    members: [{
                        instance_id: 72,
                        release: null,
                        source: "/home/orbit-app-11",
                    }],
                },
            }),
        }, ctx)
    );
});
