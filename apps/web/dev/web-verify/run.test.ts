import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { createServer } from "node:http";
import type { AddressInfo } from "node:net";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { webkit } from "playwright";
import { afterAll, beforeAll, expect, it, vi } from "vite-plus/test";
import { applyInsets, exitCode, INSETS, pathsFrom, type ToolResult } from "./contract";
import { execute } from "./command";

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
const fastHome = mkdtempSync(join(tmpdir(), "orbit-web-verify-fast-"));
const browserHome = mkdtempSync(join(tmpdir(), "orbit-web-verify-browser-"));
let initialTask: ToolResult;
let initialPhone: ReturnType<typeof runCLI>;

async function run(home: string, args: readonly string[]) {
    const json = await execute(
        pathsFrom(join(repo, "apps/web/dev/web-verify.ts"), { ORBIT_WEB_VERIFY_HOME: home }),
        args,
    );

    return { status: exitCode(json), json };
}

const upstreamHits: string[] = [];
const upstream = createServer((request, response) => {
    upstreamHits.push(request.url ?? "/");
    response.writeHead(200, { "Content-Type": "application/json" });
    response.end(JSON.stringify({ seen: request.url }));
});

beforeAll(async () => {
    await new Promise<void>((ready) => upstream.listen(0, "127.0.0.1", ready));
    const { port } = upstream.address() as AddressInfo;
    vi.stubEnv("ANNOTATION_TRANSCRIPTION_TARGET", `http://127.0.0.1:${port}`);
    // Start the real daemon and both engines once, even for a selected case.
    // These first-use results are asserted below instead of loading the pages twice.
    const started = runCLI(browserHome, ["open", "/tasks/12"]);
    expect(started.status).toBe(0);
    expect(started.stderr).toBe("");
    initialTask = started.json;
    initialPhone = runCLI(browserHome, [
        "screenshot",
        "/activity",
        "--device=iphone-15",
        "--engine=webkit",
    ]);
    expect(initialPhone.status).toBe(0);
    expect(initialPhone.stderr).toBe("");
}, 120_000);

afterAll(async () => {
    await stop(browserHome);
    await stop(fastHome);
    upstream.close();
    vi.unstubAllEnvs();
    rmSync(fastHome, { recursive: true, force: true });
    rmSync(browserHome, { recursive: true, force: true });
});

it("prints the feature map without starting the app", () => {
    const map = JSON.parse(
        readFileSync(join(repo, "apps/web/feature-map.json"), "utf8"),
    ) as unknown;
    const result = runCLI(fastHome, ["routes"]);
    expect(result.status).toBe(0);
    expect(result.stderr).toBe("");
    expect(result.json).toEqual({ ok: true, command: "routes", routes: map });
    expect(existsSync(join(fastHome, "server.log"))).toBe(false);
});

it("rejects a wrong command line, a map pattern, and an unknown path", async () => {
    const bare = await run(fastHome, []);
    expect(bare.status).toBe(2);
    expect(bare.json.error).toBe("usage");
    expect(bare.json.next).toContain("iphone-15");
    expect(bare.json.next).toContain("webkit");

    const screenshot = await run(fastHome, ["screenshot", "/activity", "--device=iphone-15"]);
    expect(screenshot.status).toBe(2);
    expect(screenshot.json.error).toBe("usage");
    expect(screenshot.json.command).toBe("screenshot");

    const pattern = await run(fastHome, ["open", "/tasks/$id"]);
    expect(pattern.status).toBe(1);
    expect(pattern.json.error).toBe("unresolved-parameter");
    expect(pattern.json.route).toBe("/tasks/$id");
    expect(pattern.json.next).toContain("open /tasks/12");

    const unknown = await run(fastHome, [
        "screenshot",
        "/no/such/page",
        "--device=desktop",
        "--engine=chromium",
    ]);
    expect(unknown.status).toBe(1);
    expect(unknown.json.error).toBe("unknown-route");
    expect(unknown.json.next).toContain("bin/web-verify routes");

    const foreign = await run(fastHome, ["console-errors", "/\t/gateway.orbit"]);
    expect(foreign.status).toBe(2);
    expect(foreign.json).toMatchObject({
        ok: false,
        command: "console-errors",
        error: "usage",
        route: "/\t/gateway.orbit",
    });
    expect(foreign.json.message).toContain("demo server");
    expect(existsSync(join(fastHome, "server.log"))).toBe(false);
}, 30_000);

