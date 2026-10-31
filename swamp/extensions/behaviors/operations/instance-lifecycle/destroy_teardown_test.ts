import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { sha256 } from "../instance-deploy/app_step.ts";
import { runDestroyTeardown } from "./destroy_teardown.ts";

async function fixture() {
  const scripts = [
    "echo first-private-teardown-94281",
    "echo second-private-teardown-94281",
  ];
  const steps = await Promise.all(scripts.map(async (script, index) => ({
    name: `step-${index}`,
    timeout_seconds: 30,
    sha256: await sha256(script),
  })));
  const input = {
    node_id: 9,
    project_id: 12,
    environment: "development",
    node_user: "nckrtl",
    teardown: [{
      instance_id: 1,
      checkout: "/srv/apps/proof/main",
      user: "nckrtl",
      steps,
    }],
  };
  const material = {
    teardown: {
      members: [{
        instance_id: 1,
        steps: steps.map((row, index) => ({
          name: row.name,
          timeout_seconds: row.timeout_seconds,
          command: scripts[index],
        })),
      }],
    },
  };
  return { input, material, scripts };
}

Deno.test("destroy teardown reuses the owning list runner with only private callback scripts", async () => {
  const { input, material, scripts } = await fixture();
  const ran: unknown[] = [];
  const result = await runDestroyTeardown(input, 9, {
    read: () => Promise.resolve({ input, cancel_requested: false }),
    privateMaterial: { withPrivateMaterial: (execute) => execute(material) },
    run: (step, script) => {
      ran.push({
        user: step.target.variant === "checkout" ? step.target.user : "release",
        script,
      });
      return Promise.resolve({
        exit_code: 0,
        timed_out: false,
        output_tail: script,
        truncated: false,
      });
    },
  });
  assertEquals(
    ran,
    scripts.map((script) => ({ user: "nckrtl", script })),
  );
  assertEquals(result, { teardown_complete: true, instance_ids: [1] });
  assertEquals(
    scripts.some((script) => JSON.stringify(result).includes(script)),
    false,
  );
});

Deno.test("destroy teardown never substitutes another account or a foreign Node", async () => {
  const { input } = await fixture();
  let reads = 0;
  const port = {
    read: () => {
      reads++;
      return Promise.resolve({ input, cancel_requested: false });
    },
    privateMaterial: {
      withPrivateMaterial: () => {
        throw new Error("Unexpected private read");
      },
    },
  };
  await assertRejects(() => runDestroyTeardown(input, 209, port));
  await assertRejects(() =>
    runDestroyTeardown(
      { ...input, teardown: [{ ...input.teardown[0], user: "orbit" }] },
      9,
      port,
    )
  );
  assertEquals(reads, 0);
});

Deno.test("empty destroy teardown requires neither an account action nor private material", async () => {
  const { input } = await fixture();
  input.teardown[0].steps = [];
  const result = await runDestroyTeardown(input, 9, {
    read: () => {
      throw new Error("Unexpected read");
    },
    privateMaterial: {
      withPrivateMaterial: () => {
        throw new Error("Unexpected private read");
      },
    },
    run: () => {
      throw new Error("Unexpected app command");
    },
  });
  assertEquals(result, { teardown_complete: true, instance_ids: [1] });
});

Deno.test("changed admitted step digest refuses before a private read or effect", async () => {
  const { input } = await fixture();
  const changed = structuredClone(input);
  changed.teardown[0].steps[0].sha256 = "a".repeat(64);
  await assertRejects(
    () =>
      runDestroyTeardown(input, 9, {
        read: () =>
          Promise.resolve({ input: changed, cancel_requested: false }),
        privateMaterial: {
          withPrivateMaterial: () => {
            throw new Error("Unexpected private read");
          },
        },
        run: () => {
          throw new Error("Unexpected app command");
        },
      }),
    Error,
    "instance.step_not_admitted",
  );
});

Deno.test("a mismatched private script never reaches the app runner", async () => {
  const { input, material } = await fixture();
  material.teardown.members[0].steps[0].command = "echo foreign-private-649122";
  await assertRejects(
    () =>
      runDestroyTeardown(input, 9, {
        read: () => Promise.resolve({ input, cancel_requested: false }),
        privateMaterial: {
          withPrivateMaterial: (execute) => execute(material),
        },
        run: () => {
          throw new Error("Unexpected app command");
        },
      }),
    Error,
    "instance.step_not_admitted",
  );
});

Deno.test("app runner failure stops the list and returns only a bounded refusal", async () => {
  const { input, material, scripts } = await fixture();
  let calls = 0;
  const error = await assertRejects(() =>
    runDestroyTeardown(input, 9, {
      read: () => Promise.resolve({ input, cancel_requested: false }),
      privateMaterial: { withPrivateMaterial: (execute) => execute(material) },
      run: () => {
        calls++;
        throw new Error(scripts[0]);
      },
    })
  );
  assertEquals(calls, 1);
  assertEquals(String(error).includes(scripts[0]), false);
});

Deno.test("cancelled parent teardown admits no script", async () => {
  const { input } = await fixture();
  await assertRejects(
    () =>
      runDestroyTeardown(input, 9, {
        read: () => Promise.resolve({ input, cancel_requested: true }),
        privateMaterial: {
          withPrivateMaterial: () => {
            throw new Error("Unexpected private read");
          },
        },
        run: () => {
          throw new Error("Unexpected app command");
        },
      }),
    Error,
    "operation.cancelled",
  );
});
