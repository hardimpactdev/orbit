import { expect, it, vi } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { setTransport, type Transport } from "../../src/api/client";
import { queryClient } from "../../src/api/queryClient";
import { applyEvent } from "../../src/realtime/apply";
import { connectRealtime } from "../../src/realtime/connect";
import { setLiveness } from "../../src/realtime/liveness";
import { footer, openApp, pane, row } from "./app";

it("runs an action from the menu and shows the row the Gateway answered with", async () => {
    const app = await openApp("/processes");
    await expect.element(row("Processes", "vite")).toHaveAttribute("data-warn");

    // Into the list, down to vite, then its actions.
    await userEvent.keyboard("{ArrowRight}{Enter}{ArrowDown}");
    await expect.element(row("Processes", "vite")).toHaveAttribute("aria-selected", "true");
    await userEvent.keyboard("x");
    await expect
        .element(pane("vite").getByRole("menuitem", { name: "restart", exact: true }))
        .toHaveAttribute("data-selected");

    await userEvent.keyboard("{ArrowDown}");
    await expect
        .element(pane("vite").getByRole("menuitem", { name: "start", exact: true }))
        .toHaveAttribute("data-selected");
    await userEvent.keyboard("{Enter}");

    await expect.element(footer()).toMatchTextContent("Process [vite] started.");
    await expect.element(row("Processes", "vite")).not.toHaveAttribute("data-warn");
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "POST",
        path: "/api/v1/processes/2/start",
    });
});

it("destroys a process only after it is confirmed", async () => {
    const app = await openApp("/processes");
    await row("Processes", "vite").click({ button: "right" });
    await expect.element(pane("vite")).toBeVisible();

    await userEvent.keyboard("{ArrowDown}{ArrowDown}{Enter}");
    await expect.element(pane("vite")).toMatchTextContent("Confirm? Destroy process [vite]?");
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);

    await userEvent.keyboard("{Enter}");
    await expect.element(footer()).toMatchTextContent("Process [vite] destroyed.");
    await expect.element(row("Processes", "vite")).not.toBeInTheDocument();
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "DELETE",
        path: "/api/v1/processes/2",
    });
});

it("profiles an instance and shows what the command printed in a modal", async () => {
    const report = "GET https://charlie-shop.test 200 in 41.20ms\n\nTotal ....... 41.20ms";
    const fetch = window.fetch.bind(window);
    const fetched = vi.spyOn(window, "fetch").mockImplementation((input, init) => {
        // Mounting the annotator also fetches discovery; it must not consume the profile body.
        if (input === "/__orbit/profile?instance=1") {
            return Promise.resolve(Response.json({ ok: true, output: report }));
        }

        return fetch(input, init);
    });

    await openApp("/instances/1");
    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "profile", exact: true }).click();

    await expect.element(page.getByRole("dialog")).toMatchTextContent("Total ....... 41.20ms");
    expect(fetched).toHaveBeenCalledWith("/__orbit/profile?instance=1");

    await userEvent.keyboard("{Escape}");
    await expect.element(page.getByRole("dialog")).not.toBeInTheDocument();
    fetched.mockRestore();
});

it("deploys an instance and follows its operation step by step until it ends", async () => {
    const app = await openApp("/instances/1");
    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "deploy", exact: true }).click();

    await expect.element(footer()).toMatchTextContent("started as operation 1.");
    await expect.element(pane("Deploy")).toMatchTextContent("Operation 1 · succeeded");
    await expect.element(pane("Deploy")).toMatchTextContent("source_preparation");
    await expect.element(pane("Deploy")).toMatchTextContent("cleanup");
    expect(app.gateway.requests).toContainEqual(
        expect.objectContaining({ method: "POST", path: "/api/v1/instances/1/deploy" }),
    );
    expect(
        app.gateway.requests.some(
            (request) =>
                request.method === "GET" &&
                request.path.startsWith("/api/v1/operations/1?after_sequence="),
        ),
    ).toBe(true);
});

it("follows a rollback that another client started in a pane of its own", async () => {
    const app = await openApp("/instances");
    await app.gateway.transport("POST", "/api/v1/instances/1/rollback", { release: "initial" });
    await app.router.navigate({ to: "/$section/$id", params: { section: "instances", id: "1" } });

    await expect.element(pane("Rollback")).toMatchTextContent("Operation 1 · succeeded");
    await expect.element(pane("Rollback")).toMatchTextContent("rollback");
    await expect.element(pane("Rollback")).toMatchTextContent("process_restart");
});

