import { z } from "npm:zod@4.4.3";
import { Site } from "../analytics/plausible.ts";

/** Reconciles one Plausible CE site and closes its management session, from Node 209 over HTTP. */

export const Credentials = z.object({
    email: z.string().min(1),
    password: z.string().min(1),
    apiKey: z.string().min(1),
    plugins: z.object({ domain: z.string(), token: z.string() }).partial()
        .optional(),
}).passthrough();

const Limit = 2 * 1024 * 1024;

/** A refusal carries a public code; its detail names only the provider action and HTTP status. */
export class Refusal extends Error {
    constructor(readonly code: string, readonly detail: string) {
        super(code);
    }
}

function expect(condition: unknown, code: string, detail: string) {
    if (!condition) throw new Refusal(code, detail);
}

const path = (domain: string) => "/" + encodeURIComponent(domain);

type Reply = { status: number; location: string; body: string };

/** A memory-only session: cookies and the CSRF token never leave this object. */
class Session {
    readonly cookies = new Map<string, string>();
    csrf = "";
    constructor(readonly base: string) {}

    async call(
        method: string,
        target: string,
        body?: unknown,
        authorization?: string,
    ): Promise<Reply> {
        const context = `Plausible ${method} ${target}`;
        const headers: Record<string, string> = {
            accept: authorization ? "application/json" : "text/html",
        };
        if (body !== undefined) {
            headers["content-type"] = "application/json";
            if (!authorization) headers["x-csrf-token"] = this.csrf;
        }
        if (authorization) headers.authorization = authorization;
        if (this.cookies.size) {
            headers.cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`)
                .join("; ");
        }
        let reply: Reply;
        try {
            const response = await fetch(
                this.base.replace(/\/$/, "") + target,
                {
                    method,
                    headers,
                    body: body === undefined ? undefined : JSON.stringify(body),
                    redirect: "manual",
                    signal: AbortSignal.timeout(15000),
                },
            );
            for (const cookie of response.headers.getSetCookie()) {
                const [pair, ...attributes] = cookie.split(";");
                const at = pair.indexOf("=");
                const name = pair.slice(0, at).trim(),
                    value = pair.slice(at + 1);
                const expired = value === "" ||
                    attributes.some((a) => /^\s*max-age=0\s*$/i.test(a));
                if (expired) this.cookies.delete(name);
                else this.cookies.set(name, value);
            }
            const content = new Uint8Array(await response.arrayBuffer());
            expect(
                content.length <= Limit,
                "analytics.management_unreadable",
                context + ": response exceeds 2 MiB.",
            );
            reply = {
                status: response.status,
                location: response.headers.get("location") ?? "",
                body: new TextDecoder("utf-8", { fatal: true }).decode(content),
            };
        } catch (error) {
            if (error instanceof Refusal) throw error;
            throw new Refusal(
                "analytics.management_unavailable",
                context + ": response unavailable or unreadable.",
            );
        }
        expect(
            ![401, 403].includes(reply.status) &&
                !(target !== "/logout" && reply.location.startsWith("/login")),
            "analytics.management_unauthorized",
            context + `: authentication refused (HTTP ${reply.status}).`,
        );
        return reply;
    }

    token(body: string) {
        const match =
            /<meta\s+name="csrf-token"\s+content="([^"]+)"/i.exec(body) ??
                /<input[^>]*name="_csrf_token"[^>]*value="([^"]+)"/i.exec(body);
        expect(
            match,
            "analytics.management_unreadable",
            "Plausible management page has no CSRF token.",
        );
        this.csrf = match![1];
    }

    async inspect(domain: string): Promise<string | null> {
        const { status, location, body } = await this.call(
            "GET",
            path(domain) + "/settings/general",
        );
        if (status === 404 || (status === 302 && location === "/sites")) {
            return null;
        }
        expect(
            status === 200,
            "analytics.management_unreadable",
            `Plausible site inspection returned HTTP ${status}; expected 200.`,
        );
        this.token(body);
        const select =
            /<select[^>]*name="site\[timezone\]"[^>]*>([\s\S]*?)<\/select>/i
                .exec(body);
        const option = select &&
            /<option\b(?=[^>]*\bselected(?:="selected")?)(?=[^>]*\bvalue="([^"]+)")[^>]*>/i
                .exec(select[1]);
        expect(
            option,
            "analytics.management_unreadable",
            "Plausible site inspection has no selected timezone.",
        );
        return option![1];
    }
}

type Goal = { goal_type: string; goal: Record<string, unknown> };
const identity = ({ goal_type, goal }: Goal) =>
    JSON.stringify([
        goal_type,
        goal.event_name ?? goal.path ?? null,
        goal.currency ?? null,
        Object.entries((goal.custom_props ?? {}) as Record<string, unknown>)
            .sort(),
    ]);

async function goals(
    session: Session,
    site: z.infer<typeof Site>,
    credentials: z.infer<typeof Credentials>,
) {
    if (!site.goals.length) return;
    const token = credentials.plugins?.token ?? "";
    expect(
        credentials.plugins?.domain === site.domain && /^[!-~]+$/.test(token),
        "analytics.goals_credentials_missing",
        "Plausible goals require the site's Plugins API credential.",
    );
    const authorization = "Basic " + btoa(":" + token);
    // CE accepts at most eight goals per request; this is one site's action group.
    for (let offset = 0; offset < site.goals.length; offset += 8) {
        const batch = site.goals.slice(offset, offset + 8);
        const { status, body } = await session.call(
            "PUT",
            "/api/plugins/v1/goals",
            { goals: batch },
            authorization,
        );
        expect(
            status === 201,
            "analytics.goals_update_failed",
            `Plausible goals returned HTTP ${status}; expected 201.`,
        );
        let observed: Goal[];
        try {
            observed = JSON.parse(body).goals;
            if (
                !observed.every((row) =>
                    Number.isInteger(row.goal.id) && (row.goal.id as number) > 0
                )
            ) throw new Error();
        } catch {
            throw new Refusal(
                "analytics.goals_unproven",
                "Plausible goals receipt is invalid.",
            );
        }
        expect(
            JSON.stringify(observed.map(identity).sort()) ===
                JSON.stringify((batch as Goal[]).map(identity).sort()),
            "analytics.goals_unproven",
            "Plausible goals receipt differs from the admitted batch.",
        );
    }
}

/** Log in, create or update the site, set its goals, prove the Stats API, and always log out. */
export async function reconcile(
    baseUrl: string,
    rawSite: z.input<typeof Site>,
    rawCredentials: unknown,
) {
    const url = new URL(baseUrl);
    expect(
        ["http:", "https:"].includes(url.protocol) && url.hostname &&
            !url.username && !url.password &&
            ["", "/"].includes(url.pathname) &&
            !url.search && !url.hash,
        "analytics.address_invalid",
        "Plausible base URL must be an HTTP(S) origin.",
    );
    const site = Site.parse(rawSite),
        credentials = Credentials.parse(rawCredentials);
    const session = new Session(baseUrl);
    let primary: unknown = null;
    try {
        let reply = await session.call("GET", "/login");
        expect(
            reply.status === 200,
            "analytics.management_unavailable",
            `Plausible login form returned HTTP ${reply.status}; expected 200.`,
        );
        session.token(reply.body);
        reply = await session.call("POST", "/login", {
            email: credentials.email,
            password: credentials.password,
        });
        expect(
            reply.status === 302 && reply.location === "/sites",
            "analytics.management_unauthorized",
            `Plausible login did not open the site list (HTTP ${reply.status}).`,
        );
        let domain = site.domain;
        let current = await session.inspect(domain);
        if (
            current === null && site.previous_domain &&
            site.previous_domain !== domain
        ) {
            domain = site.previous_domain;
            current = await session.inspect(domain);
        }
        const fields = {
            site: { domain: site.domain, timezone: site.timezone },
        };
        if (current === null) {
            reply = await session.call("GET", "/sites/new");
            expect(
                reply.status === 200,
                "analytics.management_unreadable",
                `Plausible new-site form returned HTTP ${reply.status}; expected 200.`,
            );
            session.token(reply.body);
            reply = await session.call("POST", "/sites", fields);
            expect(
                reply.status === 302 &&
                    reply.location.startsWith(
                        path(site.domain) + "/installation",
                    ),
                "analytics.site_create_failed",
                `Plausible site creation did not reach installation (HTTP ${reply.status}).`,
            );
        } else if (domain !== site.domain || current !== site.timezone) {
            reply = await session.call(
                "PUT",
                path(domain) + "/settings",
                fields,
            );
            expect(
                reply.status === 302 &&
                    reply.location === path(site.domain) + "/settings/general",
                "analytics.site_update_failed",
                `Plausible site update did not reach settings (HTTP ${reply.status}).`,
            );
        }
        await goals(session, site, credentials);
        expect(
            await session.inspect(site.domain) === site.timezone,
            "analytics.site_unproven",
            "Plausible site timezone differs from admitted intent.",
        );
        reply = await session.call(
            "POST",
            "/api/v2/query",
            { site_id: site.domain, metrics: ["visitors"], date_range: "7d" },
            "Bearer " + credentials.apiKey,
        );
        expect(
            reply.status === 200,
            "analytics.stats_unauthorized",
            `Plausible Stats API returned HTTP ${reply.status}; expected 200.`,
        );
    } catch (error) {
        primary = error;
        throw error;
    } finally {
        if (session.cookies.size) {
            try {
                const { status } = await session.call("GET", "/logout");
                expect(
                    [200, 302].includes(status),
                    "analytics.logout_failed",
                    `Plausible logout returned HTTP ${status}; expected 200 or 302.`,
                );
            } catch (error) {
                if (primary === null) throw error;
                if (error instanceof Refusal) {
                    console.error("Cleanup: " + error.detail);
                }
            }
        }
    }
    return {
        ready: true as const,
        domain: site.domain,
        timezone: site.timezone,
        readable: true as const,
    };
}
