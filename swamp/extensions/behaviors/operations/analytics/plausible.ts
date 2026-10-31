import { z } from "npm:zod@4.4.3";
import { Domain } from "./host-intent.ts";

const Text = z.string().min(1).max(2000).regex(/^[^\r\n\0]+$/);
const Props = z.record(z.string().min(1), Text)
  .refine((props) => Object.keys(props).length <= 3);
const EventData = z.object({ event_name: Text, custom_props: Props.optional() })
  .strict();
const PageData = z.object({
  path: Text.startsWith("/"),
  custom_props: Props.optional(),
}).strict();
const RevenueData = EventData.extend({
  currency: z.string().regex(/^[A-Z]{3}$/),
});
export const Goal = z.discriminatedUnion("goal_type", [
  z.object({ goal_type: z.literal("Goal.CustomEvent"), goal: EventData })
    .strict(),
  z.object({ goal_type: z.literal("Goal.Pageview"), goal: PageData }).strict(),
  z.object({ goal_type: z.literal("Goal.Revenue"), goal: RevenueData })
    .strict(),
]);
export type GoalIntent = z.infer<typeof Goal>;
const Identity = {
  id: z.number().int().positive(),
  display_name: z.string().optional(),
};
const GoalResponse = z.object({
  goals: z.array(z.discriminatedUnion("goal_type", [
    z.object({
      goal_type: z.literal("Goal.CustomEvent"),
      goal: EventData.extend(Identity),
    }),
    z.object({
      goal_type: z.literal("Goal.Pageview"),
      goal: PageData.extend(Identity),
    }),
    z.object({
      goal_type: z.literal("Goal.Revenue"),
      goal: RevenueData.extend(Identity),
    }),
  ])),
});
function goalIdentity(value: GoalIntent) {
  const goal = value.goal;
  return JSON.stringify([
    value.goal_type,
    "event_name" in goal ? goal.event_name : goal.path,
    "currency" in goal ? goal.currency : null,
    Object.entries(goal.custom_props ?? {}).sort(([a], [b]) =>
      a.localeCompare(b)
    ),
  ]);
}

export const Site = z.object({
  domain: Domain,
  previous_domain: z.string().nullable().default(null),
  timezone: z.string().min(1).max(100),
  goals: z.array(Goal).max(100).default([]),
}).strict();
export type SiteIntent = z.input<typeof Site>;
export type Credentials = {
  email: string;
  password: string;
  apiKey: string;
  /** Protected source must prove this token belongs to the admitted site. */
  plugins?: { domain: string; token: string };
};
type Fetch = typeof fetch;

export class PlausibleRefusal extends Error {
  constructor(public code: string) {
    super(code);
  }
}

/** CE 3.2.1 management HTTP. Credentials and response bodies remain transient. */
export class PlausibleCE {
  private cookies = new Map<string, string>();
  private csrf = "";
  constructor(
    private baseUrl: string,
    private credentials: Credentials,
    private request: Fetch = fetch,
  ) {
    const url = new URL(baseUrl);
    if (
      !["http:", "https:"].includes(url.protocol) || url.username ||
      url.password || url.pathname !== "/"
    ) {
      throw new PlausibleRefusal("analytics.address_invalid");
    }
  }

