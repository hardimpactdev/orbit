import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  changeTargets,
  convergeRoute,
  removeRoute,
  replaceDomain,
  replaceRouter,
  retireSource,
  RouteRefusal,
  type Stages,
} from "./lifecycle.ts";

class Fixture implements Stages {
  events: string[] = [];
  receipts = new Set<string>();
  failures = new Map<string, Error>();
  authoritative = "old";
  oldServing = true;
  candidateServing = false;
  completed(name: string) {
    return this.receipts.has(name);
  }
  run(name: string): Promise<void> {
    this.events.push(name);
    const failure = this.failures.get(name);
    if (failure) return Promise.reject(failure);
    if (["router-caddy", "workload-caddy"].includes(name)) {
      this.candidateServing = true;
    }
    if (["database", "database-cutover", "database-committed"].includes(name)) {
      this.authoritative = "candidate";
    }
    if (["withdraw-old-sites", "cleanup-caddy"].includes(name)) {
      this.oldServing = false;
    }
    if (["withdraw-candidate-sites", "rollback-caddy"].includes(name)) {
      this.candidateServing = false;
    }
    this.receipts.add(name);
    return Promise.resolve();
  }
}

Deno.test("domain replacement verifies projections and environment before DNS and cutover", async () => {
  const f = new Fixture();
  await replaceDomain(f, { public: false, production: false });
  assertEquals(f.events, [
    "workload-certificate",
    "workload-caddy",
    "router-certificate",
    "firewall-policy",
    "workload-verified",
    "router-caddy",
    "laravel-url",
    "dns-published",
    "database-cutover",
    "prepare-cleanup",
    "cleanup-caddy",
    "cleanup-certificates",
    "synchronize-current-environment",
    "verify-current-workload",
    "complete-replacement",
  ]);
  assertEquals(f.authoritative, "candidate");
  assertEquals(f.oldServing, false);
});

Deno.test("public handler activates after cutover and before opening its firewall", async () => {
  const f = new Fixture();
  await replaceDomain(f, { public: true, production: true });
  const before = (a: string, b: string) =>
    assertEquals(f.events.indexOf(a) < f.events.indexOf(b), true);
  before("router-caddy", "ingress-certificate");
  before("public-edge-verified", "environment-synchronized");
  before("environment-synchronized", "dns-published");
  before("database-cutover", "public-activated");
  before("public-activated", "ingress-firewall");
  assertEquals(f.events.includes("laravel-url"), false);
});

Deno.test("pre-cutover refusal restores the original Route and withdraws sites before certificates", async () => {
  const f = new Fixture();
  f.failures.set(
    "public-edge-verified",
    new RouteRefusal("route.verify_failed"),
  );
  await assertRejects(
    () => replaceDomain(f, { public: true, production: true }),
    RouteRefusal,
  );
  assertEquals(f.authoritative, "old");
  assertEquals(f.oldServing, true);
  assertEquals(f.candidateServing, false);
  assertEquals(f.events.slice(-6), [
    "rollback-public-edge",
    "rollback-environment",
    "rollback-dns",
    "rollback-caddy",
    "rollback-certificates",
    "rollback-record",
  ]);
});

Deno.test("lost DNS response leaves both sites serving until the same intent resumes", async () => {
  const f = new Fixture();
  f.failures.set("dns-published", new Error("Lost response"));
  await assertRejects(() =>
    replaceDomain(f, { public: false, production: false })
  );
  assertEquals(f.authoritative, "old");
  assertEquals(f.oldServing && f.candidateServing, true);
  assertEquals(f.events.some((name) => name.startsWith("rollback")), false);
  f.failures.clear();
  f.events = [];
  await replaceDomain(f, { public: false, production: false });
  assertEquals(f.events[0], "dns-published");
  assertEquals(f.events.includes("workload-certificate"), false);
});

