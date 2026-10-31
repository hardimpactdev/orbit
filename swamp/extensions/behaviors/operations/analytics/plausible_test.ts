import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  Goal,
  type GoalIntent,
  PlausibleCE,
  PlausibleRefusal,
} from "./plausible.ts";

function fixture(
  sites: Record<string, string> = {},
  plugins?: { domain: string; token: string },
) {
  const goals: GoalIntent[] = [];
  let goalResponse: Response | undefined;
  const calls: {
    method: string;
    path: string;
    body?: Record<string, unknown>;
  }[] = [];
  let loggedIn = false;
  let denyStats = false;
  const page = (timezone?: string) =>
    `<meta name="csrf-token" content="private-csrf">${
      timezone
        ? `<select name="site[timezone]"><option selected="selected" value="${timezone}">timezone</option></select>`
        : ""
    }`;
  const fetcher: typeof fetch = (input, init) => {
    const url = new URL(String(input));
    const path = decodeURIComponent(url.pathname);
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    const method = init?.method ?? "GET";
    calls.push({ method, path, body });
    if (path === "/login" && method === "GET") {
      return Promise.resolve(
        new Response(page(), {
          headers: { "set-cookie": "session=private-cookie; HttpOnly" },
        }),
      );
    }
    if (path === "/login" && method === "POST") {
      if (
        new Headers(init?.headers).get("cookie") !== "session=private-cookie" ||
        new Headers(init?.headers).get("x-csrf-token") !== "private-csrf"
      ) throw new Error("Missing auth context");
      loggedIn = true;
      return Promise.resolve(
        new Response(null, { status: 302, headers: { location: "/sites" } }),
      );
    }
    if (path === "/logout") {
      loggedIn = false;
      return Promise.resolve(
        new Response(null, { status: 302, headers: { location: "/login" } }),
      );
    }
    if (path === "/api/plugins/v1/goals") {
      assertEquals(method, "PUT");
      assertEquals((body.goals as unknown[]).length <= 8, true);
      assertEquals(
        new Headers(init?.headers).get("authorization"),
        `Basic ${btoa(":private-plugin-token")}`,
      );
      if (goalResponse) return Promise.resolve(goalResponse);
      for (const goal of body.goals as GoalIntent[]) {
        if (
          !goals.some((current) =>
            JSON.stringify(current) === JSON.stringify(goal)
          )
        ) {
          goals.push(goal);
        }
      }
      return Promise.resolve(
        Response.json({
          goals: (body.goals as GoalIntent[]).map((value, index) => ({
            ...value,
            goal: { ...value.goal, id: index + 1, display_name: "Goal" },
          })),
        }, { status: 201 }),
      );
    }
    if (path === "/api/v2/query") {
      return Promise.resolve(
        new Response("{}", { status: denyStats ? 401 : 200 }),
      );
    }
    if (!loggedIn) {
      return Promise.resolve(
        new Response(null, { status: 302, headers: { location: "/login" } }),
      );
    }
    if (path === "/sites/new") return Promise.resolve(new Response(page()));
    if (path === "/sites" && method === "POST") {
      const site = body.site as { domain: string; timezone: string };
      sites[site.domain] = site.timezone;
      return Promise.resolve(
        new Response(null, {
          status: 302,
          headers: {
            location: `/${site.domain}/installation?site_created=true`,
          },
        }),
      );
    }
    const settings = /^\/(.+)\/settings\/general$/.exec(path);
    if (settings) {
      return Promise.resolve(
        sites[settings[1]]
          ? new Response(page(sites[settings[1]]))
          : new Response("Missing", { status: 404 }),
      );
    }
    const update = /^\/(.+)\/settings$/.exec(path);
    if (update && method === "PUT") {
      const site = body.site as { domain: string; timezone: string };
      delete sites[update[1]];
      sites[site.domain] = site.timezone;
      return Promise.resolve(
        new Response(null, {
          status: 302,
          headers: { location: `/${site.domain}/settings/general` },
        }),
      );
    }
    throw new Error("Unexpected endpoint");
  };
  return {
    calls,
    sites,
    goals,
    goalResponse: (response: Response) => {
      goalResponse = response;
    },
    fetcher,
    deny: () => {
      denyStats = true;
    },
    client: new PlausibleCE("http://10.44.0.3:8000", {
      email: "automation@example.test",
      password: "private-password",
      apiKey: "private-key",
      plugins,
    }, fetcher),
  };
}

