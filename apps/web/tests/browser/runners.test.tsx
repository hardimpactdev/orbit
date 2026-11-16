import { afterEach, expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { openApp, pane, row } from "./app";

afterEach(async () => {
    await page.viewport(1280, 800);
});

/** The cells of a frame whose text does not fit, by their text. */
function cutCells(frame: string): string[] {
    const region = pane(frame).element();

    return [...region.querySelectorAll<HTMLElement>("[role=cell], .row-detail")]
        .filter((cell) => cell.offsetParent !== null && cell.scrollWidth > cell.clientWidth)
        .map((cell) => cell.textContent ?? "");
}

it("opens the runner view from the main nav", async () => {
    const app = await openApp("/");
    await page.getByTestId("nav-runners").click();

    await expect.poll(app.url).toBe("/runners");
    await expect.element(page.getByTestId("runners-title")).toHaveTextContent("Runners");
    expect(app.gateway.requests.map((request) => request.path)).toContain("/api/v1/ci/runners");
});

it("shows each CI Node's places, resources and runners, then the jobs and the runner stats", async () => {
    await openApp("/runners");
    const node = pane("beast");

    await expect
        .element(node.getByRole("img", { name: "Places: 2/4 used · 2 free" }))
        .toBeVisible();
    await expect.element(node.getByText("12.5% pressure")).toBeVisible();
    await expect.element(node.getByText("16.0/64.0 GiB")).toBeVisible();
    await expect.element(node.getByText("dev", { exact: true })).toBeVisible();

    const busy = node.getByRole("row").filter({ hasText: "beast-ci-1" });
    await expect.element(busy).toMatchTextContent(/busy/);
    await expect.element(busy).toMatchTextContent(/2\.330\.0/);
    await expect.element(busy).toMatchTextContent(/acme\/shop · CI · php \(1\)/);
    await expect.element(busy).toMatchTextContent(/4m/);
    await expect
        .element(node.getByRole("row").filter({ hasText: "beast-ci-3" }))
        .toMatchTextContent(/stopped/);

    await expect.element(row("Running and queued", "php (1)")).toMatchTextContent(/in_progress/);
    await expect.element(row("Running and queued", "php (2)")).toMatchTextContent(/queued/);
    await expect.element(row("Runners, last 48 h", "beast-ci-1")).toMatchTextContent(/1 \(50%\)/);
    await expect.element(row("Runners, last 48 h", "beast-ci-1")).toMatchTextContent(/7m 30s/);
    await expect.element(row("Recent jobs, last 48 h", "beast-ci-2")).toMatchTextContent(/success/);
});

it("keeps the runner rows readable on a phone without a sideways scroll", async () => {
    await page.viewport(390, 800);
    await openApp("/runners");
    await expect.element(pane("beast").getByText("acme/shop · CI · php (1) 4m")).toBeVisible();

    expect(cutCells("beast")).toEqual([]);
    expect(cutCells("Runners, last 48 h")).toEqual([]);
    expect(cutCells("Running and queued")).toEqual([]);
    const main = document.querySelector<HTMLElement>("main.page-content");
    expect(main?.scrollWidth).toBeLessThanOrEqual(main?.clientWidth ?? 0);
    expect(document.documentElement.scrollWidth).toBeLessThanOrEqual(window.innerWidth);
});
