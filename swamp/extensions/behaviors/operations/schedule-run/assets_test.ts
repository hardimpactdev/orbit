import {
  assertEquals,
  assertStringIncludes,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import { behavior } from "./assets.ts";
import {
  legacyScheduleUnits,
  PublicInstall,
  renderScheduleUnits,
} from "../processes-schedules/contract.ts";

const schedule = {
  id: "123e4567-e89b-42d3-a456-426614174000",
  command_hash: "a".repeat(64),
  user: "orbit",
  group: "orbit",
  home: "/home/orbit",
  shell: "/bin/bash",
  working_directory: "/apps/proof",
  login_shell: false,
  calendar: "hourly",
  timeout_seconds: 10,
  desired: "disabled" as const,
};

Deno.test("lifecycle assets contain canonical public units and no private command field", async () => {
  const writes: unknown[] = [];
  const args = behavior.methods.lifecycle.arguments.parse({
    schedule,
  });
  const result = await behavior.methods.lifecycle.execute(args, {
    extensionFile: (path) => "/bundle/" + path,
    writeResource: async (...args: unknown[]) => {
      writes.push(args);
    },
  });
  assertEquals(result, renderScheduleUnits(schedule));
  assertEquals(writes.length, 1);
  assertThrows(() =>
    PublicInstall.parse({ ...schedule, command: "private-command" })
  );
});

Deno.test("shared unit templates preserve context quoting and a single login-shell flag", () => {
  const input = {
    ...schedule,
    home: '/home/space "quote"%$x',
    shell: "/bin/ba$sh",
    working_directory: "/apps/space%\\data",
    login_shell: true,
  };
  const legacy = legacyScheduleUnits(input);
  assertStringIncludes(
    legacy.directService,
    '"HOME=/home/space \\"quote\\"%%$x"',
  );
  assertStringIncludes(
    legacy.directService,
    '"SHELL=/bin/ba$sh" "USER=orbit" "LOGNAME=orbit" "/bin/ba$sh" --login ',
  );
  assertEquals(legacy.directService.match(/ --login/g)?.length, 1);
  assertStringIncludes(
    legacy.agentService,
    'Environment="HOME=/home/space \\"quote\\"%%$x"',
  );
  assertStringIncludes(
    legacy.script_prefix,
    JSON.stringify({
      user: input.user,
      group: input.group,
      home: input.home,
      working_directory: input.working_directory,
      shell: input.shell,
      login_shell: true,
    }),
  );
  const units = renderScheduleUnits(input);
  assertStringIncludes(units.service, "WorkingDirectory=/apps/space%%\\data\n");
  assertEquals(units.timer, legacy.timer);
});