it("clicks nothing when the checkout has no active page", async () => {
    const result = await run(fastHome, ["click", "[data-testid=nav-menu]"]);
    expect(result.status).toBe(1);
    expect(result.json).toMatchObject({
        ok: false,
        command: "click",
        error: "no-page",
    });
    expect(result.json.next).toContain("open");
    expect(existsSync(join(fastHome, "session.json"))).toBe(false);
});

it("stops a checkout that has no daemon", async () => {
    const extra = await run(fastHome, ["stop", "/activity"]);
    expect(extra.status).toBe(2);
    expect(extra.json.error).toBe("usage");
    expect(extra.json.command).toBe("stop");

    const stopped = runCLI(fastHome, ["stop"]);
    expect(stopped.status).toBe(0);
    expect(stopped.stderr).toBe("");
    expect(stopped.json).toEqual({ ok: true, command: "stop" });
    expect(existsSync(join(fastHome, "session.json"))).toBe(false);
    expect(existsSync(join(fastHome, "server.log"))).toBe(false);
});

it("screenshots phone and desktop pages, clicks the active page, and reports console errors", async () => {
    const phone = initialPhone;
    expect(phone.status).toBe(0);
    expect(phone.stderr).toBe("");
    expect(phone.json).toMatchObject({
        ok: true,
        command: "screenshot",
        route: "/activity",
        pattern: "/activity",
        device: "iphone-15",
        engine: "webkit",
        viewport: { width: 393, height: 659 },
        reused: false,
    });
    expect(phone.json.url).toMatch(/^http:\/\/127\.0\.0\.1:\d+\/activity$/);
    expect(phone.json.file?.endsWith("activity__iphone-15__webkit.png")).toBe(true);
    expectPng(repo, phone.json.file ?? "");

    const phoneAgain = await run(browserHome, [
        "screenshot",
        "/activity",
        "--device=iphone-15",
        "--engine=webkit",
    ]);
    expect(phoneAgain.json).toMatchObject({
        reused: true,
        engine: "webkit",
        device: "iphone-15",
    });

    const phoneChromium = await run(browserHome, [
        "screenshot",
        "/activity",
        "--device=iphone-15",
        "--engine=chromium",
    ]);
    expect(phoneChromium.status).toBe(0);
    expect(phoneChromium.json).toMatchObject({
        ok: true,
        command: "screenshot",
        device: "iphone-15",
        engine: "chromium",
        viewport: { width: 393, height: 659 },
        reused: false,
    });
    expectPng(repo, phoneChromium.json.file ?? "");
    expect((await run(browserHome, ["click", "[data-testid=nav-menu]"])).json).toMatchObject({
        ok: true,
        device: "iphone-15",
        engine: "chromium",
    });

    const desktop = await run(browserHome, [
        "screenshot",
        "/activity",
        "--device=desktop",
        "--engine=chromium",
    ]);
    expect(desktop.status).toBe(0);
    expect(desktop.json).toMatchObject({
        ok: true,
        command: "screenshot",
        route: "/activity",
        pattern: "/activity",
        device: "desktop",
        engine: "chromium",
        viewport: { width: 1280, height: 800 },
        reused: false,
    });
    expect(desktop.json.file?.endsWith("activity__desktop__chromium.png")).toBe(true);
    expectPng(repo, desktop.json.file ?? "");

    const desktopAgain = await run(browserHome, [
        "screenshot",
        "/activity",
        "--device=desktop",
        "--engine=chromium",
    ]);
    expect(desktopAgain.json).toMatchObject({
        reused: true,
        engine: "chromium",
        device: "desktop",
    });

    const desktopClick = await run(browserHome, ["click", "[data-testid=activity-filter-status]"]);
    expect(desktopClick.status).toBe(0);
    expect(desktopClick.json).toMatchObject({
        ok: true,
        command: "click",
        pattern: "/activity",
        device: "desktop",
        engine: "chromium",
        selector: "[data-testid=activity-filter-status]",
    });
    expect(
        desktopClick.json.route === "/activity" ||
            desktopClick.json.route?.startsWith("/activity?"),
    ).toBe(true);

    const desktopWebkit = await run(browserHome, [
        "screenshot",
        "/activity",
        "--device=desktop",
        "--engine=webkit",
    ]);
    expect(desktopWebkit.status).toBe(0);
    expect(desktopWebkit.json).toMatchObject({
        ok: true,
        command: "screenshot",
        device: "desktop",
        engine: "webkit",
        viewport: { width: 1280, height: 800 },
        reused: false,
    });
    expectPng(repo, desktopWebkit.json.file ?? "");
    expect(
        (await run(browserHome, ["click", "[data-testid=activity-filter-status]"])).json,
    ).toMatchObject({
        ok: true,
        device: "desktop",
        engine: "webkit",
    });

    expect(initialTask).toMatchObject({
        ok: true,
        command: "open",
        route: "/tasks/12",
        pattern: "/tasks/$id",
        device: "desktop",
        engine: "chromium",
    });
    expect(initialTask.url).toMatch(/\/tasks\/12$/);

    const phoneOpen = await run(browserHome, [
        "open",
        "/activity",
        "--device=iphone-15",
        "--engine=webkit",
    ]);
    expect(phoneOpen.status).toBe(0);
    expect(phoneOpen.json).toMatchObject({
        device: "iphone-15",
        engine: "webkit",
        pattern: "/activity",
    });

    const menu = await run(browserHome, ["click", "[data-testid=nav-menu]"]);
    expect(menu.status).toBe(0);
    expect(menu.json).toMatchObject({
        ok: true,
        command: "click",
        route: "/activity",
        pattern: "/activity",
        device: "iphone-15",
        engine: "webkit",
        selector: "[data-testid=nav-menu]",
    });

    const errors = await run(browserHome, ["console-errors", "/activity"]);
    expect(errors.status).toBe(0);
    expect(errors.json).toMatchObject({
        ok: true,
        command: "console-errors",
        route: "/activity",
        pattern: "/activity",
        device: "desktop",
        engine: "chromium",
        errors: [],
    });

    const stillPhone = await run(browserHome, ["click", "[data-testid=nav-close]"]);
    expect(stillPhone.status).toBe(0);
    expect(stillPhone.json).toMatchObject({
        route: "/activity",
        device: "iphone-15",
        engine: "webkit",
        selector: "[data-testid=nav-close]",
    });

    const missing = await run(browserHome, ["click", "[data-testid=not-a-control]"]);
    expect(missing.status).toBe(1);
    expect(missing.json).toMatchObject({
        ok: false,
        command: "click",
        error: "selector-missing",
        selector: "[data-testid=not-a-control]",
        device: "iphone-15",
        engine: "webkit",
    });
    expect(missing.json.next).toContain("data-testid");

    const log = readFileSync(join(browserHome, "server.log"), "utf8");
    expect(log).not.toContain("Gateway");

    const check = await webkit.launch({ headless: true });
    try {
        const context = await check.newContext();
        await context.addInitScript(applyInsets, INSETS["iphone-15"]);
        const page = await context.newPage();
        await page.goto(phone.json.url ?? "");
        await page.locator("[data-app-shell]").waitFor();
        const padding = await page.evaluate(() => {
            const shell = document.querySelector("[data-app-shell]");
            if (!(shell instanceof HTMLElement)) return "";

            return getComputedStyle(shell).paddingBottom;
        });
        expect(padding).toBe("34px");
    } finally {
        await check.close();
    }
}, 180_000);

