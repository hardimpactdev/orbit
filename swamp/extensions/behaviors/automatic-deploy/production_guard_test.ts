import { assertEquals } from "jsr:@std/assert@1.0.19";
import { productionGuard } from "./production_guard_fixture.ts";

Deno.test("production matrix matches orbit-main receipts, history decoding and retry decisions", async () => {
    const actual = await productionGuard();
    const golden = new URL("./production_guard.golden.json", import.meta.url);
    // Regenerate only on a detached orbit-main checkout; see docs/reference/deployments.md.
    if (Deno.env.get("ORBIT_GENERATE_PRODUCTION_GOLDEN") === "1") {
        await Deno.writeTextFile(
            golden,
            JSON.stringify(actual, null, 4) + "\n",
        );
    }
    assertEquals(actual, JSON.parse(await Deno.readTextFile(golden)));
});
