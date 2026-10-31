import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import {
  processAccountContent,
  ProcessAccountHome,
} from "./process-account.ts";
import { installProcess } from "./process-installation.ts";
import type { SystemTransport } from "../node/system_command.ts";

const content =
  `[Unit]\nX-Orbit-Process-ID=7\n[Service]\nUser=orbit\nEnvironment=VITE_DEV_SERVER_CERT=${ProcessAccountHome}/.orbit/certificates/app-instance-1/current/cert.pem\nExecStart="/usr/bin/env" "VITE_DEV_SERVER_KEY=${ProcessAccountHome}/.orbit/certificates/app-instance-1/current/key.pem" "/usr/bin/true"\n`;
const digest = async (value: string) =>
  Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value)),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
const base = {
  process_id: 7,
  name: "orbit-process-7-proof.service",
  runtime: "systemd" as const,
  agent_sha256: "a".repeat(64),
  content_hash: await digest(content),
  desired: "stopped" as const,
  on_demand: false,
  activation_pending: false,
  retired: null,
  account_user: "orbit",
};

Deno.test("Process account template uses the admitted account's actual home, without guessing its group", async () => {
  const calls: unknown[] = [];
  const transport: SystemTransport = (command) => {
    calls.push(command);
    return Promise.resolve({
      success: true,
      stdout: "orbit:x:123:456::/srv/managed-user:/bin/bash\n",
    });
  };
  const unit = await processAccountContent(content, "orbit", transport);
  assertEquals(calls, [{
    command: "/usr/bin/getent",
    args: ["passwd", "orbit"],
    timeoutMs: 10000,
  }]);
  assertEquals(unit.includes(ProcessAccountHome), false);
  assertEquals(unit.includes("/srv/managed-user/.orbit/certificates"), true);
});

Deno.test("Process account template escapes directive paths and ExecStart arguments separately", async () => {
  const home = '/srv/a b$%"c';
  const unit = await processAccountContent(
    content,
    "orbit",
    () =>
      Promise.resolve({
        success: true,
        stdout: `orbit:x:123:456::${home}:/bin/bash\n`,
      }),
  );
  assertEquals(
    unit.includes(
      "Environment=VITE_DEV_SERVER_CERT=/srv/a\\x20b\\x24%%\\x22c/",
    ),
    true,
  );
  assertEquals(unit.includes('"VITE_DEV_SERVER_KEY=/srv/a b$$%%\\"c/'), true);
});

Deno.test("Process account lookup refuses absent, root, foreign, ambiguous or unsafe account results", async () => {
  for (
    const stdout of [
      "",
      "orbit:x:0:0::/root:/bin/bash\n",
      "foreign:x:123:456::/srv/home:/bin/bash\n",
      "orbit:x:123:456::/srv/../other:/bin/bash\n",
      "orbit:x:123:456::/srv/home:/bin/bash\norbit:x:124:456::/srv/other:/bin/bash\n",
    ]
  ) {
    await assertRejects(() =>
      processAccountContent(
        content,
        "orbit",
        () => Promise.resolve({ success: true, stdout }),
      )
    );
  }
  let calls = 0;
  await assertRejects(() =>
    processAccountContent(content, "other", () => {
      calls++;
      return Promise.resolve({ success: true, stdout: "" });
    })
  );
  assertEquals(calls, 0);
});

Deno.test("Process installer checks the template hash before account discovery or effects", async () => {
  let effects = 0, lookups = 0;
  await assertRejects(() =>
    installProcess(
      { ...base, content_hash: "b".repeat(64) },
      {
        withPrivateMaterial: async (use) =>
          await use({
            installation: {
              runtime: "systemd",
              content,
              environment: "# private environment",
            },
          }),
      },
      undefined,
      () => {
        effects++;
        return Promise.resolve({});
      },
      () => {
        lookups++;
        return Promise.resolve(content);
      },
    )
  );
  assertEquals([effects, lookups], [0, 0]);
});

Deno.test("Process installer resolves private unit contents before staging and keeps environment bytes intact", async () => {
  const calls: Array<{ name: string; input: Record<string, unknown> }> = [];
  const outcome = await installProcess(
    base,
    {
      withPrivateMaterial: async (use) =>
        await use({
          installation: {
            runtime: "systemd",
            content,
            environment: "SECRET=private-only\n",
          },
        }),
    },
    undefined,
    (name, input) => {
      calls.push({ name, input });
      return Promise.resolve(
        name === "process.stage-unit"
          ? { staged: true, previous: false, state: null }
          : name === "process.activate-unit"
          ? { backup_left: false }
          : { state: "inactive" },
      );
    },
    (value, user, signal) =>
      processAccountContent(value, user, () =>
        Promise.resolve({
          success: true,
          stdout: "orbit:x:123:456::/custom/home:/bin/bash\n",
        }), signal),
  );
  assertEquals(outcome, { ready: true, state: "inactive" });
  assertEquals(
    String(calls[0].input.content).includes("/custom/home/.orbit/"),
    true,
  );
  assertEquals(calls[0].input.environment, "SECRET=private-only\n");
  assertEquals(JSON.stringify(outcome).includes("private-only"), false);
});

Deno.test("Process account failure happens before any unit or retired-runtime effect", async () => {
  let effects = 0;
  await assertRejects(() =>
    installProcess(
      { ...base, retired: { runtime: "docker", name: "orbit-process-7-old" } },
      {
        withPrivateMaterial: async (use) =>
          await use({
            installation: {
              runtime: "systemd",
              content,
              environment: "# private",
            },
          }),
      },
      undefined,
      () => {
        effects++;
        return Promise.resolve({});
      },
      () => Promise.reject(new Error("unavailable")),
    )
  );
  assertEquals(effects, 0);
});
