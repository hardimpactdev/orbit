import { expect, it } from "vite-plus/test";
import { openApp, pane, row } from "./app";
import deployments from "../../fixtures/fleet/instance-deployment-list.1.json";
import tables from "../../fixtures/fleet/database-tables.charlie-shop.json";

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
    await expect.element(pane("Deployments")).toMatchTextContent("Latest 25");
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
        .toMatchTextContent("Could not load tables: Database table query failed.");
    await expect.element(pane("Tables")).not.toMatchTextContent("No tables.");
});

it("shows returned table names and distinguishes a successful empty read", async () => {
    await openApp("/databases/1");
    await expect.element(row("Tables", "products")).toBeVisible();
    await openApp("/databases/2");
    await expect.element(pane("Tables")).toMatchTextContent("No tables.");
});

it("follows an accepted table inspection without reporting an empty database while it runs", async () => {
    let complete!: (answer: { status: number; payload: unknown }) => void;
    const completed = new Promise<{ status: number; payload: unknown }>((resolve) => {
        complete = resolve;
    });
    await openApp("/databases/1", {
        wrapTransport(inner) {
            return (method, path, body, options) => {
                if (path.endsWith("/charlie-shop/tables")) {
                    return Promise.resolve({
                        status: 202,
                        payload: { data: { ...tables.body.data, status: "queued", result: null } },
                    });
                }
                return path === "/api/v1/operations/1"
                    ? completed
                    : inner(method, path, body, options);
            };
        },
    });
    await expect.element(pane("Tables")).toMatchTextContent("Loading…");
    await expect.element(pane("Tables")).not.toMatchTextContent("No tables.");
    complete({ status: 200, payload: tables.body });
    await expect.element(row("Tables", "products")).toBeVisible();
});

it("shows a native table inspection failure instead of an empty database", async () => {
    await openApp("/databases/1", {
        wrapTransport(inner) {
            return (method, path, body, options) =>
                path.endsWith("/charlie-shop/tables")
                    ? Promise.resolve({
                          status: 202,
                          payload: {
                              data: {
                                  ...tables.body.data,
                                  status: "failed",
                                  result: null,
                                  error_code: "database.query_failed",
                              },
                          },
                      })
                    : inner(method, path, body, options);
        },
    });
    await expect
        .element(pane("Tables"))
        .toMatchTextContent("Could not load tables: database.query_failed");
    await expect.element(pane("Tables")).not.toMatchTextContent("No tables.");
});

it("shows the Swamp origin and caller Node name in deployment history", async () => {
    await openApp("/instances/1/deployments/1");

    await expect.element(pane("Properties")).toMatchTextContent("Triggered byswamp:auto-deploy");
    await expect.element(pane("Properties")).toMatchTextContent("Caller Nodebeast");
});
