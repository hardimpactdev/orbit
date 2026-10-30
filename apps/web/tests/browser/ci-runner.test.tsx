import { expect, it } from "vite-plus/test";
import { queryClient } from "../../src/api/queryClient";
import { applyEvent } from "../../src/realtime/apply";
import { openApp, pane, row } from "./app";

it.each(["/nodes", "/"])("shows a CI-only Node with workload meters on %s", async (path) => {
    await openApp(path);
    await expect.element(row("Tracked nodes", "beast")).toBeVisible();

    applyEvent(queryClient, {
        type: "node.updated",
        id: 2,
        at: "2026-10-21T10:00:00+00:00",
        data: { id: 2, roles: ["ci-runner"] },
    });

    const workload = row("Tracked nodes", "beast");
    await expect.element(workload).toBeVisible();
    await expect.element(workload).toMatchTextContent(/%/);
    await expect
        .element(pane("Other nodes").getByText("beast", { exact: true }))
        .not.toBeInTheDocument();
    if (path === "/nodes") {
        await expect
            .element(workload.getByTitle("ci-runner", { exact: true }))
            .toHaveTextContent("1");
    }
});
