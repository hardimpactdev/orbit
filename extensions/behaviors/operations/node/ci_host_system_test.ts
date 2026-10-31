import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { convergeCiHost } from "./ci_host_system.ts";
import type { SystemCommand, SystemTransport } from "./system_command.ts";

const paths = {
  project: "/1.0/projects/orbit-ci",
  network: "/1.0/networks/orbitci0?project=default",
  acl: "/1.0/network-acls/orbit-ci-egress?project=default",
  pool: "/1.0/storage-pools/default",
  profile: "/1.0/profiles/default?project=orbit-ci",
  instances: "/1.0/instances?project=orbit-ci",
};
const value = (raw: unknown) => raw as Record<string, unknown>;

/** In-memory Incus API objects, including retained resources outside this role's authority. */
class Incus {
  objects = new Map<string, unknown>();
  calls: SystemCommand[] = [];
  refuseProjectDelete = false;
  refuseVerification = false;
  failQuery = false;
  aclEmptyFields = false;
  signalAfterQuery?: AbortController;
  commandArgs(command: SystemCommand) {
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args.slice(0, 2), ["-n", "/usr/bin/incus"]);
    return command.args.slice(2);
  }
  changes() {
    return this.calls.map((call) => this.commandArgs(call)).filter((args) =>
      args[0] !== "query"
    );
  }
  run: SystemTransport = (command) => {
    this.calls.push(structuredClone(command));
    const args = this.commandArgs(command);
    assertEquals(command.timeoutMs > 0 && command.timeoutMs <= 30000, true);
    if (args[0] === "query") {
      const path = args[1];
      if (this.failQuery) {
        return Promise.resolve({
          success: false,
          stdout: "daemon unavailable",
        });
      }
      const base = path.split("?")[0];
      const result =
        ["projects", "networks", "network-acls", "storage-pools"].some((
            collection,
          ) => base === `/1.0/${collection}`
          )
          ? [...this.objects.keys()].filter((key) => key.startsWith(`${base}/`))
          : this.objects.get(path);
      this.signalAfterQuery?.abort();
      const output = path === paths.acl && this.aclEmptyFields
        ? {
          ...value(result),
          egress: (value(result).egress as Record<string, unknown>[]).map(
            (rule) => ({ ...rule, source: "", description: "", protocol: "" }),
          ),
        }
        : result;
      return Promise.resolve({
        success: result !== undefined,
        stdout: JSON.stringify(output) ?? "",
      });
    }
    assertEquals(args.slice(0, 2), ["--project", "default"]);
    const mutation = args.slice(2);
    const tag = mutation.slice(0, 2).join(" ");
    const settings = (items: readonly string[]) =>
      Object.fromEntries(items.map((item) => item.split(/=(.*)/s).slice(0, 2)));
    const document = () => JSON.parse(command.stdin ?? "null");
    switch (tag) {
      case "storage create":
        this.objects.set(paths.pool, { driver: "dir" });
        break;
      case "project create":
        this.objects.set(paths.project, {
          config: settings(mutation.slice(3).filter((arg) => arg !== "-c")),
        });
        this.objects.set(paths.instances, []);
        break;
      case "project set":
        Object.assign(
          value(value(this.objects.get(paths.project)).config),
          settings(mutation.slice(3)),
        );
        break;
      case "network acl":
        if (mutation[2] === "create") this.objects.set(paths.acl, {});
        else if (mutation[2] === "edit") {
          this.objects.set(paths.acl, document());
        } else if (mutation[2] === "delete") this.objects.delete(paths.acl);
        else throw new Error("Unexpected ACL mutation");
        break;
      case "network create":
        this.objects.set(paths.network, {
          type: "bridge",
          managed: true,
          config: { "ipv4.address": "10.253.3.1/24" },
        });
        break;
      case "network set":
        Object.assign(
          value(value(this.objects.get(paths.network)).config),
          settings(mutation.slice(3)),
        );
        break;
      case "profile edit": {
        const profile = document();
        if (this.refuseVerification) profile.config["limits.memory"] = "1GiB";
        this.objects.set(paths.profile, profile);
        break;
      }
      case "project delete":
        if (this.refuseProjectDelete) {
          return Promise.resolve({
            success: false,
            stdout: "project still owns images or volumes",
          });
        }
        this.objects.delete(paths.project);
        break;
      case "network delete":
        this.objects.delete(paths.network);
        break;
      default:
        throw new Error(`Unexpected mutation ${tag}`);
    }
    return Promise.resolve({ success: true, stdout: "" });
  };
}
const failure = { error_code: "ci_runner.host_failed" } as const;
async function prepared() {
  const host = new Incus();
  assertEquals(await convergeCiHost("converge", host.run), {
    outcome: "converged",
  });
  return host;
}