  private async call(
    method: string,
    path: string,
    body?: Record<string, unknown>,
  ): Promise<Response> {
    let response: Response;
    try {
      response = await this.request(new URL(path, this.baseUrl), {
        method,
        redirect: "manual",
        signal: AbortSignal.timeout(15_000),
        headers: {
          accept: "text/html",
          cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join("; "),
          ...(body
            ? { "content-type": "application/json", "x-csrf-token": this.csrf }
            : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
      });
    } catch {
      throw new PlausibleRefusal("analytics.management_unavailable");
    }
    for (const cookie of response.headers.getSetCookie()) {
      const pair = /^([^=;]+)=([^;]*)/.exec(cookie);
      if (pair) this.cookies.set(pair[1], pair[2]);
    }
    if (
      [401, 403].includes(response.status) ||
      (path !== "/logout" &&
        (response.headers.get("location") ?? "").startsWith("/login"))
    ) {
      await response.body?.cancel();
      throw new PlausibleRefusal("analytics.management_unauthorized");
    }
    return response;
  }

  private token(html: string) {
    const match = /<meta\s+name="csrf-token"\s+content="([^"]+)"/i.exec(html) ??
      /<input[^>]*name="_csrf_token"[^>]*value="([^"]+)"/i.exec(html);
    if (!match) throw new PlausibleRefusal("analytics.management_unreadable");
    this.csrf = match[1];
  }

  async login() {
    const form = await this.call("GET", "/login");
    if (form.status !== 200) {
      throw new PlausibleRefusal("analytics.management_unavailable");
    }
    this.token(await form.text());
    const login = await this.call("POST", "/login", {
      email: this.credentials.email,
      password: this.credentials.password,
    });
    const destination = login.headers.get("location");
    await login.body?.cancel();
    if (login.status !== 302 || destination !== "/sites") {
      throw new PlausibleRefusal("analytics.management_unauthorized");
    }
    const page = await this.call("GET", "/sites/new");
    if (page.status !== 200) {
      throw new PlausibleRefusal("analytics.management_unauthorized");
    }
    this.token(await page.text());
  }

  async inspect(domain: string): Promise<{ timezone: string } | null> {
    const response = await this.call(
      "GET",
      `/${encodeURIComponent(domain)}/settings/general`,
    );
    if (response.status === 404) {
      await response.body?.cancel();
      return null;
    }
    if (
      response.status === 302 && response.headers.get("location") === "/sites"
    ) {
      await response.body?.cancel();
      return null;
    }
    if (response.status !== 200) {
      await response.body?.cancel();
      throw new PlausibleRefusal("analytics.management_unreadable");
    }
    const html = await response.text();
    this.token(html);
    const select =
      /<select[^>]*name="site\[timezone\]"[^>]*>([\s\S]*?)<\/select>/i.exec(
        html,
      );
    const option = select &&
      /<option\b(?=[^>]*\bselected(?:="selected")?)(?=[^>]*\bvalue="([^"]+)")[^>]*>/i
        .exec(select[1]);
    if (!option) throw new PlausibleRefusal("analytics.management_unreadable");
    return { timezone: option[1] };
  }

  async converge(raw: SiteIntent) {
    const site = Site.parse(raw);
    if (
      site.goals.length && (
        this.credentials.plugins?.domain !== site.domain ||
        !this.credentials.plugins.token ||
        !/^[\x21-\x7e]+$/.test(this.credentials.plugins.token)
      )
    ) throw new PlausibleRefusal("analytics.goals_credentials_missing");
    let current = await this.inspect(site.domain);
    let domain = site.domain;
    if (
      !current && site.previous_domain && site.previous_domain !== site.domain
    ) {
      domain = Site.shape.domain.parse(site.previous_domain);
      current = await this.inspect(domain);
    }
    if (!current) {
      const result = await this.call("POST", "/sites", {
        site: { domain: site.domain, timezone: site.timezone },
      });
      const location = result.headers.get("location") ?? "";
      await result.body?.cancel();
      if (
        result.status !== 302 ||
        !location.startsWith(`/${encodeURIComponent(site.domain)}/installation`)
      ) {
        // A lost/racing create is observed before native resume tries it again.
        throw new PlausibleRefusal("analytics.site_create_failed");
      }
    } else if (domain !== site.domain || current.timezone !== site.timezone) {
      const result = await this.call(
        "PUT",
        `/${encodeURIComponent(domain)}/settings`,
        { site: { domain: site.domain, timezone: site.timezone } },
      );
      const location = result.headers.get("location");
      await result.body?.cancel();
      if (
        result.status !== 302 ||
        location !== `/${encodeURIComponent(site.domain)}/settings/general`
      ) {
        throw new PlausibleRefusal("analytics.site_update_failed");
      }
    }
    const observed = await this.inspect(site.domain);
    if (observed?.timezone !== site.timezone) {
      throw new PlausibleRefusal("analytics.site_unproven");
    }
    // CE 3.2.1's bulk schema uses Funnel.Const.max_steps() = 8.
    for (let offset = 0; offset < site.goals.length; offset += 8) {
      await this.convergeGoals(site.goals.slice(offset, offset + 8));
    }
    let response: Response;
    try {
      response = await this.request(new URL("/api/v2/query", this.baseUrl), {
        method: "POST",
        redirect: "manual",
        signal: AbortSignal.timeout(15_000),
        headers: {
          authorization: `Bearer ${this.credentials.apiKey}`,
          "content-type": "application/json",
        },
        body: JSON.stringify({
          site_id: site.domain,
          metrics: ["visitors"],
          date_range: "7d",
        }),
      });
    } catch {
      throw new PlausibleRefusal("analytics.stats_unreadable");
    }
    await response.body?.cancel();
    if (response.status !== 200) {
      throw new PlausibleRefusal("analytics.stats_unauthorized");
    }
    return {
      domain: site.domain,
      timezone: site.timezone,
      readable: true as const,
    };
  }

  private async convergeGoals(goals: GoalIntent[]) {
    let response: Response;
    try {
      response = await this.request(
        new URL("/api/plugins/v1/goals", this.baseUrl),
        {
          method: "PUT",
          redirect: "manual",
          signal: AbortSignal.timeout(15_000),
          headers: {
            accept: "application/json",
            "content-type": "application/json",
            authorization: `Basic ${
              btoa(`:${this.credentials.plugins!.token}`)
            }`,
          },
          body: JSON.stringify({ goals }),
        },
      );
    } catch {
      throw new PlausibleRefusal("analytics.goals_unavailable");
    }
    if (response.status !== 201) {
      await response.body?.cancel();
      throw new PlausibleRefusal(
        [401, 403].includes(response.status)
          ? "analytics.goals_unauthorized"
          : "analytics.goals_update_failed",
      );
    }
    let body: unknown;
    try {
      body = await response.json();
    } catch {
      throw new PlausibleRefusal("analytics.goals_unproven");
    }
    const observed = GoalResponse.safeParse(body);
    if (
      !observed.success ||
      JSON.stringify(observed.data.goals.map(goalIdentity).sort()) !==
        JSON.stringify(goals.map(goalIdentity).sort())
    ) throw new PlausibleRefusal("analytics.goals_unproven");
  }

  /** Provider retention policy: keep the site and goals. Successful Gateway projection removes the binding. */
  detach() {
    return { detached: true as const };
  }

  async logout() {
    try {
      const result = await this.call("GET", "/logout");
      await result.body?.cancel();
    } finally {
      this.cookies.clear();
      this.csrf = "";
    }
  }
}
