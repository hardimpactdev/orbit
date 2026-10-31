import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
import { digest } from "./image_guest.ts";
import { uploadTaskVmPiArtifact } from "./pi_delivery.ts";

async function fixture(length = 64) {
  const bytes = new Uint8Array(length).fill(42);
  const sha256 = await digest(bytes);
  const calls: SystemCommand[] = [];
  let reads = 0;
  const material = {
    artifact: (field: string, sha: string) => {
      reads++;
      assertEquals(field, "pi_artifact_file");
      assertEquals(sha, sha256);
      return Promise.resolve(bytes);
    },
  };
  const transport: SystemTransport = (call) => {
    calls.push(call);
    const request = JSON.parse(call.stdin!);
    assertEquals(request.name, "pi.receive-artifact");
    assertEquals(call.args.slice(0, 4), ["-n", "--", "/usr/bin/python3", "-c"]);
    const input = request.input;
    assertEquals(input.sha256, sha256);
    const received = input.offset + atob(input.bytes).length;
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({
        received_bytes: received,
        verified: received === input.total,
      }),
    });
  };
  return { bytes, sha256, calls, material, transport, reads: () => reads };
}

Deno.test("Pi parent delivery uses bounded native chunks and returns only the pin", async () => {
  const f = await fixture(2097152 + 17);
  let guards = 0;
  assertEquals(
    await uploadTaskVmPiArtifact(
      { sha256: f.sha256 },
      () => {
        guards++;
        return Promise.resolve();
      },
      f.material,
      f.transport,
    ),
    { uploaded: true, sha256: f.sha256 },
  );
  assertEquals(f.calls.length, 2);
  assertEquals(guards, 3);
  assertEquals(f.reads(), 1);
  assertEquals(f.calls.map((c) => JSON.parse(c.stdin!).input.offset), [
    0,
    2097152,
  ]);
  assertEquals(f.calls.every((c) => c.command === "/usr/bin/sudo"), true);
});

Deno.test("Pi parent delivery refuses a digest mismatch before local mutation", async () => {
  const f = await fixture();
  await assertRejects(
    () =>
      uploadTaskVmPiArtifact({ sha256: f.sha256 }, async () => {}, {
        artifact: () => Promise.resolve(new Uint8Array([1])),
      }, f.transport),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.calls, []);
});

Deno.test("Pi parent delivery refuses empty artifacts and invalid pins", async () => {
  const f = await fixture();
  await assertRejects(
    () =>
      uploadTaskVmPiArtifact(
        { sha256: "private-invalid-pin" },
        async () => {},
        f.material,
        f.transport,
      ),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.reads(), 0);
  await assertRejects(
    () =>
      uploadTaskVmPiArtifact({ sha256: f.sha256 }, async () => {}, {
        artifact: () => Promise.resolve(new Uint8Array()),
      }, f.transport),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.calls, []);
});

Deno.test("Pi parent delivery guards cancellation before download and every chunk", async () => {
  const f = await fixture(2097152 + 1);
  const controller = new AbortController();
  controller.abort(new Error("private-cancellation-reason"));
  await assertRejects(
    () =>
      uploadTaskVmPiArtifact(
        { sha256: f.sha256 },
        async () => {},
        f.material,
        f.transport,
        controller.signal,
      ),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.reads(), 0);
  let guards = 0;
  await assertRejects(
    () =>
      uploadTaskVmPiArtifact(
        { sha256: f.sha256 },
        () => {
          if (++guards === 3) {
            return Promise.reject(
              new Error("private-supersession-detail"),
            );
          }
          return Promise.resolve();
        },
        f.material,
        f.transport,
      ),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.calls.length, 1);
});

Deno.test("Pi parent delivery rejects malformed or false native progress without retry", async () => {
  const f = await fixture();
  for (
    const result of [{}, { received_bytes: 0, verified: false }, {
      received_bytes: 64,
      verified: false,
    }, { received_bytes: 65, verified: true }]
  ) {
    let attempts = 0;
    await assertRejects(
      () =>
        uploadTaskVmPiArtifact(
          { sha256: f.sha256 },
          async () => {},
          f.material,
          () => {
            attempts++;
            return Promise.resolve({
              success: true,
              stdout: JSON.stringify(result),
            });
          },
        ),
      Error,
      "task_vm.pi_artifact_failed",
    );
    assertEquals(attempts, 1);
  }
});

Deno.test("Pi parent delivery redacts private transport and material errors", async () => {
  const f = await fixture();
  const secret = "private-transport-credential";
  await assertRejects(
    () =>
      uploadTaskVmPiArtifact({ sha256: f.sha256 }, async () => {}, {
        artifact: () => Promise.reject(new Error(secret)),
      }, f.transport),
    Error,
    "task_vm.pi_artifact_failed",
  );
  await assertRejects(
    () =>
      uploadTaskVmPiArtifact(
        { sha256: f.sha256 },
        async () => {},
        f.material,
        () => Promise.reject(new Error(secret)),
      ),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.calls, []);
});