Deno.test("CI host creates the exact restricted Incus boundary and verifies it", async () => {
  const host = await prepared();
  const project = value(value(host.objects.get(paths.project)).config);
  assertEquals(project["features.networks"], "false");
  assertEquals(project["restricted.containers.privilege"], "unprivileged");
  assertEquals(project["restricted.containers.nesting"], "block");
  assertEquals(project["restricted.virtual-machines.lowlevel"], "block");
  assertEquals(project["limits.virtual-machines"], "0");
  assertEquals(host.objects.get(paths.pool), { driver: "dir" });
  const profile = value(host.objects.get(paths.profile));
  assertEquals(profile.config, {
    "boot.autostart": "true",
    "limits.cpu": "6",
    "limits.memory": "12GiB",
    "limits.processes": "8192",
    "security.nesting": "false",
    "security.privileged": "false",
  });
  assertEquals(profile.devices, {
    eth0: { name: "eth0", network: "orbitci0", type: "nic" },
    root: { path: "/", pool: "default", type: "disk" },
  });
  const acl = value(host.objects.get(paths.acl));
  assertEquals(acl.ingress, []);
  const rules = acl.egress as Record<string, string>[];
  assertEquals(rules.map((rule) => rule.action), ["reject", "reject", "allow"]);
  assertEquals(rules[0].destination.split(","), [
    "0.0.0.0/8",
    "10.0.0.0/8",
    "100.64.0.0/10",
    "127.0.0.0/8",
    "169.254.0.0/16",
    "172.16.0.0/12",
    "192.0.0.0/24",
    "192.168.0.0/16",
    "198.18.0.0/15",
    "224.0.0.0/3",
  ]);
  assertEquals(rules.slice(1), [{
    action: "reject",
    destination: "::/0",
    state: "enabled",
  }, { action: "allow", destination: "0.0.0.0/0", state: "enabled" }]);
  const create = host.changes().find((args) =>
    args[2] === "project" && args[3] === "create"
  );
  assertEquals(create?.slice(5), [
    "-c",
    "features.images=true",
    "-c",
    "features.profiles=true",
    "-c",
    "features.storage.volumes=true",
    "-c",
    "features.storage.buckets=true",
    "-c",
    "features.networks=false",
  ]);
  assertEquals(
    host.calls.filter((call) =>
      call.args[2] === "query" &&
      ["/1.0/networks", "/1.0/network-acls"].includes(call.args[3])
    ).length,
    0,
  );
});
Deno.test("convergence adopts the existing subnet, pool, images and running or stopped containers", async () => {
  const host = await prepared();
  value(value(host.objects.get(paths.network)).config)["ipv4.address"] =
    "10.253.2.1/24";
  host.objects.set(paths.pool, {
    driver: "zfs",
    config: { source: "fast/incus" },
  });
  host.objects.set(paths.instances, [
    "/1.0/instances/ci-shark-1?project=orbit-ci",
    "/1.0/instances/ci-shark-2?project=orbit-ci",
  ]);
  host.objects.set("/1.0/instances/ci-shark-1?project=orbit-ci", {
    status: "Running",
    config: { "volatile.base_image": "cache" },
  });
  host.objects.set("/1.0/instances/ci-shark-2?project=orbit-ci", {
    status: "Stopped",
  });
  host.objects.set("/1.0/images/cache?project=orbit-ci", {
    fingerprint: "cached-image",
  });
  const before = structuredClone(host.objects);
  host.calls = [];
  assertEquals(await convergeCiHost("converge", host.run), {
    outcome: "converged",
  });
  assertEquals(await convergeCiHost("converge", host.run), {
    outcome: "converged",
  });
  assertEquals(host.objects, before);
  assertEquals(
    host.changes().some((args) =>
      args.includes("create") || args.includes("delete")
    ),
    false,
  );
});
Deno.test("removal refuses even one stopped instance without a mutation", async () => {
  for (const status of ["Running", "Stopped"]) {
    const host = await prepared();
    host.objects.set(paths.instances, [
      `/1.0/instances/${status}?project=orbit-ci`,
    ]);
    host.objects.set(`/1.0/instances/${status}?project=orbit-ci`, { status });
    const before = structuredClone(host.objects);
    host.calls = [];
    assertEquals(await convergeCiHost("remove", host.run), {
      outcome: "occupied",
    });
    assertEquals(await convergeCiHost("purge", host.run), {
      outcome: "occupied",
    });
    assertEquals(host.objects, before);
    assertEquals(host.changes(), []);
  }
});
Deno.test("normal removal retains everything and purge deletes only empty reserved resources", async () => {
  const host = await prepared();
  const before = structuredClone(host.objects);
  assertEquals(await convergeCiHost("remove", host.run), {
    outcome: "retained",
  });
  assertEquals(host.objects, before);
  host.calls = [];
  assertEquals(await convergeCiHost("purge", host.run), { outcome: "removed" });
  assertEquals(host.changes().map((args) => args.slice(2)), [
    ["project", "delete", "orbit-ci"],
    ["network", "delete", "orbitci0"],
    ["network", "acl", "delete", "orbit-ci-egress"],
  ]);
  assertEquals(host.objects.get(paths.pool), { driver: "dir" });
  host.calls = [];
  assertEquals(await convergeCiHost("purge", host.run), { outcome: "removed" });
  assertEquals(host.changes(), []);
});
Deno.test("an Incus project-delete refusal preserves images and prevents network deletion", async () => {
  const host = await prepared();
  host.objects.set("/1.0/images/cache?project=orbit-ci", {
    fingerprint: "cache",
  });
  host.refuseProjectDelete = true;
  const before = structuredClone(host.objects);
  host.calls = [];
  assertEquals(await convergeCiHost("purge", host.run), failure);
  assertEquals(host.objects, before);
  assertEquals(host.changes().map((args) => args.slice(2)), [[
    "project",
    "delete",
    "orbit-ci",
  ]]);
});
Deno.test("foreign network isolation and unmanaged bridges refuse before their unsafe edits", async () => {
  const host = await prepared();
  value(value(host.objects.get(paths.project)).config)["features.networks"] =
    "true";
  host.calls = [];
  assertEquals(await convergeCiHost("converge", host.run), failure);
  assertEquals(host.changes(), []);
  value(value(host.objects.get(paths.project)).config)["features.networks"] =
    "false";
  for (
    const [key, invalid] of [["type", "macvlan"], ["managed", false]] as const
  ) {
    const current = value(host.objects.get(paths.network));
    current.type = "bridge";
    current.managed = true;
    current[key] = invalid;
    host.calls = [];
    assertEquals(await convergeCiHost("converge", host.run), failure);
    assertEquals(
      host.changes().some((args) =>
        args[2] === "profile" || (args[2] === "network" && args[3] === "set")
      ),
      false,
    );
  }
});
Deno.test("daemon errors, malformed lists, excessive output and failed verification cannot claim success", async () => {
  const host = new Incus();
  host.failQuery = true;
  assertEquals(await convergeCiHost("converge", host.run), failure);
  assertEquals(host.changes(), []);
  for (
    const stdout of ["not-json", "{}", '"missing"', " ".repeat(65537) + "[]"]
  ) {
    let calls = 0;
    assertEquals(
      await convergeCiHost("purge", () => {
        calls++;
        return Promise.resolve({ success: true, stdout });
      }),
      failure,
    );
    assertEquals(calls, 1);
  }
  const drift = new Incus();
  drift.refuseVerification = true;
  assertEquals(await convergeCiHost("converge", drift.run), failure);
});
Deno.test("cancellation prevents a first command or the next mutation, and uncertainty propagates", async () => {
  const host = new Incus();
  await assertRejects(() =>
    convergeCiHost("converge", host.run, AbortSignal.abort())
  );
  assertEquals(host.calls, []);
  const controller = new AbortController();
  host.signalAfterQuery = controller;
  await assertRejects(() =>
    convergeCiHost("converge", host.run, controller.signal)
  );
  assertEquals(host.calls.length, 1);
  assertEquals(host.changes(), []);
  await assertRejects(() =>
    convergeCiHost(
      "remove",
      () => Promise.reject(new Error("command outcome unknown")),
    )
  );
});

