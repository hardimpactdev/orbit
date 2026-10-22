import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { openApp, pane, row } from "./app";

const path = "/api/v1/instances/1/dependencies";

it("reads saved dependencies only when selected, including with Tasks disabled", async () => {
    const app = await openApp("/instances/1", { tasks: false });
    expect(app.gateway.requests.some((request) => request.path === path)).toBe(false);
    await page.getByTestId("instance-dependencies").click();
    await expect.element(row("Composer", "laravel/framework")).toMatchTextContent("v12.32.0");
    await expect.element(row("npm", "@tanstack/react-query")).toMatchTextContent("5.103.1");
    await expect
        .element(page.getByTestId("instance-dependencies"))
        .toHaveAttribute("aria-selected", "true");
    expect(
        app.gateway.requests.filter((request) => request.path.includes("/dependencies")),
    ).toEqual([{ method: "GET", path, body: undefined }]);
});

it("shows unscanned inventories without starting a scan", async () => {
    const app = await openApp("/instances/2");
    await page.getByTestId("instance-dependencies").click();
    await expect.element(pane("Composer")).toMatchTextContent("Not scanned yet.");
    await expect.element(pane("npm")).toMatchTextContent("Not scanned yet.");
    expect(
        app.gateway.requests
            .filter((request) => request.path.includes("/dependencies"))
            .every((request) => request.method === "GET"),
    ).toBe(true);
});

it("labels stale or failed snapshots and shows absent manifests", async () => {
    await openApp("/instances/1", {
        wrapTransport(inner) {
            return async (method, url, body, options) => {
                const answer = await inner(method, url, body, options);
                if (url !== path) return answer;
                const payload = answer.payload as {
                    data: {
                        composer: Record<string, unknown>;
                        javascript: Record<string, unknown>;
                    };
                };
                payload.data.composer.state = "stale";
                payload.data.composer.succeeded = false;
                payload.data.composer.error_code = "dependencies.scan_failed";
                payload.data.javascript.state = "absent";
                payload.data.javascript.snapshot = null;
                return answer;
            };
        },
    });
    await page.getByTestId("instance-dependencies").click();
    await expect.element(row("Composer", "laravel/framework")).toMatchTextContent("v12.32.0");
    await expect
        .element(page.getByText("Composer: Scan failed", { exact: false }))
        .toMatchTextContent("dependencies.scan_failed");
    await expect.element(pane("npm")).toMatchTextContent("No dependency manifest found.");
});

it("shows loading and a read error instead of an empty inventory", async () => {
    let finish: (() => void) | undefined;
    const waiting = new Promise<void>((resolve) => {
        finish = resolve;
    });
    await openApp("/instances/1", {
        wrapTransport(inner) {
            return async (method, url, body, options) => {
                if (url !== path) return inner(method, url, body, options);
                await waiting;
                return {
                    status: 409,
                    payload: {
                        error: {
                            code: "dependencies.operation_busy",
                            message: "Instance operation is busy.",
                        },
                    },
                };
            };
        },
    });
    await page.getByTestId("instance-dependencies").click();
    await expect.element(pane("Dependencies")).toMatchTextContent("Loading saved dependencies…");
    finish?.();
    await expect.element(page.getByRole("alert")).toMatchTextContent("Instance operation is busy.");
});

it("keeps installed versions and long package names readable on a phone", async () => {
    await page.viewport(393, 659);
    await openApp("/instances/1");
    await page.getByTestId("instance-dependencies").click();
    await expect.element(row("npm", "@tanstack/react-query")).toBeVisible();
    await expect.element(row("npm", "@tanstack/react-query")).toMatchTextContent("5.103.1");
    expect(document.documentElement.scrollWidth).toBeLessThanOrEqual(393);
    expect(pane("npm").element().getBoundingClientRect().width).toBeGreaterThan(300);
});
