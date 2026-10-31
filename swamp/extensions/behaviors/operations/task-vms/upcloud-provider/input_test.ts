import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import { behavior, UpCloudIntent } from "./input.ts";

export function intent() {
    return {
        task_vm_id: 184,
        node_id: 14,
        gateway_node_id: 14,
        provider: "upcloud" as const,
        provider_id: null,
        name: "tvm-184",
        image_fingerprint: "11111111-1111-4111-8111-111111111111",
        recipe_identity: "recipe-278",
        provider_host_public_key:
            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4 tvm-184",
        upcloud: {
            zone: "nl-ams1",
            plan: "2xCPU-4GB",
            disk_gb: 20,
            max_vms: 4,
            source_template: "11111111-1111-4111-8111-111111111111",
            gateway_public_key: "ssh-ed25519 PUBLIC",
        },
    };
}
const context = (writes: Record<string, unknown>[]) => ({
    extensionFile: (path: string) => "/native-cache/" + path,
    writeResource: (spec: string, _name: string, value: unknown) => {
        writes.push({ spec, value });
        return Promise.resolve({ spec });
    },
});
Deno.test("UpCloud assets serialize only the provider subset of full parent public intent", async () => {
    const writes: Record<string, unknown>[] = [];
    await behavior.methods.read.execute({
        intent: { ...intent(), unrelated_public: "root" } as ReturnType<
            typeof intent
        >,
        root_node_id: 14,
    }, context(writes));
    const assets = writes[0].value as Record<string, string>;
    assertEquals(JSON.parse(assets.input_json), intent());
    assertEquals(assets.resolve_script, "/native-cache/scripts/resolve.py");
});
Deno.test("UpCloud public inputs refuse a changed VM, template or executor", () => {
    const mutations = [
        { ...intent(), name: "tvm-185" },
        { ...intent(), node_id: 209 },
        {
            ...intent(),
            image_fingerprint: "22222222-2222-4222-8222-222222222222",
        },
    ];
    for (const value of mutations) {
        assertThrows(() => UpCloudIntent.parse(value));
    }
});
Deno.test("Unchanged root identity is required before script assets or vault steps", async () => {
    const writes: Record<string, unknown>[] = [];
    await assertRejects(
        () =>
            behavior.methods.read.execute({
                intent: intent(),
                root_node_id: 209,
            }, context(writes)),
        Error,
        "task_vm.provider_create_refused",
    );
    assertEquals(writes, []);
});
Deno.test("Provider result preserves exact captured host pin and image", async () => {
    const value = intent();
    const provider = {
        provider_id: "22222222-2222-4222-8222-222222222222",
        address: "8.8.4.4",
        ssh_host_key: value.provider_host_public_key,
        image_fingerprint: value.image_fingerprint,
        running: true,
        settled: true,
    };
    const writes: Record<string, unknown>[] = [];
    await behavior.methods.result.execute({
        intent: value,
        stdout: JSON.stringify(provider),
    }, context(writes));
    assertEquals(writes[0].value, { provider });
    assertThrows(() =>
        behavior.methods.result.execute({
            intent: value,
            stdout: JSON.stringify({
                ...provider,
                image_fingerprint: "33333333-3333-4333-8333-333333333333",
            }),
        }, context(writes))
    );
});