Deno.test("final verification accepts only Incus empty ACL defaults, not a changed boundary", async () => {
  const host = await prepared();
  host.aclEmptyFields = true;
  assertEquals(await convergeCiHost("converge", host.run), {
    outcome: "converged",
  });
  const run: SystemTransport = async (command, signal) => {
    const result = await host.run(command, signal);
    if (command.args.at(-1) === paths.acl && command.args[2] === "query") {
      const output = JSON.parse(result.stdout);
      output.egress[0].destination = "0.0.0.0/0";
      return { success: true, stdout: JSON.stringify(output) };
    }
    return result;
  };
  assertEquals(await convergeCiHost("converge", run), failure);
});
Deno.test("malformed occupancy is refused and missing reserved resources are not recreated by removal", async () => {
  const host = await prepared();
  host.objects.set(paths.instances, { status: "unknown" });
  host.calls = [];
  assertEquals(await convergeCiHost("purge", host.run), failure);
  assertEquals(host.changes(), []);
  const empty = new Incus();
  assertEquals(await convergeCiHost("remove", empty.run), {
    outcome: "retained",
  });
  assertEquals(await convergeCiHost("purge", empty.run), {
    outcome: "removed",
  });
  assertEquals(empty.changes(), []);
  assertEquals(empty.objects.size, 0);
});
