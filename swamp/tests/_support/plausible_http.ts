/** Disposable CE HTTP contract. All requests stay on loopback. */
import { createServer } from "node:http";
import type { AddressInfo } from "node:net";
export class PlausibleFixture {
    sites: Record<string, string> = {};
    calls: [string, string][] = [];
    fail = "";
    failPaths = new Set<string>();
    malformedPaths = new Set<string>();
    badGoals = false;
    password = "provider-password-938725";
    apiKey = "provider-api-key-736529";
    plugin = "provider-plugin-824235";
    cookie = "provider-cookie-824369";
    server = createServer(async (incoming, outgoing) => {
        const path = decodeURIComponent(incoming.url!);
        if (this.malformedPaths.has(path)) {
            this.calls.push([incoming.method!, path]);
            outgoing.socket!.end(this.password + "\r\n");
            return;
        }
        const chunks: Uint8Array[] = [];
        for await (const chunk of incoming) chunks.push(chunk);
        const body = new Blob(chunks.map((chunk) => new Uint8Array(chunk)))
            .stream();
        const request = new Request(this.url + incoming.url, {
            method: incoming.method,
            headers: incoming.headers as Record<string, string>,
            ...(["GET", "HEAD"].includes(incoming.method!)
                ? {}
                : { body, duplex: "half" }),
        });
        const response = await this.handle(request);
        outgoing.writeHead(
            response.status,
            Object.fromEntries(response.headers),
        );
        outgoing.end(new Uint8Array(await response.arrayBuffer()));
    });
    static async create() {
        const fixture = new PlausibleFixture();
        await new Promise<void>((resolve) =>
            fixture.server.listen(0, "127.0.0.1", resolve)
        );
        return fixture;
    }
    private async handle(request: Request) {
        const path = decodeURIComponent(new URL(request.url).pathname),
            method = request.method;
        this.calls.push([method, path]);
        const raw = await request.text(), data = raw ? JSON.parse(raw) : {};
        let status = 200, headers: Record<string, string> = {}, body = "";
        const form = '<meta name="csrf-token" content="private-csrf-826495">';
        if (
            path.startsWith("/api/") &&
            request.headers.get("Accept") !== "application/json"
        ) status = 406;
        else if (
            !["/login", "/logout"].includes(path) &&
            !path.startsWith("/api/") &&
            request.headers.get("Cookie") !== "session=" + this.cookie
        ) status = 401;
        else if (
            ["POST", "PUT"].includes(method) && !path.startsWith("/api/") &&
            request.headers.get("X-CSRF-Token") !== "private-csrf-826495"
        ) status = 403;
        else if (
            this.fail && path.includes(this.fail) || this.failPaths.has(path)
        ) {
            status = 503;
            body = this.password;
        } else if (
            ["/login", "/sites/new"].includes(path) && method === "GET"
        ) body = form;
        else if (path === "/login") {
            if (data.password !== this.password) status = 401;
            else {
                status = 302;
                headers = {
                    Location: "/sites",
                    "Set-Cookie": `session=${this.cookie}; Path=/`,
                };
            }
        } else if (path === "/logout") {
            status = 302;
            headers = {
                Location: "/login",
                "Set-Cookie": "session=; Max-Age=0; Path=/",
            };
        } else if (path === "/api/v2/query") {
            status =
                request.headers.get("Authorization") === "Bearer " + this.apiKey
                    ? 200
                    : 401;
        } else if (path === "/api/plugins/v1/goals") {
            status = request.headers.get("Authorization") ===
                    "Basic " + btoa(":" + this.plugin)
                ? 201
                : 401;
            body = JSON.stringify({
                goals: this.badGoals
                    ? []
                    : data.goals.map((row: { goal: object }, i: number) => ({
                        ...row,
                        goal: { ...row.goal, id: i + 1 },
                    })),
            });
        } else if (path === "/sites" && method === "POST") {
            this.sites[data.site.domain] = data.site.timezone;
            status = 302;
            headers = { Location: `/${data.site.domain}/installation` };
        } else if (path.endsWith("/settings/general")) {
            const domain = path.split("/")[1];
            if (!(domain in this.sites)) status = 404;
            else {body = form +
                    `<select name="site[timezone]"><option value="${
                        this.sites[domain]
                    }" selected>UTC</option></select>`;}
        } else if (path.endsWith("/settings") && method === "PUT") {
            delete this.sites[path.split("/")[1]];
            this.sites[data.site.domain] = data.site.timezone;
            status = 302;
            headers = { Location: `/${data.site.domain}/settings/general` };
        } else status = 404;
        return new Response(body, { status, headers });
    }
    get url() {
        return `http://127.0.0.1:${
            (this.server.address() as AddressInfo).port
        }`;
    }
    get secrets() {
        return [
            this.password,
            this.apiKey,
            this.plugin,
            this.cookie,
            "private-csrf-826495",
        ];
    }
    get credentials() {
        return {
            email: "fixture@example.test",
            password: this.password,
            apiKey: this.apiKey,
            plugins: { domain: "shop.test", token: this.plugin },
        };
    }
    count(method: string, path: string) {
        return this.calls.filter((row) => row[0] === method && row[1] === path)
            .length;
    }
    async [Symbol.asyncDispose]() {
        const closed = new Promise<void>((resolve, reject) =>
            this.server.close((error) => error ? reject(error) : resolve())
        );
        this.server.closeAllConnections();
        await closed;
    }
}
