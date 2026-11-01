import { assertEquals } from "jsr:@std/assert@1.0.14";
import { binary, Swamp } from "../../node-worker/config.ts";
import { swampInstallSpec } from "./install_spec.ts";

Deno.test("Swamp role install shares the official worker pin and memory floor", () => {
    const plan = swampInstallSpec();
    assertEquals(plan.binary, binary);
    assertEquals(plan.artifact.sha256, Swamp.sha256);
    assertEquals(plan.artifact.bytes, Swamp.bytes);
    assertEquals(plan.minimum_memory_bytes, Swamp.bytes + Swamp.headroom);
    assertEquals(
        plan.artifact.url,
        `https://github.com/swamp-club/swamp/releases/download/v${Swamp.version}/swamp-linux-x86_64`,
    );
});
