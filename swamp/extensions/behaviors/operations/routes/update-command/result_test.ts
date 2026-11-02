import { assertEquals } from "jsr:@std/assert@1.0.19";
import { phaseResult } from "./result.ts";

Deno.test("a mutation phase is ready only when its single child succeeded", () => {
    const state = (status: string) => ({ status });
    assertEquals(phaseResult({ mode: "forward", steps: { "forward-0": state("succeeded") } }), { ready: true });
    assertEquals(phaseResult({ mode: "withdraw", steps: { "withdraw-0": state("failed") } }), { ready: false });
    assertEquals(phaseResult({ mode: "restore", steps: {} }), { ready: false });
});