Deno.test("CE creates a missing site and a repeated converge does not create it twice", async () => {
  const f = fixture();
  await f.client.login();
  const site = {
    domain: "example.test",
    previous_domain: null,
    timezone: "Europe/Amsterdam",
  };
  await f.client.converge(site);
  await f.client.converge(site);
  await f.client.logout();
  assertEquals(f.sites, { "example.test": "Europe/Amsterdam" });
  assertEquals(
    f.calls.filter((c) => c.path === "/sites" && c.method === "POST").length,
    1,
  );
});
Deno.test("CE updates timezone and renames the existing site through its changeset endpoint", async () => {
  const f = fixture({ "before.test": "UTC" });
  await f.client.login();
  await f.client.converge({
    domain: "after.test",
    previous_domain: "before.test",
    timezone: "Europe/Amsterdam",
  });
  assertEquals(f.sites, { "after.test": "Europe/Amsterdam" });
  assertEquals(
    f.calls.some((c) => c.path === "/sites" && c.method === "POST"),
    false,
  );
  await f.client.logout();
});
Deno.test("an already applied domain update is observed rather than repeated", async () => {
  const f = fixture({ "after.test": "Europe/Amsterdam" });
  await f.client.login();
  await f.client.converge({
    domain: "after.test",
    previous_domain: "before.test",
    timezone: "Europe/Amsterdam",
  });
  assertEquals(f.calls.filter((c) => c.method === "PUT").length, 0);
  await f.client.logout();
});
Deno.test("detach preserves the site and makes no destructive call", () => {
  const f = fixture({ "example.test": "UTC" });
  assertEquals(f.client.detach(), { detached: true });
  assertEquals(f.calls, []);
  assertEquals(f.sites, { "example.test": "UTC" });
});
Deno.test("unauthorized site reads never become successful zero counts", async () => {
  const f = fixture({ "example.test": "UTC" });
  await f.client.login();
  f.deny();
  await assertRejects(
    () =>
      f.client.converge({
        domain: "example.test",
        previous_domain: null,
        timezone: "UTC",
      }),
    PlausibleRefusal,
    "analytics.stats_unauthorized",
  );
  await f.client.logout();
});
Deno.test("unknown login redirects fail closed without leaking credentials or response text", async () => {
  const client = new PlausibleCE(
    "http://10.44.0.3:8000",
    {
      email: "private-email",
      password: "private-password",
      apiKey: "private-key",
    },
    (_input, init) =>
      Promise.resolve(
        init?.method === "POST"
          ? new Response(null, {
            status: 302,
            headers: { location: "https://outside.test/private-key" },
          })
          : new Response('<meta name="csrf-token" content="private-csrf">'),
      ),
  );
  const error = await assertRejects(
    () => client.login(),
    PlausibleRefusal,
    "analytics.management_unauthorized",
  );
  assertEquals(error.message.includes("private-"), false);
});
Deno.test("transport errors suppress raw diagnostics", async () => {
  const client = new PlausibleCE("http://10.44.0.3:8000", {
    email: "private-email",
    password: "private-password",
    apiKey: "private-key",
  }, () => Promise.reject(new Error("private-password")));
  const error = await assertRejects(
    () => client.login(),
    PlausibleRefusal,
    "analytics.management_unavailable",
  );
  assertEquals(error.message.includes("private-"), false);
});

Deno.test("CE goal convergence uses site-scoped Plugins auth and keeps manual goals", async () => {
  const f = fixture({ "example.test": "UTC" }, {
    domain: "example.test",
    token: "private-plugin-token",
  });
  f.goals.push({
    goal_type: "Goal.CustomEvent",
    goal: { event_name: "Manual" },
  });
  const site = {
    domain: "example.test",
    previous_domain: null,
    timezone: "UTC",
    goals: [
      {
        goal_type: "Goal.CustomEvent" as const,
        goal: { event_name: "Signup" },
      },
      {
        goal_type: "Goal.Pageview" as const,
        goal: { path: "/thanks", custom_props: { plan: "paid" } },
      },
      {
        goal_type: "Goal.Revenue" as const,
        goal: { event_name: "Purchase", currency: "EUR" },
      },
    ],
  };
  await f.client.login();
  await f.client.converge(site);
  await f.client.converge(site);
  assertEquals(f.goals.length, 4);
  assertEquals(f.goals[0].goal, { event_name: "Manual" });
  assertEquals(
    f.calls.filter((call) => call.path === "/api/plugins/v1/goals").length,
    2,
  );
  await f.client.logout();
});

for (
  const plugins of [undefined, {
    domain: "foreign.test",
    token: "private-plugin-token",
  }]
) {
  Deno.test(`CE refuses ${plugins ? "foreign" : "missing"} goal credentials before site mutation`, async () => {
    const f = fixture({}, plugins);
    await f.client.login();
    await assertRejects(
      () =>
        f.client.converge({
          domain: "example.test",
          previous_domain: null,
          timezone: "UTC",
          goals: [{
            goal_type: "Goal.CustomEvent",
            goal: { event_name: "Signup" },
          }],
        }),
      PlausibleRefusal,
      "analytics.goals_credentials_missing",
    );
    assertEquals(
      f.calls.filter((call) =>
        ["POST", "PUT", "DELETE"].includes(call.method) &&
        call.path !== "/login"
      ),
      [],
    );
    await f.client.logout();
  });
}

