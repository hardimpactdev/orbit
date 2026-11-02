import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior, observerFiles } from "../fleet-recipe/observer_assets.ts";
const ctx = {
    extensionFile: (name: string) =>
        new URL("../fleet-recipe/" + name, import.meta.url).pathname,
    writeResource: () => Promise.resolve(),
};
Deno.test("observer files preserve the narrowed canonical unit policy", () => {
    assertEquals(
        observerFiles({
            configuration: "public",
            certificate: "root",
            unit: "ProtectHome=yes",
            apps_root: null,
            home: "/home/orbit",
        }).unit,
        "ProtectHome=tmpfs\nBindReadOnlyPaths=-/home/orbit/apps",
    );
});
Deno.test("DNS refusals and mismatched observer delivery cannot emit readiness", async () => {
    await assertRejects(() =>
        behavior.methods.dns_result.execute({
            stdout: '{"error_code":"wireguard.peer_dns_apply_failed"}',
        }, ctx)
    );
    await assertRejects(() =>
        behavior.methods.observer_delivery.execute({
            sha256: "a".repeat(64),
            stdout: JSON.stringify({
                installed: true,
                changed: false,
                sha256: "b".repeat(64),
            }),
        }, ctx)
    );
});
