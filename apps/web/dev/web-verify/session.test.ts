import { createServer } from "node:http";
import type { AddressInfo } from "node:net";
import { chromium, webkit, type Browser, type BrowserType, type Page } from "playwright";
import { afterAll, expect, it } from "vite-plus/test";
import { applyInsets, INSETS } from "./contract";
import { confineToOrigin } from "./session";

// These protocol checks need real engines, but no daemon or demo dev server.
// Keep their browser launches out of the daemon file's serial lifecycle.
const browsers = new Map<BrowserType, Browser>();
async function browserFor(engine: BrowserType): Promise<Browser> {
    let browser = browsers.get(engine);
    if (browser === undefined) {
        browser = await engine.launch({ headless: true });
        browsers.set(engine, browser);
    }

    return browser;
}

afterAll(async () => {
    await Promise.all([...browsers.values()].map((browser) => browser.close()));
});

it("sets phone safe-area insets and clears them on desktop", async () => {
    const browser = await browserFor(webkit);
    const phone = await browser.newContext();
    const pixel = await browser.newContext();
    const desktop = await browser.newContext();
    try {
        const blank = "data:text/html,<!doctype html><html><body></body></html>";
        await phone.addInitScript(applyInsets, INSETS["iphone-15"]);
        const phonePage = await phone.newPage();
        await phonePage.goto(blank);
        expect(await inset(phonePage, "top")).toBe("0px");
        expect(await inset(phonePage, "bottom")).toBe("34px");
        expect(await inset(phonePage, "left")).toBe("0px");

        await pixel.addInitScript(applyInsets, INSETS["pixel-8"]);
        const pixelPage = await pixel.newPage();
        await pixelPage.goto(blank);
        expect(await inset(pixelPage, "bottom")).toBe("24px");

        await desktop.addInitScript(applyInsets, INSETS.desktop);
        const desktopPage = await desktop.newPage();
        await desktopPage.goto(blank);
        expect(await inset(desktopPage, "bottom")).toBe("");
        expect(await inset(desktopPage, "top")).toBe("");
    } finally {
        await Promise.all([phone.close(), pixel.close(), desktop.close()]);
    }
}, 60_000);

function inset(page: Page, edge: string): Promise<string> {
    return page.evaluate(
        (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim(),
        `--safe-area-inset-${edge}`,
    );
}

it("blocks a direct request and each redirect hop before another host is contacted", async () => {
    const sinkHits: string[] = [];
    const sink = createServer((request, response) => {
        sinkHits.push(request.url ?? "/");
        response.writeHead(200, { "Content-Type": "text/html" });
        response.end("<html><body>sink</body></html>");
    });
    await new Promise<void>((ready) => {
        sink.listen(0, "127.0.0.1", () => ready());
    });
    const sinkPort = (sink.address() as AddressInfo).port;
    const sinkOrigin = `http://127.0.0.1:${sinkPort}`;
    const allowedHits: string[] = [];
    const allowed = createServer((request, response) => {
        const url = request.url ?? "/";
        allowedHits.push(url);
        const times = allowedHits.filter((hit) => hit === url).length;
        if (url === "/flip") {
            if (times === 1) {
                response.writeHead(200, { "Content-Type": "text/html" });
                response.end("<html><body>checked</body></html>");
                return;
            }
            response.writeHead(302, { Location: `${sinkOrigin}/from-second` });
            response.end();
            return;
        }
        if (url === "/bounce") {
            response.writeHead(302, { Location: `${sinkOrigin}/secret` });
            response.end();
            return;
        }
        if (url === "/chain") {
            response.writeHead(302, { Location: "/hop" });
            response.end();
            return;
        }
        if (url === "/hop") {
            response.writeHead(302, { Location: `${sinkOrigin}/chained` });
            response.end();
            return;
        }
        if (url === "/stay") {
            response.writeHead(302, { Location: "/landed" });
            response.end();
            return;
        }
        response.writeHead(200, { "Content-Type": "text/html" });
        response.end(`<html><body>${url}</body></html>`);
    });
    await new Promise<void>((ready) => {
        allowed.listen(0, "127.0.0.1", () => ready());
    });
    const origin = `http://127.0.0.1:${(allowed.address() as AddressInfo).port}`;
    try {
        // route.fetch/redirect and WebSocket interception are implemented separately by
        // each Playwright engine. WebKit is a supported verifier, not an extra layout run.
        for (const engine of [chromium, webkit]) {
            allowedHits.length = 0;
            sinkHits.length = 0;
            const browser = await browserFor(engine);
            const context = await browser.newContext();
            try {
                await confineToOrigin(context, origin);
                const page = await context.newPage();
                const homeAt = allowedHits.length;
                await page.goto(`${origin}/`, { timeout: 5_000 });
                expect(allowedHits.slice(homeAt).filter((hit) => hit === "/")).toEqual(["/"]);
                expect(await page.locator("body").innerText()).toContain("/");

                const stayAt = allowedHits.length;
                await page.goto(`${origin}/stay`, { timeout: 5_000 });
                const stayHits = allowedHits.slice(stayAt);
                expect(stayHits.filter((hit) => hit === "/stay")).toEqual(["/stay"]);
                expect(stayHits.filter((hit) => hit === "/landed")).toEqual(["/landed"]);
                expect(await page.locator("body").innerText()).toContain("/landed");

                const flipAt = allowedHits.length;
                const sinkAt = sinkHits.length;
                await page.goto(`${origin}/flip`, { timeout: 5_000 });
                expect(allowedHits.slice(flipAt).filter((hit) => hit === "/flip")).toEqual([
                    "/flip",
                ]);
                expect(sinkHits.slice(sinkAt)).toEqual([]);
                expect(await page.locator("body").innerText()).toContain("checked");
                expect(new URL(page.url()).origin).toBe(origin);
                expect(sinkHits).toEqual([]);

                const socket = await page.evaluate(
                    (target) =>
                        new Promise((resolve) => {
                            const ws = new WebSocket(target);
                            const finish = (value: string) => resolve(value);
                            ws.onopen = () => finish("open");
                            ws.onerror = () => finish("error");
                            ws.onclose = () => finish("closed");
                            setTimeout(() => finish("timeout"), 2_000);
                        }),
                    `ws://127.0.0.1:${sinkPort}/socket`,
                );
                expect(socket).not.toBe("open");
                expect(socket).not.toBe("timeout");
                expect(sinkHits).toEqual([]);

                const direct = await context.newPage();
                await expect(
                    direct.goto(`${sinkOrigin}/direct`, { timeout: 5_000 }),
                ).rejects.toThrow();
                expect(sinkHits).toEqual([]);

                const redirect = await context.newPage();
                await expect(
                    redirect.goto(`${origin}/bounce`, { timeout: 5_000 }),
                ).rejects.toThrow();
                expect(sinkHits).toEqual([]);

                const chain = await context.newPage();
                await expect(chain.goto(`${origin}/chain`, { timeout: 5_000 })).rejects.toThrow();
                expect(sinkHits).toEqual([]);
            } finally {
                await context.close();
            }
        }
    } finally {
        sink.close();
        allowed.close();
    }
}, 60_000);
