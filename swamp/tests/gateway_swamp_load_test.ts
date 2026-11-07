import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

const source = await Deno.readTextFile(
  new URL(
    "../workflows/workflow-operation-gateway-swamp-load.yaml",
    import.meta.url,
  ),
);
const workflow = parse(source) as {
  jobs: { steps: { name: string; task: { inputs: { run: string } } }[] }[];
};
const run =
  workflow.jobs[0].steps.find((step) => step.name === "load")!.task.inputs
    .run;

Deno.test("the release load compares only the commit's workflows, never a Schedule's", async () => {
  const filter = /^\s*tracked='([^']+)'$/m.exec(run)?.[1];
  assertEquals(typeof filter, "string");
  const listed = {
    results: [
      { id: "b", name: "operation-route-create" },
      { id: "s", name: "schedule-077c43f7-dd1c-4576-af12-b269e83cddfe" },
      { id: "a", name: "operation-schedule-create" },
    ],
  };
  const jq = new Deno.Command("jq", {
    args: ["-ec", filter!],
    stdin: "piped",
    stdout: "piped",
  }).spawn();
  const writer = jq.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(listed)));
  await writer.close();
  const output = await jq.output();
  assertEquals(output.success, true);
  assertEquals(JSON.parse(new TextDecoder().decode(output.stdout)), [
    ["a", "operation-schedule-create"],
    ["b", "operation-route-create"],
  ]);
  assertEquals(run.includes('jq -ec "$tracked"'), true);
});

type Step = {
  name: string;
  dependsOn?: { step: string }[];
  task: { inputs: { run: string; env?: Record<string, string> } };
};
const steps = (workflow.jobs[0].steps as unknown) as Step[];
const collections = steps.find((step) => step.name === "collections")!;

/** Runs the collections step in a folder of its own: `sudo` runs its command, and `ansible-galaxy` records its arguments or fails. */
async function installCollections(pin: string | null, galaxyFails = false) {
  const folder = await Deno.makeTempDir();
  const bin = `${folder}/bin`;
  await Deno.mkdir(bin);
  await Deno.writeTextFile(
    `${bin}/sudo`,
    '#!/bin/sh\n[ "$1" = -n ] && shift\nexec "$@"\n',
  );
  await Deno.writeTextFile(
    `${bin}/galaxy`,
    `#!/bin/sh\necho "$@" > "${folder}/galaxy-args"\n${
      galaxyFails ? "exit 1" : "exit 0"
    }\n`,
  );
  for (const tool of ["sudo", "galaxy"]) {
    await Deno.chmod(`${bin}/${tool}`, 0o755);
  }
  const requirements = `${folder}/requirements.yml`;
  await Deno.writeTextFile(
    requirements,
    "collections:\n  - {name: ansible.posix, version: 2.2.2}\n",
  );
  if (pin !== null) await Deno.writeTextFile(`${folder}/pin.yml`, pin);
  const done = await new Deno.Command("sh", {
    args: ["-c", collections.task.inputs.run],
    env: {
      PATH: `${bin}:${Deno.env.get("PATH")}`,
      REQUIREMENTS: requirements,
      PIN: `${folder}/pin.yml`,
      COLLECTIONS: `${folder}/collections`,
      GALAXY: `${bin}/galaxy`,
    },
    stdout: "piped",
  }).output();
  const read = (name: string) =>
    Deno.readTextFile(`${folder}/${name}`).catch(() => null);
  const result = {
    success: done.success,
    stdout: new TextDecoder().decode(done.stdout).trim(),
    galaxy: await read("galaxy-args"),
    pin: await read("pin.yml"),
    requirements,
    folder,
  };
  await Deno.remove(folder, { recursive: true });
  return result;
}

Deno.test("the release load installs the collections the swamp role pins, before serve loads workflows that may need them", () => {
  assertEquals(
    collections.task.inputs.env?.PIN,
    "/opt/orbit/ansible/requirements.yml",
  );
  assertEquals(
    collections.task.inputs.env?.COLLECTIONS,
    "/opt/orbit/ansible/collections",
  );
  const load = steps.find((step) => step.name === "load")!;
  assertEquals(
    load.dependsOn?.map((d) => d.step).includes("collections"),
    true,
  );
});

Deno.test("a release with a new requirements.yml installs its collections and moves the pin", async () => {
  const run = await installCollections(
    "collections:\n  - {name: community.general, version: 12.0.1}\n",
  );
  assertEquals(run.success, true);
  assertEquals(run.stdout, "installed");
  assertEquals(
    run.galaxy?.trim(),
    `collection install --upgrade -r ${run.requirements} -p ${run.folder}/collections`,
  );
  assertEquals(
    run.pin,
    "collections:\n  - {name: ansible.posix, version: 2.2.2}\n",
  );
});

Deno.test("a release whose requirements.yml matches the pin installs nothing", async () => {
  const run = await installCollections(
    "collections:\n  - {name: ansible.posix, version: 2.2.2}\n",
  );
  assertEquals(run.success, true);
  assertEquals(run.stdout, "unchanged");
  assertEquals(run.galaxy, null);
});

Deno.test("a failed install keeps the old pin, so the next release tries again", async () => {
  const run = await installCollections(null, true);
  assertEquals(run.success, false);
  assertEquals(run.pin, null);
});