it("shows an instance's Horizon queue by job state and opens a job in Horizon", async () => {
    // A job opens through a real link with a target, so a new tab is the browser's own behaviour.
    const opened: { href: string; target: string }[] = [];
    const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function (
        this: HTMLAnchorElement,
    ) {
        opened.push({ href: this.href, target: this.target });
    });

    await openApp("/instances/1");
    await expect.element(pane("Jobs")).toMatchTextContent("No pending jobs.");
    await expect.element(pane("Queue")).toMatchTextContent("Jobs per minute12");
    await expect
        .element(pane("Queue"))
        .toMatchTextContent("Queue default0 jobs · 0s wait · 3 workers");

    await page.getByRole("tab", { name: "failed 1" }).click();
    await expect.element(row("Jobs", "SendReceipt")).toMatchTextContent("mail server refused");

    await row("Jobs", "SendReceipt").click();
    expect(opened).toEqual([
        { href: "https://charlie-shop.test/horizon/failed/f1", target: "_blank" },
    ]);
    click.mockRestore();
});

it("shows no queue panel for an instance without Horizon", async () => {
    await openApp("/instances/2");
    await expect.element(pane("Application log")).toBeVisible();

    await expect.element(pane("Jobs")).not.toBeInTheDocument();
});

it("lists live UFW on a node and keeps Orbit's own rules without actions", async () => {
    const app = await openApp("/nodes/2/firewall");
    const managed = row("Firewall", "orbit:wireguard-members");

    await expect.element(managed).toMatchTextContent("any on orbit");
    await expect.element(managed).toMatchTextContent("live");
    await expect.element(row("Firewall", "orbit:public-ssh-recovery")).not.toBeInTheDocument();

    // A managed live rule opens nothing and offers no menu; an operator rule still does both.
    await managed.click();
    expect(app.url()).toBe("/nodes/2/firewall");
    await managed.click({ button: "right" });
    await expect.element(page.getByRole("menuitem")).not.toBeInTheDocument();

    await row("Firewall", "https-public").click({ button: "right" });
    await expect.element(page.getByRole("menuitem", { name: "remove" })).toBeVisible();
});

async function completeDemoAnalytics(
    transport: Transport,
    method: "POST" | "DELETE",
    instanceId: number,
): Promise<void> {
    const answer = await transport(method, `/api/v1/instances/${instanceId}/analytics`, {});
    expect(answer.status).toBe(202);
    const { data } = answer.payload as { data: { id: number } };
    await transport("GET", `/api/v1/operations/${data.id}`, undefined);
    await transport("GET", `/api/v1/operations/${data.id}`, undefined);
}

it("starts analytics operations without replacing the observed state or claiming completion", async () => {
    const requests: { method: string; path: string }[] = [];
    const app = await openApp("/instances/1", {
        wrapTransport: (inner) => async (method, path, body, options) => {
            requests.push({ method, path });
            if (
                (method === "POST" || method === "DELETE") &&
                path === "/api/v1/instances/1/analytics"
            ) {
                return {
                    status: 202,
                    payload: {
                        data: {
                            id: method === "POST" ? 789 : 790,
                            kind: method === "POST" ? "analytics.enable" : "analytics.disable",
                            status: "running",
                            input: { instance_id: 1 },
                            result: null,
                        },
                    },
                };
            }

            return inner(method, path, body, options);
        },
    });
    await expect.element(pane("Application log")).toBeVisible();
    await expect.element(pane("Properties")).not.toMatchTextContent("Analytics");
    const observed = queryClient.getQueryData(["instance-analytics", 1]);

    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "enable analytics", exact: true }).click();

    await expect
        .element(footer())
        .toMatchTextContent("Analytics enable for [charlie-shop/dev] started as operation 789.");
    expect(requests).toContainEqual({ method: "POST", path: "/api/v1/instances/1/analytics" });
    expect(queryClient.getQueryData(["instance-analytics", 1])).toEqual(observed);
    await expect.element(pane("Properties")).not.toMatchTextContent("Analytics");
    await expect.element(page.getByRole("dialog")).not.toBeInTheDocument();

    // A later Gateway read reports the native workflow's publication.
    await completeDemoAnalytics(app.gateway.transport, "POST", 1);
    await queryClient.invalidateQueries({ queryKey: ["instance-analytics", 1] });
    await expect
        .element(pane("Properties"))
        .toMatchTextContent("Analyticsanalytics.dev.charlie-shop.test");
    await expect.element(pane("Properties")).toMatchTextContent("Dashboardanalytics.orbit");
    await expect.element(pane("Analytics")).toMatchTextContent("Live visitors");
    await expect.element(pane("Analytics")).toMatchTextContent("2");
    await expect.element(pane("Top pages")).toMatchTextContent("/");
    await expect.element(pane("Top pages")).toMatchTextContent("120");

    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "disable analytics", exact: true }).click();
    await expect
        .element(pane("charlie-shop/dev"))
        .toMatchTextContent("Confirm? Remove every analytics tracking host");
    expect(requests.some((request) => request.method === "DELETE")).toBe(false);

    await userEvent.keyboard("{Enter}");

    await expect
        .element(footer())
        .toMatchTextContent("Analytics disable for [charlie-shop/dev] started as operation 790.");
    expect(requests).toContainEqual({ method: "DELETE", path: "/api/v1/instances/1/analytics" });
    expect(queryClient.getQueryData(["instance-analytics", 1])).toMatchObject({
        instance_id: 1,
        enabled: true,
    });
    await expect.element(pane("Analytics")).toMatchTextContent("Live visitors");
    expect(requests.some((request) => /^\/api\/v1\/operations\/(789|790)/.test(request.path))).toBe(
        false,
    );

    await completeDemoAnalytics(app.gateway.transport, "DELETE", 1);
    await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["instance-analytics", 1] }),
        queryClient.invalidateQueries({ queryKey: ["instance-analytics-stats", 1] }),
    ]);
    await expect.element(pane("Properties")).not.toMatchTextContent("Analytics");
    await expect.element(pane("Analytics")).not.toBeInTheDocument();
});

