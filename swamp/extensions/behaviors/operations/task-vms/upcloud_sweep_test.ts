import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type CloudRequest,
  cloudRequest,
  sweepUpCloud,
} from "./upcloud_sweep.ts";
import { SweepIntent } from "./sweep.ts";
const uuid = "0753940c-1382-456a-9b45-63601e6c7dd1";
const input = () =>
  SweepIntent.parse({
    node_id: 14,
    ci_runner: false,
    stray_vm_ids: [],
    provider: "upcloud",
    deadline_at: 4000000000,
    agent_sha256: "a".repeat(64),
    host: null,
    retired_build_ids: [],
    live_names: [],
    protected_build_ids: [],
    recorded_build_ids: [],
    running_stamps: [],
  });
class Cloud {
  input = input();
  server: Record<string, unknown> | null = {
    uuid,
    title: "tvm-90",
    hostname: "tvm-90",
    state: "started",
    labels: { label: [{ key: "orbit-task-vm", value: "90" }] },
  };
  template: Record<string, unknown> | null = null;
  calls: { method: string; path: string; body?: Record<string, unknown> }[] =
    [];
  beforeRead = () => {};
  waits = 0;
  cancelled = false;
  gateway = {
    read: () => {
      this.beforeRead();
      return Promise.resolve({
        status: "running",
        cancel_requested: this.cancelled,
        input: structuredClone(this.input),
      });
    },
    withPrivateMaterial: () =>
      Promise.reject(new Error("Unit fixture uses no real credential")),
  };
  request: CloudRequest = (method, path, body) => {
    this.calls.push({ method, path, ...(body ? { body } : {}) });
    if (path.startsWith("server?label=")) {
      const label = decodeURIComponent(path.slice("server?label=".length));
      const rows = this.server &&
          (this.server.labels as { label: { key: string }[] }).label.some((
            entry,
          ) => entry.key === label)
        ? [this.server]
        : [];
      return Promise.resolve({ servers: { server: structuredClone(rows) } });
    }
    if (path === "storage/template") {
      return Promise.resolve({
        storages: {
          storage: this.template ? [structuredClone(this.template)] : [],
        },
      });
    }
    if (path === `storage/${uuid}`) {
      if (method === "DELETE") {
        this.template = null;
        return Promise.resolve({});
      }
      return Promise.resolve(
        this.template ? { storage: structuredClone(this.template) } : null,
      );
    }
    if (path === `server/${uuid}` && method === "GET") {
      return Promise.resolve(
        this.server ? { server: structuredClone(this.server) } : null,
      );
    }
    if (path === `server/${uuid}/stop`) {
      this.server!.state = "stopped";
      return Promise.resolve({});
    }
    if (path === `server/${uuid}?storages=1&backups=delete`) {
      this.server = null;
      return Promise.resolve({});
    }
    throw new Error(`Unexpected provider request ${method} ${path}`);
  };
  execute() {
    return sweepUpCloud(
      this.input,
      this.gateway,
      undefined,
      this.request,
      () => {
        this.waits++;
        return Promise.resolve();
      },
      () => Date.parse("2026-10-31T01:00:00Z"),
    );
  }
  effects() {
    return this.calls.filter((call) => call.method !== "GET");
  }
}
Deno.test("UpCloud native sweep verifies ownership, hard-stops, observes stopped, deletes storage and proves absence", async () => {
  const f = new Cloud();
  assertEquals(await f.execute(), {
    ready: true,
    observed_names: [],
    deleted_names: ["tvm-90"],
  });
  assertEquals(f.effects(), [
    {
      method: "POST",
      path: `server/${uuid}/stop`,
      body: { stop_server: { stop_type: "hard" } },
    },
    { method: "DELETE", path: `server/${uuid}?storages=1&backups=delete` },
  ]);
});
Deno.test("UpCloud live rows and operator names are never deleted", async () => {
  for (const mode of ["live", "operator", "wrong-label"]) {
    const f = new Cloud();
    if (mode === "live") f.input.live_names = ["tvm-90"];
    if (mode === "operator") {
      f.server!.title = "operator-vm";
      f.server!.hostname = "operator-vm";
    }
    if (mode === "wrong-label") {
      f.server!.labels = { label: [{ key: "operator", value: "90" }] };
    }
    await f.execute();
    assertEquals(f.effects(), []);
  }
});
Deno.test("UpCloud rechecks a new live row after provider inventory before mutation", async () => {
  const f = new Cloud();
  f.beforeRead = () => {
    f.input.live_names = ["tvm-90"];
  };
  await f.execute();
  assertEquals(f.effects(), []);
});
Deno.test("UpCloud exact UUID/name/hostname/label disagreement refuses a destructive detail", async () => {
  for (const field of ["hostname", "uuid"]) {
    const f = new Cloud();
    const request = f.request;
    f.request = async (method, path, body, missing) => {
      const raw = await request(method, path, body, missing);
      if (path === `server/${uuid}` && raw?.server) {
        (raw.server as Record<string, unknown>)[field] = field === "uuid"
          ? "a4bfe8d2-7514-4e48-a3c4-d230f31bf4eb"
          : "operator-vm";
      }
      return raw;
    };
    const result = await f.execute();
    assertEquals(
      "error_code" in result ? result.error_code : null,
      "task_vm.host_command_failed",
    );
    assertEquals(f.effects(), []);
  }
});
Deno.test("UpCloud image servers retain recent or running timestamps and remove only stale owned builders", async () => {
  for (const stamp of ["20261030220000", "20261031003000"]) {
    const f = new Cloud();
    f.server!.title = `tvm-image-${stamp}`;
    f.server!.hostname = f.server!.title;
    f.server!.labels = { label: [{ key: "orbit-task-image", value: stamp }] };
    if (stamp === "20261030220000") f.input.running_stamps = [stamp];
    await f.execute();
    assertEquals(f.effects(), []);
  }
});
Deno.test("UpCloud deletes only stale private unverified templates and keeps verified/public/running ones", async () => {
  for (
    const mode of ["stale", "verified", "public", "running", "invalid-date"]
  ) {
    const f = new Cloud();
    f.server = null;
    f.template = {
      uuid,
      title: "orbit-task-base-20261030220000-unverified",
      access: "private",
    };
    if (mode === "verified") {
      f.template.title = "orbit-task-base-20261030220000";
    }
    if (mode === "public") f.template.access = "public";
    if (mode === "running") f.input.running_stamps = ["20261030220000"];
    if (mode === "invalid-date") {
      f.template.title = "orbit-task-base-20260230000000-unverified";
    }
    await f.execute();
    assertEquals(f.effects().length, mode === "stale" ? 1 : 0);
  }
});
Deno.test("a repeated native run after provider absence performs no new deletion", async () => {
  const f = new Cloud();
  await f.execute();
  const count = f.effects().length;
  await f.execute();
  assertEquals(f.effects().length, count);
});
Deno.test("provider credentials stay callback-local and never appear in paths or bounded errors", async () => {
  const token = "ucat_fixture_private_sweep";
  const gateway = {
    read: () => Promise.resolve({}),
    withPrivateMaterial: async (
      callback: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      try {
        await callback({ upcloud_token: token });
      } catch {
        throw new Error("Private material callback failed");
      }
    },
  };
  const seen: string[] = [];
  const request = cloudRequest(
    gateway,
    undefined,
    ((url: string | URL | Request, init?: RequestInit) => {
      seen.push(String(url));
      assertEquals(
        init?.headers && (init.headers as Record<string, string>).Authorization,
        `Bearer ${token}`,
      );
      assertEquals(init?.redirect, "error");
      throw new Error(`provider error holding ${token}`);
    }) as typeof fetch,
  );
  await assertRejects(
    () => request("GET", "server?label=orbit-task-vm"),
    Error,
    "Private material callback failed",
  );
  assertEquals(seen, [
    "https://api.upcloud.com/1.3/server?label=orbit-task-vm",
  ]);
});

