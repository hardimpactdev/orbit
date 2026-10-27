import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import fixture from "../../fixtures/fleet/task-swamp-runs.12.json";
import { queryClient } from "../../src/api/queryClient";
import { openApp, pane } from "./app";

const endpoint = "/api/v1/task-groups/12/swamp-runs";

it("shows deterministic runs without an agent transcript and expands plain text output", async () => {
    const app = await openApp("/tasks/12", {
        wrapTransport: (inner) => (method, path, body) =>
            path.endsWith("/agents")
                ? Promise.resolve({ status: 200, payload: { data: [] } })
                : inner(method, path, body),
    });
    const panel = pane("Swamp");
    await expect.element(panel).toMatchTextContent("maintenance-worker · running");
    await expect.element(panel).toMatchTextContent("maintenance / before · succeeded");
    await expect.element(panel).toMatchTextContent("maintenance / composer · running");
    await expect.element(panel).toMatchTextContent("maintenance / after · pending");
    await expect
        .element(panel)
        .toMatchTextContent("Worker output may arrive when this step finishes.");
    await panel.getByText("Step output", { exact: true }).click();
    await expect.element(panel.getByText(/"changed": false/)).toBeVisible();
    await panel.getByText("Run log", { exact: true }).click();
    await expect.element(panel.getByText("Starting maintenance on the task worker.")).toBeVisible();
    expect(app.gateway.requests.some((request) => request.path === endpoint)).toBe(true);
    expect(app.gateway.requests.every((request) => request.path.startsWith("/api/"))).toBe(true);
});

it("refreshes output and states, shows stale data, and recovers", async () => {
    let snapshot = structuredClone(fixture.body.data);
    await openApp("/tasks/12", {
        wrapTransport: (inner) => (method, path, body) =>
            path === endpoint
                ? Promise.resolve({ status: 200, payload: { data: snapshot } })
                : inner(method, path, body),
    });
    const panel = pane("Swamp");
    await expect.element(panel).toMatchTextContent("composer · running");
    snapshot.runs[0]!.steps[1]!.status = "failed";
    snapshot.runs[0]!.steps[1]!.output = "<script>never execute</script>\nCheck failed";
    snapshot.runs[0]!.steps[1]!.truncated = true;
    snapshot.runs[0]!.status = "failed";
    snapshot.runs[0]!.log_truncated = true;
    snapshot.truncated = true;
    // The same refetch used by the five-second poll, without a real-time wait.
    await queryClient.refetchQueries({ queryKey: ["task-groups", "12", "swamp-runs"] });
    await expect.element(panel).toMatchTextContent("maintenance-worker · failed");
    await expect.element(panel).toMatchTextContent("Step output truncated.");
    await expect.element(panel).toMatchTextContent("Run log truncated");
    await panel.getByText("Step output", { exact: true }).nth(1).click();
    await expect.element(panel.getByText(/<script>never execute<\/script>/)).toBeVisible();
    expect(document.querySelectorAll('[data-testid="task-swamp-runs"] script')).toHaveLength(0);
    snapshot.availability = "stale";
    await queryClient.refetchQueries({ queryKey: ["task-groups", "12", "swamp-runs"] });
    await expect.element(panel).toMatchTextContent("Showing the last successful read.");
    await expect.element(panel).toMatchTextContent("composer · failed");
    snapshot = structuredClone(fixture.body.data);
    await queryClient.refetchQueries({ queryKey: ["task-groups", "12", "swamp-runs"] });
    await expect
        .element(panel.getByText(/Showing the last successful read/))
        .not.toBeInTheDocument();
});

it.each(["available", "unavailable"])(
    "keeps an empty %s read separate from the task page",
    async (availability) => {
        await openApp("/tasks/12", {
            wrapTransport: (inner) => (method, path, body) =>
                path === endpoint
                    ? Promise.resolve({
                          status: 200,
                          payload: {
                              data: { availability, observed_at: null, runs: [], truncated: false },
                          },
                      })
                    : inner(method, path, body),
        });
        await expect
            .element(pane("Swamp"))
            .toMatchTextContent(
                availability === "available"
                    ? "No Swamp runs for this task group."
                    : "Swamp output is unavailable.",
            );
        await expect.element(pane("Subtasks")).toBeVisible();
    },
);

it("keeps transport errors local to the panel and never polls on a subtask page", async () => {
    const app = await openApp("/tasks/12", {
        wrapTransport: (inner) => (method, path, body) =>
            path === endpoint
                ? Promise.resolve({ status: 502, payload: { error: { message: "Failed" } } })
                : inner(method, path, body),
    });
    await expect.element(pane("Swamp")).toMatchTextContent("Swamp output is unavailable.");
    await expect.element(pane("Subtasks")).toBeVisible();
    await app.router.navigate({
        to: "/tasks/$id/subtasks/$subtaskId",
        params: { id: "12", subtaskId: "31" },
    });
    await expect.element(page.getByTestId("task-swamp-runs")).not.toBeInTheDocument();
});
