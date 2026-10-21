import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { expect, it, vi } from "vite-plus/test";
import { pathsFrom } from "./contract";

vi.mock("playwright", () => {
    throw new Error("The short-lived command must not load Playwright");
});

it("validates routes and dispatches without loading browser machinery", async () => {
    const { execute } = await import("./command");
    const paths = pathsFrom(fileURLToPath(new URL("../web-verify.ts", import.meta.url)), {
        ORBIT_WEB_VERIFY_HOME: join(import.meta.dirname, "unused-command-test-home"),
    });
    const routes = await execute(paths, ["routes"]);
    expect(routes).toMatchObject({ ok: true, command: "routes" });
    const invalid = await execute(paths, ["open", "/no/such/page"]);
    expect(invalid).toMatchObject({ ok: false, command: "open", error: "unknown-route" });
    const click = await execute(paths, ["click", "button"]);
    expect(click).toMatchObject({ ok: false, command: "click", error: "no-page" });
});