function expectPng(root: string, file: string): void {
    const bytes = readFileSync(resolve(root, file));
    expect(bytes.subarray(0, 8).toString("hex")).toBe("89504e470d0a1a0a");
}

it("does not let demo adapters call the CLI or an upstream service", async () => {
    const port = (upstream.address() as AddressInfo).port;
    // Isolation is an HTTP proof against the already running server, not another navigation.
    const origin = new URL(initialPhone.json.url ?? "").origin;

    const profile = await fetch(`${origin}/__orbit/profile?instance=1`);
    expect(await profile.json()).toEqual({
        ok: false,
        output: "Demo mode does not run orbit profile.",
    });

    const speech = await fetch(`${origin}/__annotate/speech`);
    expect(speech.ok).toBe(true);
    expect(await speech.text()).not.toBe("upstream");

    // The overlay sends a loopback server URL through this prefix, so it reaches the stub.
    const page = await (await fetch(`${origin}/`)).text();
    expect(page).toContain('<meta name="annotate-local-server-proxy" content="/__annotate/local">');

    const annotation = await fetch(`${origin}/__annotate/local/${port}/annotations`);
    expect(await annotation.json()).toEqual({
        error: "Demo mode does not call the annotation server.",
    });
    expect(upstreamHits).toEqual([]);
}, 120_000);

