import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  exporterActions,
  exporterRule,
  type ExporterState,
} from "./metrics_exporter_actions.ts";
import {
  serviceActions,
  serviceRule,
  serviceRules,
  type ServiceState,
} from "./metrics_service_actions.ts";
import { runMetricsProcess, type Runner } from "./metrics_system.ts";
const cadvisorHash =
    "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87",
  fpmHash = "570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98";
const marker = "# Managed by Orbit: service-metrics\n";
const empty: ServiceState = {
  config: null,
  unit: null,
  rules: [],
  active: false,
  enabled: false,
  binary: false,
};
function desired(): ServiceState {
  return {
    config: marker + "monitor:\n  listen_addr: 10.44.0.13:9114\n",
    unit: marker +
      "[Service]\nExecStart=/usr/local/bin/orbit-fpm-exporter serve\n",
    rules: serviceRules(
      "ufw allow in on orbit proto tcp from 10.44.0.9 to 10.44.0.13 port 9114 comment 'orbit:metrics-service-fpm-allow'\nufw deny in on orbit proto tcp to 10.44.0.13 port 9114 comment 'orbit:metrics-service-fpm-deny'",
    ),
    active: true,
    enabled: true,
    binary: true,
  };
}
async function fixture() {
  const root = await Deno.makeTempDir();
  for (const path of ["/etc/systemd/system", "/etc/orbit", "/usr/local/bin"]) {
    await Deno.mkdir(root + path, { recursive: true });
  }
  const states: Record<string, { active: boolean; enabled: boolean }> = {};
  const rules = new Map<string, string[]>();
  const calls: string[][] = [];
  let badHash = false,
    fail = "",
    unknown = "",
    packagePresent = true,
    largeMetrics = false;
  const runner: Runner = async (program, args, options = {}) => {
    const name = program.split("/").at(-1)!;
    calls.push([name, ...args]);
    const key = args.join(" ");
    if (fail && key === fail) {
      fail = "";
      return { code: 1, stdout: "never expose raw error sentinel" };
    }
    if (unknown && key === unknown) throw new Error("metrics.outcome_unknown");
    if (name === "ufw") {
      if (key === "status") return { code: 0, stdout: "Status: active\n" };
      if (key === "status numbered") {
        return {
          code: 0,
          stdout: "Status: active\n" +
            [...rules].filter(([comment]) => !comment.includes("service-")).map(
              ([comment, rule], i) =>
                `[ ${i + 1}] ${rule[9]} ${rule[11]}/tcp on orbit ALLOW IN ${
                  rule[7]
                } # ${comment}`,
            ).join("\n"),
        };
      }
      if (key === "show added") {
        return {
          code: 0,
          stdout: [...rules.values()].map((row) => "ufw " + row.join(" ")).join(
            "\n",
          ),
        };
      }
      if (args[0] === "--force") rules.delete(args.at(-1)!);
      else if (args[0] === "insert") {
        const existing = [...rules];
        rules.clear();
        rules.set(args.at(-1)!, args.slice(2));
        for (const [key, value] of existing) rules.set(key, value);
      } else rules.set(args.at(-1)!, args);
      return { code: 0, stdout: "" };
    }
    if (name === "systemctl") {
      if (["daemon-reload", "reset-failed"].includes(args[0])) {
        return { code: 0, stdout: "" };
      }
      const state = states[args.at(-1)!] ??= { active: false, enabled: false };
      if (args[0] === "is-active") {
        return {
          code: state.active ? 0 : 3,
          stdout: state.active ? "active\n" : "inactive\n",
        };
      }
      if (args[0] === "is-enabled") {
        return {
          code: state.enabled ? 0 : 1,
          stdout: state.enabled ? "enabled\n" : "disabled\n",
        };
      }
      if (["enable", "disable"].includes(args[0])) {
        state.enabled = args[0] === "enable";
        if (args.includes("--now")) state.active = state.enabled;
      }
      if (args[0] === "restart") state.active = true;
      if (args[0] === "stop") state.active = false;
      return { code: 0, stdout: "" };
    }
    if (name === "dpkg-query") {
      return {
        code: packagePresent ? 0 : 1,
        stdout: packagePresent ? "install ok installed" : "",
      };
    }
    if (name === "apt-get") {
      packagePresent = true;
      return { code: 0, stdout: "" };
    }
    if (name === "curl") {
      if (args.includes("--output")) {
        await Deno.writeTextFile(
          args[args.indexOf("--output") + 1],
          "pinned binary fixture",
        );
      }
      const stdout = args.includes("--output")
        ? ""
        : "# HELP fixture\n" + (largeMetrics ? "x".repeat(70000) : "");
      if (stdout.length > (options.maxOutputBytes ?? 65536)) {
        throw new Error("metrics.outcome_unknown");
      }
      return { code: 0, stdout };
    }
    if (name === "sha256sum") {
      return {
        code: 0,
        stdout: (badHash
          ? "0".repeat(64)
          : args.at(-1)!.includes("cadvisor")
          ? cadvisorHash
          : fpmHash) + "  file\n",
      };
    }
    const command = new Deno.Command(program, {
      args,
      stdin: options.input === undefined ? "null" : "piped",
      stdout: "piped",
      stderr: "null",
    }).spawn();
    if (options.input !== undefined) {
      const writer = command.stdin.getWriter();
      await writer.write(new TextEncoder().encode(options.input));
      await writer.close();
    }
    const output = await command.output();
    return {
      code: output.code,
      stdout: new TextDecoder().decode(output.stdout),
    };
  };
  const options = { root, owner: Deno.uid()! };
  return {
    root,
    calls,
    states,
    rules,
    exporter: exporterActions(runner, options),
    service: serviceActions(runner, options),
    badHash: () => {
      badHash = true;
    },
    fail: (value: string) => {
      fail = value;
    },
    unknown: (value: string) => {
      unknown = value;
    },
    largeMetrics: () => {
      largeMetrics = true;
    },
    packageMissing: () => {
      packagePresent = false;
    },
    close: () => Deno.remove(root, { recursive: true }),
  };
}
const input = { address: "10.44.0.13", metrics_address: "10.44.0.9" };
Deno.test("exporter refuses duplicate malformed and foreign-interface owned firewall rules", () => {
  const valid =
    "Status: active\n[ 3] 10.44.0.13 9100/tcp on orbit ALLOW IN 10.44.0.9 # orbit:metrics-node-exporter";
  assertEquals(
    exporterRule(valid, "node_exporter", input.address),
    input.metrics_address,
  );
  for (
    const text of [
      valid + "\n" + valid.split("\n")[1],
      valid.replace("on orbit", "on eth0"),
      valid.replace("9100/tcp", "9999/tcp"),
    ]
  ) {
    let refused = false;
    try {
      exporterRule(text, "node_exporter", input.address);
    } catch {
      refused = true;
    }
    assertEquals(refused, true);
  }
});
for (const kind of ["node_exporter", "cadvisor"] as const) {
  Deno.test(`${kind} uses local tools to converge remove and restore an exact snapshot`, async () => {
    const f = await fixture();
    try {
      f.packageMissing();
      assertEquals(
        await f.exporter(kind, { ...input, operation: "converge" }),
        { changed: true },
      );
      const state = await f.exporter(kind, {
        ...input,
        operation: "snapshot",
      }) as ExporterState;
      assertEquals(state.service_active, true);
      assertEquals(state.firewall_source, input.metrics_address);
      assertEquals(await f.exporter(kind, { ...input, operation: "actual" }), {
        actual: "active",
      });
      assertEquals(await f.exporter(kind, { ...input, operation: "remove" }), {
        changed: true,
      });
      assertEquals(
        await f.exporter(kind, { ...input, operation: "restore", state }),
        { changed: true },
      );
      assertEquals(
        await f.exporter(kind, { ...input, operation: "snapshot" }),
        state,
      );
      if (kind === "cadvisor") {
        assertEquals(f.calls.filter((row) => row[0] === "curl").length, 2);
      } else assertEquals(f.calls.some((row) => row[0] === "apt-get"), true);
    } finally {
      await f.close();
    }
  });
}
Deno.test("owned exporter failure restores before returning a bounded error", async () => {
  const f = await fixture();
  try {
    const before = await f.exporter("node_exporter", {
      ...input,
      operation: "snapshot",
    });
    f.fail("restart prometheus-node-exporter");
    assertEquals(
      await f.exporter("node_exporter", { ...input, operation: "converge" }),
      { error_code: "metrics.exporter_service_failed", restored: true },
    );
    assertEquals(
      await f.exporter("node_exporter", { ...input, operation: "snapshot" }),
      before,
    );
  } finally {
    await f.close();
  }
});
Deno.test("unknown exporter effects throw and never claim rollback", async () => {
  const f = await fixture();
  try {
    f.unknown("restart prometheus-node-exporter");
    await assertRejects(
      () => f.exporter("node_exporter", { ...input, operation: "converge" }),
      Error,
      "metrics.outcome_unknown",
    );
    assertEquals(f.calls.some((row) => row[1] === "disable"), false);
  } finally {
    await f.close();
  }
});
Deno.test("unowned exporter symlink refuses before package or systemd mutation", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(
      f.root + "/etc/systemd/system/prometheus-node-exporter.service.d",
    );
    await Deno.symlink(
      "/etc/passwd",
      f.root +
        "/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf",
    );
    assertEquals(
      await f.exporter("node_exporter", { ...input, operation: "converge" }),
      { error_code: "metrics.exporter_configuration_ownership_drift" },
    );
    assertEquals(
      f.calls.some((row) => row[0] === "apt-get" || row[1] === "restart"),
      false,
    );
  } finally {
    await f.close();
  }
});
Deno.test("cAdvisor checksum mismatch never publishes the unverified binary", async () => {
  const f = await fixture();
  try {
    f.badHash();
    const out = await f.exporter("cadvisor", {
      ...input,
      operation: "converge",
    });
    assertEquals(out.error_code, "metrics.checksum_mismatch");
    assertEquals(
      f.calls.some((row) =>
        row[0] === "mv" && row.at(-1)!.endsWith("/orbit-cadvisor")
      ),
      false,
    );
  } finally {
    await f.close();
  }
});
Deno.test("service applies verifies and restores its owned files systemd state and ordered firewall", async () => {
  const f = await fixture();
  try {
    assertEquals(await f.service({ operation: "snapshot" }), empty);
    assertEquals(await f.service({ operation: "apply", state: desired() }), {});
    assertEquals(await f.service({ operation: "snapshot" }), desired());
    assertEquals(await f.service({ operation: "restore", state: empty }), {});
    assertEquals(await f.service({ operation: "snapshot" }), empty);
  } finally {
    await f.close();
  }
});
Deno.test("service refuses foreign firewall scope duplicate markers and incomplete state before effects", async () => {
  const f = await fixture();
  try {
    const state = desired();
    state.rules[0][3] = "eth0";
    assertEquals(await f.service({ operation: "apply", state }), {
      error_code: "metrics.service_invalid",
    });
    assertEquals(
      await f.service({
        operation: "apply",
        state: { ...empty, active: true },
      }),
      { error_code: "metrics.service_invalid" },
    );
    let refused = false;
    try {
      serviceRule([
        "allow",
        "in",
        "on",
        "orbit",
        "proto",
        "tcp",
        "from",
        "any",
        "to",
        "10.44.0.13",
        "port",
        "9114",
        "comment",
        "orbit:metrics-service-fpm-allow",
      ]);
    } catch {
      refused = true;
    }
    assertEquals(refused, true);
    assertEquals(f.calls, []);
  } finally {
    await f.close();
  }
});
Deno.test("service readiness failure restores state and consumes its recovery journal", async () => {
  const f = await fixture();
  try {
    f.fail(
      "--fail --silent --noproxy * --max-time 12 --max-filesize 8388608 http://10.44.0.13:9114/metrics",
    );
    assertEquals(await f.service({ operation: "apply", state: desired() }), {
      error_code: "metrics.service_failed",
      restored: true,
    });
    assertEquals(await f.service({ operation: "snapshot" }), empty);
  } finally {
    await f.close();
  }
});
Deno.test("service rejects a foreign-owned directory marker before any mutation", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(f.root + "/etc/orbit/service-metrics", { mode: 0o700 });
    await Deno.writeTextFile(
      f.root + "/etc/orbit/service-metrics/.orbit-owner",
      "foreign\n",
      { mode: 0o600 },
    );
    assertEquals(await f.service({ operation: "apply", state: desired() }), {
      error_code: "metrics.service_ownership",
    });
    assertEquals(
      f.calls.some((row) => ["mv", "install", "curl"].includes(row[0])),
      false,
    );
  } finally {
    await f.close();
  }
});

