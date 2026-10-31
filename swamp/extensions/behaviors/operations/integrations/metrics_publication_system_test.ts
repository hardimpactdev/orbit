import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { publicationFirewallActions } from "./metrics_publication_system.ts";
import type { Runner } from "./metrics_system.ts";
const allow = "orbit:metrics-grafana-upstream",
  deny = "orbit:metrics-grafana-isolation",
  trust = "orbit:wireguard-members";
const input = {
  address: "10.44.0.17",
  action: { operation: "converge" as const, gateway: "10.44.0.14" },
};
function fixture(
  initial: { body: string; comment: string }[] = [{
    body: "Anywhere ALLOW IN 10.44.0.0/24",
    comment: trust,
  }],
) {
  const rules = [...initial], effects: string[][] = [];
  let failAllow = false, active = true;
  const run: Runner = (_program, args) => {
    if (args[0] === "status") {
      return Promise.resolve({
        code: 0,
        stdout: `Status: ${active ? "active" : "inactive"}\n` +
          rules.map((rule, i) => `[ ${i + 1}] ${rule.body} # ${rule.comment}`)
            .join("\n"),
      });
    }
    effects.push(args);
    if (args[0] === "insert") {
      if (failAllow && args[2] === "allow") {
        failAllow = false;
        return Promise.resolve({ code: 1, stdout: "" });
      }
      rules.unshift({
        body: `${args[11]} 3000/tcp on orbit ${args[2].toUpperCase()} IN ${
          args[9] === "any" ? "Anywhere" : args[9]
        }`,
        comment: args.at(-1)!,
      });
    } else rules.splice(Number(args.at(-1)) - 1, 1);
    return Promise.resolve({ code: 0, stdout: "" });
  };
  return {
    apply: publicationFirewallActions(run),
    rules,
    effects,
    failAllow: () => {
      failAllow = true;
    },
    inactive: () => {
      active = false;
    },
  };
}
Deno.test("Grafana firewall establishes deny before trust then Gateway-only allow and repeats without writes", async () => {
  const f = fixture();
  assertEquals(await f.apply(input), { changed: true });
  assertEquals(f.rules.map((r) => r.comment), [allow, deny, trust]);
  f.effects.length = 0;
  assertEquals(await f.apply(input), { changed: false });
  assertEquals(f.effects, []);
  assertEquals(
    await f.apply({
      ...input,
      action: { operation: "remove", gateway: input.action.gateway },
    }),
    { changed: true },
  );
  assertEquals(f.rules.map((r) => r.comment), [trust]);
});
Deno.test("Grafana firewall refuses wrong source interface port and duplicate ownership before effects", async () => {
  const row = {
    body: "10.44.0.17 3000/tcp on orbit ALLOW IN 10.44.0.14",
    comment: allow,
  };
  for (
    const rules of [
      [{ ...row, body: row.body.replace("orbit", "eth0") }],
      [{ ...row, body: row.body.replace("3000", "9999") }],
      [{ ...row, body: row.body.replace("10.44.0.14", "10.44.0.13") }],
      [row, row],
    ]
  ) {
    const f = fixture(rules);
    await assertRejects(() => f.apply(input), Error, "ownership_drift");
    assertEquals(f.effects, []);
  }
});
Deno.test("Grafana firewall retains isolation on allow failure and completes on the same intent", async () => {
  const f = fixture();
  f.failAllow();
  await assertRejects(() => f.apply(input), Error, "apply_failed");
  assertEquals(f.rules.map((r) => r.comment), [deny, trust]);
  assertEquals(await f.apply(input), { changed: true });
  assertEquals(f.rules.map((r) => r.comment), [allow, deny, trust]);
});
Deno.test("Grafana firewall repairs order and abandons exact role comments only", async () => {
  const f = fixture([
    { body: "Anywhere ALLOW IN 10.44.0.0/24", comment: trust },
    { body: "10.44.0.17 3000/tcp on orbit DENY IN Anywhere", comment: deny },
  ]);
  await f.apply(input);
  assertEquals(f.rules.map((r) => r.comment), [allow, deny, trust]);
  await f.apply({ ...input, action: { operation: "abandon" } });
  assertEquals(f.rules.map((r) => r.comment), [trust]);
});
Deno.test("Grafana firewall never activates an inactive firewall or hides unknown effects", async () => {
  const f = fixture();
  f.inactive();
  await assertRejects(() => f.apply(input), Error, "inactive");
  assertEquals(f.effects, []);
  const unknown: Runner = () => {
    throw new Error("metrics.outcome_unknown");
  };
  await assertRejects(
    () => publicationFirewallActions(unknown)(input),
    Error,
    "metrics.outcome_unknown",
  );
});
