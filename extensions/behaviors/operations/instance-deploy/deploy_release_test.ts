import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { NodeCall } from "../_lib/node-agent.ts";
import {
  activateDeployRelease,
  pruneDeployReleases,
} from "./deploy_release.ts";
import { DeploySourceInput } from "./deploy_source.ts";
function fixture() {
  const input = DeploySourceInput.parse({
    instance_id: 12,
    project_id: 9,
    agent_sha256: "a".repeat(64),
    commit: null,
    release_name: "admitted",
    deployment: {
      variant: "production",
      home: "/home/orbit-app-9",
      repository: "https://example.test/repo.git",
      branch: "main",
      node_user: "nckrtl",
      protocols: ["https"],
      user: "orbit-app-9",
      relative_root: "public",
      application_suffix: "",
      served: [],
      shared_paths: [],
    },
  });
  const release = { name: "admitted", commit: "b".repeat(40) };
  const previous = { name: "previous", commit: "c".repeat(40) };
  const calls: NodeCall[] = [];
  const port = {
    current: () => Promise.resolve({ cancel_requested: false }),
    now: () => 1000,
    invoke: (call: NodeCall): Promise<Record<string, unknown>> => {
      calls.push(call);
      return Promise.resolve(
        call.name === "instance.activate-release"
          ? release
          : call.name === "instance.inspect-release"
          ? { release }
          : { removed: ["old"], in_use: ["busy"] },
      );
    },
  };
  return { input, release, previous, port, calls };
}
Deno.test("deploy activates only the admitted production candidate and returns confirmed public identity", async () => {
  const f = fixture();
  assertEquals(
    await activateDeployRelease(f.input, f.release, 100000, f.port),
    { selected_release: f.release, selection_confirmed: true },
  );
  assertEquals(f.calls.length, 1);
  assertEquals(f.calls[0].name, "instance.activate-release");
  assertEquals(f.calls[0].input.name, "admitted");
  assertEquals(f.calls[0].input.user, "orbit-app-9");
  assertEquals(f.calls[0].input.commit, f.release.commit);
  assertEquals(f.calls[0].sudo && f.calls[0].mutates, true);
});
Deno.test("wrong candidate name, pinned commit or app identity refuses before release activation", async () => {
  for (const fault of ["name", "commit", "user", "home"] as const) {
    const f = fixture();
    if (fault === "name") f.release.name = "foreign";
    if (fault === "commit") f.input.commit = "d".repeat(40);
    if (fault === "user") f.input.deployment.user = "nckrtl";
    if (fault === "home") f.input.deployment.home = "/home/foreign";
    assertEquals(
      await activateDeployRelease(f.input, f.release, 100000, f.port),
      { error_code: "deployment.identity_changed", selection_confirmed: false },
    );
    assertEquals(f.calls.length, 0);
  }
});
Deno.test("cancelled or expired deployment starts no release action", async () => {
  for (const cancelled of [true, false]) {
    const f = fixture();
    f.port.current = () => Promise.resolve({ cancel_requested: cancelled });
    assertEquals(
      await activateDeployRelease(
        f.input,
        f.release,
        cancelled ? 100000 : 999,
        f.port,
      ),
      {
        error_code: cancelled
          ? "operation.cancelled"
          : "deployment.deadline_exceeded",
        selection_confirmed: false,
      },
    );
    assertEquals(f.calls.length, 0);
  }
});
Deno.test("lost or mismatched activation response never asserts selected release certainty", async () => {
  for (const mismatch of [true, false]) {
    const f = fixture();
    f.port.invoke = () => {
      if (!mismatch) throw new Error("private diagnostic");
      return Promise.resolve({ name: "foreign", commit: f.release.commit });
    };
    assertEquals(
      await activateDeployRelease(f.input, f.release, 100000, f.port),
      { error_code: "deployment.interrupted", selection_confirmed: false },
    );
  }
});
Deno.test("cleanup rechecks selected release and protects both selected and previous names", async () => {
  const f = fixture();
  assertEquals(
    await pruneDeployReleases(
      f.input,
      f.release,
      f.previous,
      3,
      100000,
      f.port,
    ),
    { removed: ["old"], in_use: ["busy"] },
  );
  assertEquals(f.calls.map((x) => x.name), [
    "instance.inspect-release",
    "instance.prune-releases",
  ]);
  assertEquals(f.calls[0].mutates, false);
  assertEquals(f.calls[1].input.limit, 3);
  assertEquals(f.calls[1].input.selected, f.release.name);
  assertEquals(f.calls[1].input.previous, f.previous.name);
});
Deno.test("changed current release refuses cleanup before pruning", async () => {
  const f = fixture();
  f.port.invoke = (call) => {
    f.calls.push(call);
    return Promise.resolve({ release: f.previous });
  };
  assertEquals(
    await pruneDeployReleases(
      f.input,
      f.release,
      f.previous,
      3,
      100000,
      f.port,
    ),
    { error_code: "deployment.selection_changed" },
  );
  assertEquals(f.calls.length, 1);
});
Deno.test("cleanup rejects receipts naming protected releases or duplicate removals", async () => {
  for (
    const receipt of [
      { removed: ["admitted"], in_use: [] },
      { removed: ["previous"], in_use: [] },
      { removed: ["old"], in_use: ["old"] },
      { removed: ["old", "old"], in_use: [] },
    ]
  ) {
    const f = fixture(), invoke = f.port.invoke;
    f.port.invoke = (call) =>
      call.name === "instance.prune-releases"
        ? Promise.resolve(receipt)
        : invoke(call);
    assertEquals(
      await pruneDeployReleases(
        f.input,
        f.release,
        f.previous,
        3,
        100000,
        f.port,
      ),
      { error_code: "deployment.cleanup_unconfirmed" },
    );
  }
});
Deno.test("development deploy does not activate or prune production releases", async () => {
  const f = fixture();
  f.input.deployment.variant = "development";
  assertEquals(await activateDeployRelease(f.input, null, 100000, f.port), {
    selected_release: null,
    selection_confirmed: true,
  });
  assertEquals(
    await pruneDeployReleases(f.input, null, null, 3, 100000, f.port),
    { removed: [], in_use: [] },
  );
  assertEquals(f.calls.length, 0);
});
