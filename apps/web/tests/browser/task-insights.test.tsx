import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { INSIGHTS_EXPANDED_KEY } from "../../src/tasks/TaskInsights";
import { openApp, pane } from "./app";

function insightsRequests(gateway: Awaited<ReturnType<typeof openApp>>["gateway"]): string[] {
    return gateway.requests
        .filter((request) => request.path.startsWith("/api/v1/task-insights"))
        .map((request) => request.path);
}

it("shows the KPI strip with changes against the previous period, collapsed by default", async () => {
    window.localStorage.removeItem(INSIGHTS_EXPANDED_KEY);
    await openApp("/tasks");
    const strip = pane("Insights");

    await expect.element(strip.getByText("42.8M")).toBeVisible();
    await expect.element(strip.getByText("Tokens / merged PR")).toBeVisible();
    await expect.element(strip.getByText("$31.27")).toBeVisible();
    await expect.element(strip.getByText("4h 12m")).toBeVisible();
    await expect.element(strip.getByText("▲4pt")).toHaveClass("text-green");
    await expect.element(strip.getByText("▲2pt")).toHaveClass("text-red");
    await expect
        .element(strip.getByText("31 tasks · 22 merged · vs the previous 30 days"))
        .toBeVisible();
    expect(page.getByTestId("insights-details").elements()).toHaveLength(0);
});

it("opens the details, remembers the choice, and switches the breakdown", async () => {
    window.localStorage.removeItem(INSIGHTS_EXPANDED_KEY);
    await openApp("/tasks");
    await userEvent.click(pane("Insights").getByRole("button", { name: "more ▾" }));

    await expect.element(page.getByTestId("insights-weekly")).toBeVisible();
    await expect.element(page.getByTestId("insights-daily")).toBeVisible();
    await expect.element(page.getByTestId("insights-lead-time")).toBeVisible();
    expect(page.getByTestId("insights-lead-time").element().textContent).toContain(
        "3 merged with no lead time",
    );
    expect(page.getByTestId("insights-lead-time").element().textContent).toContain("merge wait 1h");
    expect(page.getByTestId("insights-release").elements()).toHaveLength(2);
    await expect
        .element(page.getByTestId("insights-breakdown"))
        .toMatchTextContent(/gpt-5\.6-luna/);
    await userEvent.click(page.getByRole("button", { name: "Release" }));
    await expect.element(page.getByTestId("insights-breakdown")).toMatchTextContent(/9e13f78/);
    expect(window.localStorage.getItem(INSIGHTS_EXPANDED_KEY)).toBe("1");

    await openApp("/tasks");
    await expect.element(page.getByTestId("insights-details")).toBeVisible();
    await userEvent.click(pane("Insights").getByRole("button", { name: "less ▴" }));
    expect(window.localStorage.getItem(INSIGHTS_EXPANDED_KEY)).toBe("0");
});

it("asks for the chosen period and Project", async () => {
    const { gateway } = await openApp("/tasks");
    await expect.element(pane("Insights").getByText("42.8M")).toBeVisible();

    await userEvent.click(pane("Insights").getByRole("button", { name: "7D" }));
    await expect.element(pane("Insights").getByText(/vs the previous 7 days/)).toBeVisible();
    await userEvent.selectOptions(
        pane("Insights").getByRole("combobox", { name: "Project" }),
        "Charlie shop",
    );

    await expect
        .poll(() => insightsRequests(gateway))
        .toEqual([
            "/api/v1/task-insights?period=30d",
            "/api/v1/task-insights?period=7d",
            "/api/v1/task-insights?period=7d&project_id=3",
        ]);
});

it("does not show the strip on an Instance's task board", async () => {
    const { gateway } = await openApp("/instances/1");
    await page.getByRole("tab", { name: /^Tasks/ }).click();

    await expect.element(page.getByTestId("tasks-board")).toBeVisible();
    expect(page.getByTestId("task-insights").elements()).toHaveLength(0);
    expect(insightsRequests(gateway)).toEqual([]);
});