Deno.test("post-cutover handler refusal keeps the replacement authoritative and resumes forward", async () => {
  const f = new Fixture();
  f.failures.set("public-activated", new RouteRefusal("caddy.publish_failed"));
  await assertRejects(() =>
    replaceDomain(f, { public: true, production: true })
  );
  assertEquals(f.authoritative, "candidate");
  assertEquals(f.events.at(-1), "rollback-public-edge");
  assertEquals(f.events.includes("rollback-record"), false);
  f.failures.clear();
  f.events = [];
  await replaceDomain(f, { public: true, production: true });
  assertEquals(f.events[0], "public-activated");
  assertEquals(f.events.includes("database-cutover"), false);
});

Deno.test("router replacement keeps the old Router until new DNS has expired", async () => {
  const f = new Fixture();
  await replaceRouter(f);
  assertEquals(f.events, [
    "baseline",
    "router-certificate",
    "firewall-policy",
    "workload-verify",
    "router-caddy",
    "database",
    "dns-publication",
    "prune-dns",
    "await-old-dns-expiry",
    "withdraw-old-sites",
    "remove-old-certificates",
    "remove-old-firewall",
    "remove-old-baseline",
    "complete-router",
  ]);
  assertEquals(f.authoritative, "candidate");
});

Deno.test("pre-cutover DNS restoration failure keeps the old Router authoritative", async () => {
  const f = new Fixture();
  f.failures.set("workload-verify", new RouteRefusal("route.verify_failed"));
  f.failures.set("restore-dns", new RouteRefusal("dns.restore_failed"));
  await assertRejects(
    () => replaceRouter(f),
    RouteRefusal,
    "dns.restore_failed",
  );
  assertEquals(f.authoritative, "old");
  assertEquals(f.oldServing, true);
  assertEquals(f.events.includes("withdraw-candidate-sites"), false);
});

Deno.test("router cleanup restart never reprovisions or repeats cutover", async () => {
  const f = new Fixture();
  f.failures.set("remove-old-certificates", new Error("Lost response"));
  await assertRejects(() => replaceRouter(f));
  assertEquals(f.authoritative, "candidate");
  f.failures.clear();
  f.events = [];
  await replaceRouter(f);
  assertEquals(f.events, [
    "remove-old-certificates",
    "remove-old-firewall",
    "remove-old-baseline",
    "complete-router",
  ]);
});

Deno.test("targeted removal resumes after Caddy without restoring a removed site", async () => {
  const f = new Fixture();
  f.failures.set("certificates", new RouteRefusal("certificate.leaf_failed"));
  await assertRejects(() => removeRoute(f, true));
  assertEquals(f.events, ["dns", "caddy", "php", "certificates"]);
  f.failures.clear();
  f.events = [];
  await removeRoute(f, true);
  assertEquals(f.events, ["certificates", "firewall", "record"]);
});

Deno.test("target preparation refusal never commits a partial pool", async () => {
  const f = new Fixture();
  f.failures.set(
    "target-workload-verified",
    new RouteRefusal("route.workload_failed"),
  );
  await assertRejects(() => changeTargets(f));
  assertEquals(f.authoritative, "old");
  assertEquals(f.events.at(-1), "rollback-preparations-record");
});

Deno.test("committed pool publication failure recovers forward without rollback", async () => {
  const f = new Fixture();
  f.failures.set("router-published", new RouteRefusal("caddy.publish_failed"));
  await assertRejects(() => changeTargets(f));
  assertEquals(f.authoritative, "candidate");
  assertEquals(f.events.includes("rollback-preparations"), false);
  f.failures.clear();
  f.events = [];
  await changeTargets(f);
  assertEquals(f.events, [
    "router-published",
    "vacated-published",
    "target-staging-cleanup",
    "removal",
    "completed",
  ]);
});