for (
  const [status, body, code] of [
    [401, "private-plugin-token", "analytics.goals_unauthorized"],
    [422, "private-plugin-token", "analytics.goals_update_failed"],
    [500, "private-plugin-token", "analytics.goals_update_failed"],
    [201, "private-plugin-token", "analytics.goals_unproven"],
    [201, '{"goals":[]}', "analytics.goals_unproven"],
    [
      201,
      '{"goals":[{"goal_type":"Goal.CustomEvent","goal":{"id":1,"event_name":"Other"}}]}',
      "analytics.goals_unproven",
    ],
  ] as const
) {
  Deno.test(`CE goal response ${status}/${body.length} refuses unproven writes without private diagnostics`, async () => {
    const f = fixture({ "example.test": "UTC" }, {
      domain: "example.test",
      token: "private-plugin-token",
    });
    f.goalResponse(new Response(body, { status }));
    await f.client.login();
    const error = await assertRejects(
      () =>
        f.client.converge({
          domain: "example.test",
          previous_domain: null,
          timezone: "UTC",
          goals: [{
            goal_type: "Goal.CustomEvent",
            goal: { event_name: "Signup" },
          }],
        }),
      PlausibleRefusal,
      code,
    );
    assertEquals(error.message.includes("private-"), false);
    assertEquals(f.calls.some((call) => call.path === "/api/v2/query"), false);
    await f.client.logout();
  });
}

Deno.test("CE desired goal schema refuses unknown or unsupported filters", () => {
  for (
    const goal of [
      {
        goal_type: "Goal.CustomEvent",
        goal: { event_name: "Signup", token: "private-value" },
      },
      {
        goal_type: "Goal.CustomEvent",
        goal: {
          event_name: "Signup",
          custom_props: { a: "1", b: "2", c: "3", d: "4" },
        },
      },
      { goal_type: "Goal.Pageview", goal: { path: "https://outside.test" } },
      {
        goal_type: "Goal.Revenue",
        goal: { event_name: "Purchase", currency: "invalid" },
      },
    ]
  ) assertEquals(Goal.safeParse(goal).success, false);
});

Deno.test("CE goal identity ignores provider property order", async () => {
  const f = fixture({ "example.test": "UTC" }, {
    domain: "example.test",
    token: "private-plugin-token",
  });
  f.goalResponse(
    Response.json({
      goals: [{
        goal_type: "Goal.CustomEvent",
        goal: {
          id: 1,
          event_name: "Signup",
          custom_props: { b: "2", a: "1" },
        },
      }],
    }, { status: 201 }),
  );
  await f.client.login();
  await f.client.converge({
    domain: "example.test",
    previous_domain: null,
    timezone: "UTC",
    goals: [{
      goal_type: "Goal.CustomEvent",
      goal: { event_name: "Signup", custom_props: { a: "1", b: "2" } },
    }],
  });
  await f.client.logout();
});

Deno.test("CE goals transport failure suppresses the token and never claims Stats readiness", async () => {
  const f = fixture({ "example.test": "UTC" });
  const client = new PlausibleCE(
    "http://10.44.0.3:8000",
    {
      email: "private-email",
      password: "private-password",
      apiKey: "private-key",
      plugins: { domain: "example.test", token: "private-plugin-token" },
    },
    (input, init) =>
      String(input).endsWith("/api/plugins/v1/goals")
        ? Promise.reject(new Error("private-plugin-token"))
        : f.fetcher(input, init),
  );
  await client.login();
  const error = await assertRejects(
    () =>
      client.converge({
        domain: "example.test",
        previous_domain: null,
        timezone: "UTC",
        goals: [{ goal_type: "Goal.Pageview", goal: { path: "/thanks" } }],
      }),
    PlausibleRefusal,
    "analytics.goals_unavailable",
  );
  assertEquals(error.message.includes("private-"), false);
  assertEquals(f.calls.some((call) => call.path === "/api/v2/query"), false);
  await client.logout();
});

Deno.test("CE goals use the pinned eight-goal bulk limit and repeated batches remain idempotent", async () => {
  const f = fixture({ "example.test": "UTC" }, {
    domain: "example.test",
    token: "private-plugin-token",
  });
  const site = {
    domain: "example.test",
    previous_domain: null,
    timezone: "UTC",
    goals: Array.from(
      { length: 10 },
      (_, index) => ({
        goal_type: "Goal.CustomEvent" as const,
        goal: { event_name: `Event ${index}` },
      }),
    ),
  };
  await f.client.login();
  await f.client.converge(site);
  await f.client.converge(site);
  assertEquals(f.goals.length, 10);
  assertEquals(
    f.calls.filter((call) => call.path === "/api/plugins/v1/goals")
      .map((call) => (call.body?.goals as unknown[]).length),
    [8, 2, 8, 2],
  );
  await f.client.logout();
});