it("shows a warn analytics panel when stats cannot be read, not zeros", async () => {
    const app = await openApp("/instances");
    await completeDemoAnalytics(app.gateway.transport, "POST", 2);
    await app.router.navigate({ to: "/$section/$id", params: { section: "instances", id: "2" } });

    const analytics = pane("Analytics");
    await expect.element(analytics).toHaveAttribute("data-state", "warn");
    await expect.element(analytics).toMatchTextContent("No Plausible Stats API key is stored");
    await expect.element(analytics).not.toMatchTextContent("Live visitors");
    await expect.element(pane("Top pages")).not.toBeInTheDocument();
});

it("asks before a destructive action and sends nothing until it is confirmed", async () => {
    const app = await openApp("/firewall");
    await row("Firewall", "443/tcp").click({ button: "right" });
    await expect.element(pane("https-public")).toBeVisible();

    await userEvent.keyboard("{Enter}");
    await expect
        .element(pane("https-public"))
        .toMatchTextContent("Confirm? Remove firewall rule [https-public] on node [beast]?");
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);

    await userEvent.keyboard("{Enter}");
    await expect.element(footer()).toMatchTextContent("Firewall rule [https-public] removed.");
    await expect.element(row("Firewall", "443/tcp")).not.toBeInTheDocument();
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "DELETE",
        path: "/api/v1/nodes/2/firewall-rules/https-public",
    });
});

it("cancels a menu with Esc", async () => {
    const app = await openApp("/firewall");
    await row("Firewall", "443/tcp").click({ button: "right" });
    await userEvent.keyboard("{Enter}{Escape}");

    await expect.element(pane("https-public")).not.toBeInTheDocument();
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);
});

it("names the command for an action that has no request", async () => {
    await openApp("/nodes/2");
    await expect.element(pane("Instances on this node")).toBeVisible();

    await userEvent.keyboard("x{ArrowDown}{Enter}");

    await expect.element(footer()).toMatchTextContent("orbit node:ssh beast");
});

it("applies a realtime event to the open screen", async () => {
    await openApp("/");
    await expect.element(row("Needs attention", "vite")).toBeVisible();
    await expect
        .element(page.getByRole("status"))
        .toHaveAccessibleName(/Not connected to the WebSocket\. Lists refresh every 30 seconds/);

    applyEvent(queryClient, {
        type: "process.status",
        id: 2,
        at: "2026-01-01T00:00:00+00:00",
        data: { id: 2, runtime_status: "active" },
    });

    await expect.element(row("Needs attention", "vite")).not.toBeInTheDocument();
    await expect.element(row("Processes", "vite")).toMatchTextContent("active");
});

it("keeps the reason it is not live in the status dot, not in the footer text", async () => {
    const app = await openApp("/");
    await expect
        .element(page.getByRole("status"))
        .toHaveAccessibleName(
            "Not connected to the WebSocket. Lists refresh every 30 seconds, then less often, up to every 5 minutes, and not while the tab is hidden.",
        );

    const refuse: typeof app.gateway.transport = (method, path, body) =>
        path === "/api/v1/realtime"
            ? Promise.resolve({
                  status: 403,
                  payload: {
                      error: { code: "node_access.required", message: "Node access is required." },
                  },
              })
            : app.gateway.transport(method, path, body);
    setTransport(refuse, "demo fleet");
    await connectRealtime(queryClient, new AbortController().signal);

    await expect
        .element(page.getByRole("status"))
        .toHaveAccessibleName(/Not connected to the WebSocket \(Node access is required\.\)/);
    await expect.element(footer()).not.toMatchTextContent("Node access is required.");
});

it("says live updates are paused while realtime is down, and refreshes on a click", async () => {
    const app = await openApp("/");
    const paused = footer().getByRole("button", { name: "live updates paused" });
    await expect.element(paused).toMatchTextContent("live updates paused");

    const before = app.gateway.requests.filter(
        (request) => request.path === "/api/v1/nodes",
    ).length;
    await paused.click();
    await vi.waitFor(() =>
        expect(
            app.gateway.requests.filter((request) => request.path === "/api/v1/nodes").length,
        ).toBeGreaterThan(before),
    );

    setLiveness("live");
    await expect.element(paused).not.toBeInTheDocument();
    setLiveness("polling");
});