Deno.test("development initial publication grants only source access and shared pools before Caddy", async () => {
  const f = new Fixture();
  await convergeRoute(f, {
    production: false,
    remoteRouter: true,
    public: false,
    webRoot: false,
  });
  assertEquals(f.events, [
    "workload-certificate",
    "publish-sites",
    "workload-prepared",
    "workload-caddy",
    "router-certificate",
    "firewall-policy",
    "workload-verified",
    "router-caddy",
    "dns-published",
    "activate-route",
  ]);
});
Deno.test("production clone uses dedicated runtime and firewall before workload and Router publication", async () => {
  const f = new Fixture();
  await convergeRoute(f, {
    production: true,
    remoteRouter: true,
    public: false,
    webRoot: false,
  });
  assertEquals(f.events, [
    "workload-certificate",
    "publish-sites",
    "production-runtime",
    "workload-firewall",
    "workload-caddy",
    "router-certificate",
    "firewall-policy",
    "workload-verified",
    "router-caddy",
    "dns-published",
    "activate-route",
  ]);
});
Deno.test("a colocated Router never verifies or proxies its own HTTPS listener", async () => {
  const f = new Fixture();
  await convergeRoute(f, {
    production: true,
    remoteRouter: false,
    public: false,
    webRoot: false,
  });
  assertEquals(f.events.includes("router-caddy"), false);
  assertEquals(f.events.includes("workload-verified"), false);
  assertEquals(
    f.events.indexOf("workload-caddy") < f.events.indexOf("dns-published"),
    true,
  );
});
Deno.test("initial projection refusal never publishes DNS or marks the Route active", async () => {
  const f = new Fixture();
  f.failures.set("workload-caddy", new RouteRefusal("caddy.publish_failed"));
  await assertRejects(() =>
    convergeRoute(f, {
      production: true,
      remoteRouter: true,
      public: true,
      webRoot: false,
    })
  );
  assertEquals(f.events.includes("dns-published"), false);
  assertEquals(f.events.includes("activate-route"), false);
});
Deno.test("source retirement withdraws old Caddy before source keys and preserves destination state", async () => {
  const f = new Fixture();
  f.authoritative = "destination";
  await retireSource(f);
  assertEquals(f.authoritative, "destination");
  assertEquals(f.events[0], "rebuild-transfer-caddy");
  assertEquals(
    f.events.indexOf("dns-published") <
      f.events.indexOf("remove-unused-source-certificate"),
    true,
  );
});

Deno.test("Router DNS failure after committed cutover keeps both Nodes serving and recovers forward", async () => {
  const f = new Fixture();
  f.failures.set("dns-publication", new RouteRefusal("dns.publish_failed"));
  await assertRejects(
    () => replaceRouter(f),
    RouteRefusal,
    "dns.publish_failed",
  );
  assertEquals(f.authoritative, "candidate");
  assertEquals(f.oldServing && f.candidateServing, true);
  assertEquals(f.events.includes("restore-dns"), false);
  assertEquals(f.events.includes("withdraw-old-sites"), false);
  f.failures.clear();
  f.events = [];
  await replaceRouter(f);
  assertEquals(f.events[0], "dns-publication");
  assertEquals(f.events.includes("database"), false);
  assertEquals(f.events.includes("baseline"), false);
});

Deno.test("failed initial production web root withdraws Caddy before its pool and certificates", async () => {
  const f = new Fixture();
  f.failures.set(
    "production-runtime",
    new RouteRefusal("php.convergence_failed"),
  );
  await assertRejects(() =>
    convergeRoute(f, {
      production: true,
      remoteRouter: true,
      public: false,
      webRoot: true,
    }), RouteRefusal);
  assertEquals(f.events.slice(-6), [
    "rollback-initial-dns",
    "rollback-initial-caddy",
    "rollback-initial-php",
    "synchronize-web-root-urls.rollback",
    "rollback-initial-certificates",
    "rollback-initial-firewall",
  ]);
  assertEquals(f.completed("activate-route"), false);
});

Deno.test("initial web-root cleanup stops before pool and keys if Caddy withdrawal fails", async () => {
  const f = new Fixture();
  f.failures.set(
    "production-runtime",
    new RouteRefusal("php.convergence_failed"),
  );
  f.failures.set(
    "rollback-initial-caddy",
    new RouteRefusal("caddy.publication_failed"),
  );
  await assertRejects(() =>
    convergeRoute(f, {
      production: true,
      remoteRouter: true,
      public: false,
      webRoot: true,
    }), RouteRefusal);
  assertEquals(f.events.includes("rollback-initial-php"), false);
  assertEquals(f.events.includes("rollback-initial-certificates"), false);
});