it("reports a screenshot write failure as that command", () => {
    const file = "activity__desktop__chromium.png";
    const blocked = join(browserHome, file);
    rmSync(blocked, { force: true });
    mkdirSync(blocked);
    try {
        // Keep a real CLI failure to check the JSON/exit-code/stderr contract too.
        const failed = runCLI(browserHome, [
            "screenshot",
            "/activity",
            "--device=desktop",
            "--engine=chromium",
        ]);
        expect(failed.status).toBe(1);
        expect(failed.stderr).toBe("");
        expect(failed.json.command).toBe("screenshot");
        expect(failed.json.error).toBe("command-failed");
        expect(failed.json.message).toMatch(/EISDIR|directory|not a file|screenshot/i);
        expect(failed.json.next).toContain("daemon.log");
        expect(failed.json.next).not.toContain("server.log");
        expect(failed.json.message).not.toBe("The demo server did not answer.");
    } finally {
        rmSync(blocked, { recursive: true, force: true });
    }
}, 120_000);

it("stops the daemon and leaves no child processes", async () => {
    const home = browserHome;
    try {
        // Shared setup starts both real engines, including when only this case runs.
        const pid = sessionPid(home);
        expect(pid).not.toBeNull();
        const children = descendantPids(pid ?? 0);
        const tree = children.map(cmdline).join("\n");
        expect(tree).toMatch(/vp/);
        expect(tree).toMatch(/chrom|headless_shell|chrome/i);
        expect(tree).toMatch(/webkit/i);

        // Keep one real CLI shutdown, including JSON, stderr and exit-code assertions.
        const stopped = runCLI(home, ["stop"]);
        expect(stopped.status).toBe(0);
        expect(stopped.stderr).toBe("");
        expect(stopped.json).toEqual({ ok: true, command: "stop" });
        expect(processAlive(pid ?? 0)).toBe(false);
        await waitUntilGone(children);

        const again = await run(home, ["stop"]);
        expect(again.status).toBe(0);
        expect(again.json).toEqual({ ok: true, command: "stop" });
        const click = await run(home, ["click", "[data-testid=nav-menu]"]);
        expect(click.status).toBe(1);
        expect(click.json.error).toBe("no-page");
        expect(sessionPid(home)).toBeNull();
    } finally {
        await stop(home);
        rmSync(home, { recursive: true, force: true });
    }
}, 180_000);
