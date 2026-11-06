import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { agentReply } from "./agent_reply.ts";

const pin = "a".repeat(64);
const boot = "878a384f-8b8a-4066-bd17-dcad8eac3e86";
const fingerprint = "c".repeat(64);
const ok = (data: Record<string, unknown>) =>
    JSON.stringify({ data, ok: true });
const refused = (code: string) =>
    JSON.stringify({ error: { code, message: "refused" }, ok: false });

Deno.test("replies keep the agent's public refusal code", () => {
    assertThrows(
        () =>
            agentReply({
                stdout: refused("agent.version_mismatch"),
                expect: "ready",
            }),
        Error,
        "agent.version_mismatch",
    );
    assertThrows(
        () => agentReply({ stdout: "not json", expect: "ready" }),
        Error,
        "task_vm.image_build_failed",
    );
    assertThrows(
        () => agentReply({ stdout: refused("Bad Code"), expect: "ready" }),
        Error,
        "task_vm.image_build_failed",
    );
    assertThrows(
        () => agentReply({ stdout: ok({ ready: false }), expect: "ready" }),
        Error,
        "task_vm.image_build_failed",
    );
    assertEquals(agentReply({ stdout: ok({ ready: true }), expect: "ready" }), {
        ok: true,
    });
});

Deno.test("phase replies need the same boot and the expected state", () => {
    const phase = (state: string, boot_id = boot) =>
        ok({ boot_id, state, unit: "u" });
    agentReply({ stdout: phase("running"), expect: "started", boot_id: boot });
    agentReply({ stdout: phase("complete"), expect: "started", boot_id: boot });
    agentReply({
        stdout: phase("complete"),
        expect: "complete",
        boot_id: boot,
    });
    assertThrows(
        () =>
            agentReply({
                stdout: phase("running"),
                expect: "complete",
                boot_id: boot,
            }),
        Error,
        "task_vm.image_build_failed",
    );
    assertThrows(
        () =>
            agentReply({
                stdout: phase("failed"),
                expect: "started",
                boot_id: boot,
            }),
        Error,
        "task_vm.image_build_failed",
    );
    assertThrows(
        () =>
            agentReply({
                stdout: phase(
                    "complete",
                    "00000000-0000-4000-8000-000000000000",
                ),
                expect: "complete",
                boot_id: boot,
            }),
        Error,
        "task_vm.image_builder_rebooted",
    );
});

Deno.test("install and seed replies check their pins", () => {
    agentReply({
        stdout: ok({ sha256: pin, replaced: true }),
        expect: "installed",
        pin,
    });
    assertThrows(
        () =>
            agentReply({
                stdout: ok({ sha256: "f".repeat(64), replaced: true }),
                expect: "installed",
                pin,
            }),
        Error,
        "task_vm.bootstrap_failed",
    );
    agentReply({
        stdout: ok({ name: "orbit-topology-seed-cccccccccccc" }),
        expect: "seed-volume",
        fingerprint,
    });
    assertThrows(
        () =>
            agentReply({
                stdout: ok({ name: "other" }),
                expect: "seed-volume",
                fingerprint,
            }),
        Error,
        "task_vm.image_build_failed",
    );
});
