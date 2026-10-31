import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  assertCapturedDestroyPublication,
  verifyDestroySourcesWithMaterial,
} from "./destroy_material.ts";
import { fixture, inventory, member } from "./fixtures/destroy.ts";

Deno.test("unpublished source refuses before downstream effects and sanitizes private errors", async () => {
  const effects: string[] = [];
  const input = {
    force: false,
    members: [{ instance_id: 1, failed_creation: false }],
  };
  const capture = {
    members: [{
      instance_id: 1,
      origin: "https://github.com/example/proof.git",
      source_commit: "a".repeat(40),
    }],
  };
  const gateway = {
    withPrivateMaterial: async (
      use: (m: Record<string, unknown>) => Promise<void>,
    ) => {
      await use({ git_read: { github_token: "synthetic-private-token" } });
    },
  };
  await assertRejects(
    async () => {
      await assertCapturedDestroyPublication(
        input,
        capture,
        gateway,
        (_origin, _commit, git) => {
          assertEquals(
            (git as Record<string, unknown>).github_token,
            "synthetic-private-token",
          );
          return Promise.resolve(false);
        },
      );
      effects.push("runtime", "databases");
    },
    Error,
    "instance.unsafe_content",
  );
  assertEquals(effects, []);
  await assertRejects(
    () =>
      assertCapturedDestroyPublication(input, capture, gateway, () => {
        throw new Error("synthetic-private-token");
      }),
    Error,
    "instance.removal_refused",
  );
});

Deno.test("force and failed creation do not read Git material for publication", async () => {
  const gateway = {
    withPrivateMaterial: () => {
      throw new Error("unexpected private read");
    },
  };
  await assertCapturedDestroyPublication(
    { force: true, members: [] },
    {},
    gateway,
  );
  await assertCapturedDestroyPublication(
    { force: false, members: [{ instance_id: 1, failed_creation: true }] },
    { members: [] },
    gateway,
  );
});

Deno.test("post teardown refusal prevents the first database effect", async () => {
  const effects: string[] = [];
  const local = fixture();
  const gateway = {
    withPrivateMaterial: async (
      use: (m: Record<string, unknown>) => Promise<void>,
    ) => {
      await use({ git_read: { github_token: null } });
    },
  };
  await assertRejects(
    async () => {
      await verifyDestroySourcesWithMaterial(
        local,
        gateway,
        (raw) => {
          const input = raw as Record<string, unknown>;
          assertEquals(input.checkout, local.members[0].source.checkout);
          assertEquals(input.git_read, { github_token: null });
          throw new Error("instance.unsafe_content");
        },
        undefined,
        () => Promise.resolve({ state: "present" }),
      );
      effects.push("databases");
    },
    Error,
    "instance.unsafe_content",
  );
  assertEquals(effects, []);
});

Deno.test("publication cannot consume delayed or repeated private callbacks", async () => {
  let delayed: ((m: Record<string, unknown>) => Promise<void>) | undefined;
  let checks = 0;
  const input = {
    force: false,
    members: [{ instance_id: 1, failed_creation: false }],
  };
  const capture = {
    members: [{
      instance_id: 1,
      origin: "https://github.com/example/proof.git",
      source_commit: "a".repeat(40),
    }],
  };
  const gateway = {
    withPrivateMaterial: (
      use: (m: Record<string, unknown>) => Promise<void>,
    ) => {
      delayed = use;
      return Promise.resolve();
    },
  };
  await assertRejects(
    () =>
      assertCapturedDestroyPublication(input, capture, gateway, () => {
        checks++;
        return Promise.resolve(true);
      }),
    Error,
    "instance.removal_refused",
  );
  await delayed!({ git_read: { github_token: null } });
  assertEquals(checks, 0);
});

Deno.test("pre-DB verification reconciles receipt-authorized cleanup and filters only completed admitted peers", async () => {
  const local = fixture();
  local.members = [member(1), member(2, "worktree")];
  const calls: string[] = [];
  let cleaned = false;
  await verifyDestroySourcesWithMaterial(
    local,
    { withPrivateMaterial: (use) => use({ git_read: { github_token: null } }) },
    (raw) => {
      const value = raw as Record<string, unknown>;
      calls.push(String(value.checkout));
      assertEquals(value.worktrees, inventory(["/srv/apps/proof/main"]));
      return Promise.resolve({ absent: false });
    },
    undefined,
    (command, input) => {
      if (command === "instance.clean-removal-receipt") {
        cleaned = true;
        return Promise.resolve({ receipt: input.receipt });
      }
      return Promise.resolve({
        state: input.checkout === "/srv/apps/proof/work"
          ? "receipt_pending_cleanup"
          : "present",
      });
    },
  );
  assertEquals(cleaned, true);
  assertEquals(calls, ["/srv/apps/proof/main"]);
});
