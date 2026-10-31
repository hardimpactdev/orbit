import { expect, it } from "vite-plus/test";
import { queryClient } from "../../src/api/queryClient";
import { applyEvent } from "../../src/realtime/apply";
import { openApp, pane, row } from "./app";

it.each(["/nodes", "/"])(
    "keeps a roleless Node with a tracked exporter and meters in Tracked nodes on %s",
    async (path) => {
        await openApp(path);
        await expect.element(row("Tracked nodes", "beast")).toBeVisible();
        applyEvent(queryClient, {
            type: "node.updated",
            id: 2,
            at: "2026-10-30T13:00:00+00:00",
            data: { id: 2, name: "roleless-beast", roles: [] },
        });
        await expect.element(row("Tracked nodes", "roleless-beast")).toMatchTextContent("33%");
        await expect
            .element(pane("Other nodes").getByText("roleless-beast", { exact: true }))
            .not.toBeInTheDocument();
    },
);

it.each([
    [false, "active"],
    [true, "inactive"],
    [true, "unknown"],
    [true, "drifted"],
])(
    "puts a role-bearing Node with exporter desired=%s actual=%s in Other nodes despite old samples",
    async (desired, actual) => {
        await openApp("/nodes", {
            wrapTransport: (inner) => (method, path, body) =>
                path === "/api/v1/metrics/status"
                    ? Promise.resolve({
                          status: 200,
                          payload: {
                              data: {
                                  exporters: [
                                      { id: 1, desired: true, actual: "active" },
                                      { id: 2, desired, actual },
                                  ],
                              },
                          },
                      })
                    : inner(method, path, body),
        });
        await expect.element(row("Tracked nodes", "gateway")).toBeVisible();
        await expect.element(row("Other nodes", "beast")).toBeVisible();
        await expect
            .element(pane("Tracked nodes").getByText("beast", { exact: true }))
            .not.toBeInTheDocument();
    },
);

it("keeps an unexported task VM in Other nodes and shows missing tracked readings as dashes", async () => {
    await openApp("/nodes");
    await expect.element(row("Tracked nodes", "app-prod")).toMatchTextContent(/—.*—.*—.*—/);
    applyEvent(queryClient, {
        type: "node.created",
        id: 175,
        at: "2026-10-30T13:00:00+00:00",
        data: {
            id: 175,
            name: "tvm-175",
            status: "active",
            platform: "linux",
            roles: ["app-dev"],
            wireguard_ip: "10.44.0.175",
            architecture: "x86_64",
        },
    });
    await expect.element(row("Other nodes", "tvm-175")).toBeVisible();
    await expect
        .element(pane("Tracked nodes").getByText("tvm-175", { exact: true }))
        .not.toBeInTheDocument();
});
