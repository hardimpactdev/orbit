import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { footer, openApp, pane } from "./app";

const field = (label: string) =>
    page
        .getByText(label, { exact: true })
        .element()
        .parentElement?.querySelector("input") as HTMLInputElement;

it("opens from the Nodes list with c and refuses an empty form", async () => {
    const app = await openApp("/nodes");
    await expect.element(pane("Tracked nodes")).toBeVisible();

    await userEvent.keyboard("c");
    await expect.poll(app.url).toBe("/nodes/create");

    await page.getByRole("button", { name: /Create node/ }).click();
    await expect.element(pane("New node")).toMatchTextContent("⚠ Required.");
    expect(app.gateway.requests.some((request) => request.method === "POST")).toBe(false);
});

it("applies the node:add rules to each field", async () => {
    await openApp("/nodes/create");
    await userEvent.fill(field("Node name"), "Not Valid");
    await userEvent.fill(field("SSH port"), "70000");
    await userEvent.fill(field("Host key fingerprint"), "not-approved");
    await page.getByRole("button", { name: /Create node/ }).click();

    await expect
        .element(pane("New node"))
        .toMatchTextContent("Use lowercase letters, digits, and dashes.");
    await expect
        .element(pane("New node"))
        .toMatchTextContent("A port is a number from 1 to 65535.");
    await expect
        .element(pane("New node"))
        .toMatchTextContent("Enter the approved SHA256 SSH host key fingerprint.");
});

it("shows the Gateway's refusal, then creates the node and opens it", async () => {
    const app = await openApp("/nodes/create");
    await userEvent.fill(field("Node name"), "spare");
    await userEvent.fill(field("Host key fingerprint"), `SHA256:${"A".repeat(43)}`);
    await page.getByRole("button", { name: /Create node/ }).click();
    await expect.element(pane("New node")).toMatchTextContent("An app-dev TLD is required");

    await userEvent.fill(field("TLD for its domains"), "spare.test");
    await page.getByRole("button", { name: /Create node/ }).click();

    await expect.poll(app.url).toBe("/nodes/5");
    await expect.element(footer()).toMatchTextContent("Node [spare] is active.");
    expect(app.gateway.requests.findLast((request) => request.method === "POST")?.body).toEqual({
        name: "spare",
        roles: ["app-dev"],
        public_ssh_port: 22,
        user: "root",
        host_key_fingerprint: `SHA256:${"A".repeat(43)}`,
        tld: "spare.test",
    });
});

it("leaves the form with Esc", async () => {
    const app = await openApp("/nodes");
    await expect.element(pane("Tracked nodes")).toBeVisible();
    await userEvent.keyboard("c");
    await expect.poll(app.url).toBe("/nodes/create");

    await userEvent.keyboard("{Escape}");
    await expect.poll(app.url).toBe("/nodes");
});
