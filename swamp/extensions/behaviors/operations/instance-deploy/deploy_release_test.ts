import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type LocalCall, localFixture } from "./deploy_test_fixture.ts";
import {
  activateDeployRelease,
  inspectRetainedDeployRelease,
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
  const calls: LocalCall[] = [];
  const port = {
    current: () => Promise.resolve({ cancel_requested: false }),
    now: () => 1000,
    dispatch: (call: LocalCall): Promise<Record<string, unknown>> => {
      calls.push(call);
      return Promise.resolve(
        call.name === "activateRelease"
          ? release
          : call.name === "inspectRelease"
          ? { release }
          : { removed: ["old"], in_use: ["busy"] },
      );
    },
  };
  const local = localFixture((call) => port.dispatch(call));
  return {
    input,
    release,
    previous,
    calls,
    port: {
      ...port,
      local,
      get dispatch() {
        return port.dispatch;
      },
      set dispatch(value) {
        port.dispatch = value;
      },
    },
  };
}
Deno.test("deploy activates only the admitted production candidate and returns confirmed public identity", async () => {
  const f = fixture();
  assertEquals(
    await activateDeployRelease(f.input, f.release, 100000, f.port),
    { selected_release: f.release, selection_confirmed: true },
  );
  assertEquals(f.calls.length, 1);
  assertEquals(f.calls[0].name, "activateRelease");
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
    f.port.dispatch = () => {
      if (!mismatch) throw new Error("private diagnostic");
      return Promise.resolve({ name: "foreign", commit: f.release.commit });
    };
    assertEquals(
      await activateDeployRelease(f.input, f.release, 100000, f.port),
      {
        error_code: mismatch
          ? "deployment.interrupted"
          : "deployment.execution_unconfirmed",
        selection_confirmed: false,
      },
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
    "inspectRelease",
    "pruneReleases",
  ]);
  assertEquals(f.calls[0].mutates, false);
  assertEquals(f.calls[1].input.limit, 3);
  assertEquals(f.calls[1].input.selected, f.release.name);
  assertEquals(f.calls[1].input.previous, f.previous.name);
});
Deno.test("changed current release refuses cleanup before pruning", async () => {
  const f = fixture();
  f.port.dispatch = (call) => {
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
    const f = fixture(), dispatch = f.port.dispatch;
    f.port.dispatch = (call) =>
      call.name === "pruneReleases" ? Promise.resolve(receipt) : dispatch(call);
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

Deno.test("retained release inspection is read only and supports already selected rollback", async () => {
  const f = fixture();
  f.input.deployment.variant = "rollback";
  assertEquals(
    await inspectRetainedDeployRelease(f.input, f.release.name, 100000, f.port),
    { release: f.release, previous: f.release },
  );
  assertEquals(f.calls.map((call) => call.name), [
    "inspectRelease",
    "inspectRelease",
  ]);
  assertEquals(f.calls.every((call) => !call.mutates), true);
  assertEquals(f.calls[1].input.name, f.release.name);
  assertEquals(
    await activateDeployRelease(f.input, f.release, 100000, f.port),
    { selected_release: f.release, selection_confirmed: true },
  );
});

Deno.test("retained release inspection refuses missing wrong or changed target without mutation", async () => {
  for (const fault of ["missing", "name", "commit"] as const) {
    const f = fixture();
    f.input.deployment.variant = "rollback";
    if (fault === "commit") f.input.commit = "d".repeat(40);
    f.port.dispatch = (call) => {
      f.calls.push(call);
      return Promise.resolve({
        release: call.input.name === undefined
          ? f.previous
          : fault === "missing"
          ? null
          : fault === "name"
          ? f.previous
          : f.release,
      });
    };
    const result = await inspectRetainedDeployRelease(
      f.input,
      f.release.name,
      100000,
      f.port,
    );
    assertEquals(result, {
      error_code: fault === "missing"
        ? "rollback.release_invalid"
        : "deployment.identity_changed",
    });
    assertEquals(f.calls.every((call) => !call.mutates), true);
  }
});

Deno.test("rollback release cleanup is never performed", async () => {
  const f = fixture();
  f.input.deployment.variant = "rollback";
  assertEquals(
    await pruneDeployReleases(
      f.input,
      f.release,
      f.previous,
      3,
      100000,
      f.port,
    ),
    { error_code: "deployment.identity_changed" },
  );
  assertEquals(f.calls, []);
});
