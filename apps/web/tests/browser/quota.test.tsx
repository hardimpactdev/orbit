import { expect, it, vi } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { queryClient } from "../../src/api/queryClient";
import { openApp, pane, row } from "./app";
import { screenText } from "./screen";

it("keeps AI router out of the sidebar while ai-router is disabled", async () => {
    await openApp("/");

    await expect.poll(() => screenText()).toContain("Dashboard");
    expect(screenText()).not.toContain("AI router");
});

it("shows provider quota when ai-router is configured and tasks are disabled", async () => {
    await openApp("/ai-router", { "ai-router": true, tasks: false });

    await expect.element(row("AI router", "Codex")).toBeVisible();
    await expect.element(row("AI router", "7d 60%")).toBeVisible();
    expect(screenText()).not.toContain(
        "require both the CLIProxyAPI collector and the tasks extension",
    );
    expect(document.querySelector('[data-testid="quota-unavailable"]')).toBeNull();
    const headers = [...document.querySelectorAll('[role="columnheader"]')].map((header) =>
        (header.textContent ?? "").replace(/\s+/g, " ").trim(),
    );
    expect(headers).not.toContain("Token spend");
    expect(screenText()).not.toContain("Token spend");
});

it("shows AI router as unconfigured while the ai-router extension is enabled without collector setup", async () => {
    const app = await openApp("/ai-router", { aiRouterExtension: true });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: true,
            "ai-router": true,
            documents: true,
            vault: false,
            conn: false,
        });

    await expect.element(page.getByTestId("nav-ai-router")).toBeVisible();
    await expect
        .element(page.getByTestId("quota-unavailable"))
        .toMatchTextContent("CLIProxyAPI collector is not configured");
    expect(
        app.gateway.requests.some((request) => request.path === "/api/v1/ai-router/providers"),
    ).toBe(false);
});

it("keeps AI router visible after the collector is torn down until the extension is disabled", async () => {
    const app = await openApp("/ai-router", { "ai-router": true });
    await expect.element(row("AI router", "Codex")).toBeVisible();
    app.gateway.teardownAiRouter();
    await queryClient.invalidateQueries({ queryKey: ["ai-router-status"] });

    await expect.element(page.getByTestId("nav-ai-router")).toBeVisible();
    await expect
        .element(page.getByTestId("quota-unavailable"))
        .toMatchTextContent("CLIProxyAPI collector is not configured");

    app.gateway.disableAiRouterExtension();
    await queryClient.invalidateQueries({ queryKey: ["extensions"] });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: true,
            "ai-router": false,
            documents: true,
            vault: false,
            conn: false,
        });
    await expect.poll(() => document.querySelector('[data-testid="nav-ai-router"]')).toBeNull();
});

it("lists provider windows from the snapshot without Primary or Secondary labels", async () => {
    await openApp("/ai-router", { "ai-router": true });

    await expect.element(row("AI router", "Codex")).toBeVisible();
    await expect.element(row("AI router", "7d 60%")).toBeVisible();
    await expect.element(row("AI router", "5h 90%")).toBeVisible();
    expect(screenText()).not.toContain("Not reported by Gateway");
    expect(screenText()).not.toContain("Token spend");
    expect(screenText()).not.toContain("Primary");
    expect(screenText()).not.toContain("Secondary");
    expect(screenText()).toContain("Cache updated");
    const meters = document.querySelectorAll('[role="meter"]');
    expect(meters.length).toBeGreaterThan(0);
    for (const meter of meters) {
        const bounds = meter.getBoundingClientRect();
        const rowBounds = meter.closest('[role="row"]')!.getBoundingClientRect();
        expect(bounds.bottom).toBeLessThanOrEqual(rowBounds.bottom);
    }
});

it("shows account controls and never renders a missing window as zero", async () => {
    await openApp("/ai-router/codex", { "ai-router": true });

    await expect.element(pane("Accounts")).toBeVisible();
    await expect.element(row("Accounts", "plus.json")).toMatchTextContent("7d 60%");
    expect(screenText()).not.toContain(" 0%");

    await expect.element(row("Accounts", "disable")).toBeVisible();
});

it("shows successful and failed account toggle outcomes", async () => {
    await openApp("/ai-router/codex", { "ai-router": true });
    const control = row("Accounts", "disable").getByText("disable", { exact: true });
    await control.click();
    await expect.element(row("Accounts", "enable")).toBeVisible();

    await openApp("/ai-router/codex", {
        "ai-router": true,
        wrapTransport: (inner) => async (method, path, body) => {
            if (method === "POST" && path.includes("/ai-router/accounts/")) {
                throw new Error("Account toggle failed");
            }
            return inner(method, path, body);
        },
    });
    await page.getByText("disable", { exact: true }).click();
    await expect.element(page.getByRole("alert")).toMatchTextContent("Account toggle failed");
});

it("positions a red even-pace marker and omits it without a reset", async () => {
    const clock = vi.spyOn(Date, "now").mockReturnValue(Date.parse("2026-09-23T12:00:00Z"));
    try {
        await openApp("/ai-router", { "ai-router": true });
        await expect.element(row("AI router", "7d 60%")).toBeVisible();
        const weekly = document.querySelector('[role="meter"][aria-label="7d remaining"]')!;
        const marker = weekly.querySelector<HTMLElement>("[data-quota-pace]")!;
        expect(marker.style.left).toBe("50%");
        expect(marker.getBoundingClientRect().width).toBeGreaterThan(0);
        expect(weekly.getAttribute("aria-valuetext")).toContain("On pace to last until reset");
        const short = document.querySelector('[role="meter"][aria-label="5h remaining"]')!;
        expect(short.querySelector("[data-quota-pace]")).toBeNull();
    } finally {
        clock.mockRestore();
    }
});