Deno.test("service snapshot refuses a pending recovery without changing local state", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(f.root + "/etc/orbit/service-metrics", { mode: 0o700 });
    await Deno.writeTextFile(
      f.root + "/etc/orbit/service-metrics/.orbit-owner",
      marker,
      { mode: 0o600 },
    );
    await Deno.writeTextFile(
      f.root + "/etc/orbit/service-metrics/transaction.json",
      JSON.stringify(empty),
      { mode: 0o600 },
    );
    assertEquals(await f.service({ operation: "snapshot" }), {
      error_code: "metrics.service_recovery_required",
    });
    assertEquals(
      f.calls.some((row) =>
        ["mv", "rm", "install", "curl", "systemctl"].includes(row[0])
      ),
      false,
    );
    assertEquals(await f.service({ operation: "apply", state: empty }), {});
    assertEquals(await f.service({ operation: "snapshot" }), empty);
  } finally {
    await f.close();
  }
});
Deno.test("service refuses failed systemd inspection with no conclusive text state", async () => {
  const f = await fixture();
  try {
    f.fail("is-active orbit-fpm-exporter");
    assertEquals(await f.service({ operation: "snapshot" }), {
      error_code: "metrics.service_failed",
    });
    assertEquals(
      f.calls.some((row) => ["mv", "install", "curl"].includes(row[0])),
      false,
    );
  } finally {
    await f.close();
  }
});

Deno.test("large healthy service metrics stay within their explicit readiness budget", async () => {
  const f = await fixture();
  try {
    f.largeMetrics();
    assertEquals(await f.service({ operation: "apply", state: desired() }), {});
  } finally {
    await f.close();
  }
});
Deno.test("private command output budgets propagate through the canonical Node runner", async () => {
  const args = ["-c", "print('x'*70000)"];
  await assertRejects(
    () => runMetricsProcess("/usr/bin/python3", args),
    Error,
    "metrics.outcome_unknown",
  );
  const result = await runMetricsProcess("/usr/bin/python3", args, {
    maxOutputBytes: 8 * 1024 * 1024,
  });
  assertEquals(result.code, 0);
  assertEquals(result.stdout.length, 70001);
});
