import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { expect, it } from "vite-plus/test";
import { execute } from "./command";
import { pathsFrom } from "./contract";
import {
    cmdline,
    descendantPids,
    processAlive,
    repo,
    runCLI,
    sessionPid,
    stop,
    waitUntilGone,
} from "./test-processes";

// One real server here: idle expiry must prove cleanup independently of explicit stop.
it("exits after the idle timeout and leaves no child processes", async () => {
    const home = mkdtempSync(join(tmpdir(), "orbit-web-verify-idle-"));
    try {
        const opened = runCLI(home, ["open", "/activity"], { ORBIT_WEB_VERIFY_IDLE_MS: "500" });
        expect(opened.status).toBe(0);
        const pid = sessionPid(home);
        expect(pid).not.toBeNull();
        const children = descendantPids(pid ?? 0);
        const tree = children.map(cmdline).join("\n");
        expect(tree).toMatch(/vp/);
        expect(children.length).toBeGreaterThan(0);

        const deadline = Date.now() + 20_000;
        while (pid !== null && processAlive(pid) && Date.now() < deadline) await delay(100);
        expect(processAlive(pid ?? 0)).toBe(false);
        await waitUntilGone(children);

        const click = await execute(
            pathsFrom(join(repo, "apps/web/dev/web-verify.ts"), { ORBIT_WEB_VERIFY_HOME: home }),
            ["click", "[data-testid=nav-menu]"],
        );
        expect(click.ok).toBe(false);
        expect(click.error).toBe("no-page");
    } finally {
        await stop(home);
        rmSync(home, { recursive: true, force: true });
    }
}, 180_000);
