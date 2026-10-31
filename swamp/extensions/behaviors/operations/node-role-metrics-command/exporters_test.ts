import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { exporterPlan } from "./exporters.ts";
import { exporterSpecification } from "../integrations/metrics_exporter_spec.ts";

const args = {
    kind: "node_exporter",
    address: "10.44.0.2",
    metrics_address: "10.44.0.9",
    selected: true,
    observation: { configuration: null, service_active: false },
    ufw: "Status: active\n",
};
Deno.test("exporter plan retains canonical unit policy and the exact previous source for rollback", () => {
    const p = exporterPlan({
        ...args,
        ufw: "Status: active\n[ 3] 10.44.0.2 9100/tcp on orbit ALLOW IN 10.44.0.8 # orbit:metrics-node-exporter",
    });
    assertEquals(
        p.configuration,
        exporterSpecification("node_exporter", args.address).configuration,
    );
    assertEquals(p.before.firewall_source, "10.44.0.8");
    assertEquals(p.remove_rule[7], "10.44.0.8");
    assertEquals(p.add_rule[7], "10.44.0.9");
    assertEquals(p.after.service_active, true);
});
Deno.test("unselected exporter removes only its managed configuration and existing rule", () => {
    const p = exporterPlan({
        ...args,
        selected: false,
        observation: { configuration: "managed", service_active: true },
    });
    assertEquals(p.after, {
        configuration: null,
        service_active: false,
        firewall_source: null,
    });
    assertEquals(p.artifact, null);
    assertEquals(
        exporterSpecification("cadvisor", args.address).artifact?.sha256,
        "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87",
    );
});
Deno.test("inactive or foreign-interface exporter firewall is refused before mutation", () => {
    for (
        const ufw of [
            "Status: inactive",
            "Status: active\n[ 3] 10.44.0.2 9100/tcp on eth0 ALLOW IN 10.44.0.9 # orbit:metrics-node-exporter",
        ]
    ) assertThrows(() => exporterPlan({ ...args, ufw }));
});
Deno.test("exporter verification consumes expected state without forwarding it to the strict input parser", async () => {
    const { behavior } = await import("./assets.ts");
    const expected = {
        configuration: null,
        service_active: false,
        firewall_source: null,
    };
    const result = await behavior.methods.exporter_verify.execute({
        ...args,
        selected: false,
        kind: "node_exporter",
        expected,
    }, {
        extensionFile: () => "unused",
        writeResource: () => Promise.resolve(),
    });
    assertEquals(result, { ready: true });
});
