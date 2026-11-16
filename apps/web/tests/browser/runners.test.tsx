import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { openApp, pane, row } from "./app";

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
