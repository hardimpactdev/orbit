import { expect, it } from "vite-plus/test";
import { openApp, pane, row } from "./app";
import deployments from "../../fixtures/fleet/instance-deployment-list.1.json";

it("shows only the latest 25 deployment records without removing stored history", async () => {
    const app = await openApp("/instances/1", {
        wrapTransport(inner) {
            return (method, path, body, options) =>
                path === "/api/v1/instances/1/deployments"
                    ? Promise.resolve({
                          status: 200,
                          payload: {
                              data: Array.from({ length: 40 }, (_, index) => ({
                                  ...deployments.body.data[0],
                                  id: 100 - index,
                                  release: `release-${String(index + 1).padStart(2, "0")}`,
                              })),
                          },
                      })
                    : inner(method, path, body, options);
        },
    });
    await expect.element(row("Deployments", "release-01")).toBeVisible();
    await expect.element(row("Deployments", "release-25")).toBeInTheDocument();
    await expect.element(row("Deployments", "release-26")).not.toBeInTheDocument();
    await expect.element(pane("Deployments")).toHaveTextContent("Latest 25");
    expect(pane("Deployments").element().querySelectorAll('[role="row"]')).toHaveLength(26);
    expect(app.gateway.requests.every((request) => request.method === "GET")).toBe(true);
});

it("shows table query failures instead of saying the database has no tables", async () => {
    await openApp("/databases/1", {
        wrapTransport(inner) {
            return (method, path, body, options) =>
                path.endsWith("/charlie-shop/tables")
                    ? Promise.resolve({
                          status: 422,
                          payload: {
                              error: {
                                  code: "database.query_failed",
                                  message: "Database table query failed.",
                              },
                          },
                      })
                    : inner(method, path, body, options);
        },
    });
    await expect
        .element(pane("Tables"))
        .toHaveTextContent("Could not load tables: Database table query failed.");
    await expect.element(pane("Tables")).not.toHaveTextContent("No tables.");
});

it("shows returned table names and distinguishes a successful empty read", async () => {
    await openApp("/databases/1");
    await expect.element(row("Tables", "products")).toBeVisible();
    await openApp("/databases/2");
    await expect.element(pane("Tables")).toHaveTextContent("No tables.");
});
