import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { servicePlan } from "./service.ts";
import { behavior } from "./assets.ts";
const absent = {
    config: null,
    unit: null,
    rules: [],
    active: false,
    enabled: false,
    binary: false,
};
const args = {
    desired: absent,
    observation: {
        config: null,
        unit: null,
        active: false,
        enabled: false,
        binary: false,
        directory_present: false,
    },
    ufw_status: "Status: active",
    ufw_added: "",
};
Deno.test("service delta preserves actual absence and independently validates complete desired state", () => {
    const plan = servicePlan(args);
    assertEquals(plan.before, absent);
    assertEquals(plan.remove_rules, []);
    assertEquals(plan.add_rules, []);
    assertThrows(() =>
        servicePlan({ ...args, desired: { ...absent, active: true } })
    );
    assertThrows(() =>
        servicePlan({ ...args, ufw_status: "Status: inactive" })
    );
});
Deno.test("service native verifier consumes expected separately and rejects unready observations", async () => {
    const context = {
        extensionFile: () => "unused",
        writeResource: () => Promise.resolve(),
    };
    assertEquals(
        await behavior.methods.service_verify.execute({
            ...args,
            expected: absent,
        }, context),
        { ready: true },
    );
    await assertRejects(() =>
        behavior.methods.service_verify.execute({
            ...args,
            expected: { ...absent, binary: true },
        }, context)
    );
    await assertRejects(() =>
        behavior.methods.service_health.execute({ stdout: "starting" }, context)
    );
    assertEquals(
        await behavior.methods.service_health.execute({
            stdout: "# HELP actual metrics\n",
        }, context),
        { ready: true },
    );
});