Deno.test("a definite provider HTTP refusal survives private callback redaction as a bounded code", async () => {
  const gateway = {
    read: () => Promise.resolve({}),
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      try {
        await use({ upcloud_token: "ucat_fixture_private_sweep" });
      } catch {
        throw new Error("Private material callback failed");
      }
    },
  };
  const request = cloudRequest(
    gateway,
    undefined,
    (() =>
      Promise.resolve(
        new Response("private provider diagnostic", { status: 403 }),
      )) as typeof fetch,
  );
  await assertRejects(
    () => request("GET", "server?label=orbit-task-vm"),
    Error,
    "task_vm.host_command_failed",
  );
});
Deno.test("API cancellation waits for an accepted provider stop to settle before claiming no running effect", async () => {
  const f = new Cloud();
  const original = f.request;
  f.request = async (method, path, body, missing) => {
    const reply = await original(method, path, body, missing);
    if (path.endsWith("/stop")) {
      f.server!.state = "maintenance";
      f.cancelled = true;
    }
    return reply;
  };
  let settled = false;
  await assertRejects(
    () =>
      sweepUpCloud(f.input, f.gateway, undefined, f.request, () => {
        if (f.cancelled) {
          f.server!.state = "stopped";
          settled = true;
        }
        return Promise.resolve();
      }),
    Error,
    "Direct Operation cancelled with no effect running",
  );
  assertEquals(settled, true);
  assertEquals(f.effects().map((row) => row.method), ["POST"]);
});
