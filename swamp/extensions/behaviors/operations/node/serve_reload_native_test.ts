/** Isolated loopback reload contract, with no admitted effects or workflow run. */
import { assert, assertEquals, fail } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";

Deno.test("reload reconciles externally indexed local behavior without a run", async () => {
    await using repo = await nativeRepo();
    repo.env.SWAMP_HOME = `${repo.dir}/home`;
    await Deno.writeTextFile(
        `${repo.dir}/.swamp.yaml`,
        "swampVersion: 20261031.063736.0-sha.e459d219\nrepoId: 0ef253a3-8e75-4c0e-a680-e31936096a45\ntelemetryDisabled: true\n",
    );
    const source = `${repo.dir}/extensions/behaviors/probe.ts`;
    await Deno.mkdir(`${repo.dir}/extensions/behaviors`, { recursive: true });
    const behavior = (version: string) =>
        `import { z } from "npm:zod@4.4.3";
export const behavior = {type:"@nckrtl/reload-probe",version:"${version}",globalArguments:z.object({}),resources:{},methods:{inspect:{description:"Probe",arguments:z.object({}),execute:async()=>({dataHandles:[]})}}};\n`;
    const workflow = (description: string) =>
        `id: f4b456ba-6e1e-4524-8e8e-b445f1d07ea4
name: reload-probe
description: ${description}
labels: {pool: migration}
vaults: []
jobs:
  - name: probe
    steps:
      - name: inspect
        task:
          type: behavior
          behaviorType: "@nckrtl/reload-probe"
          behaviorName: reload-probe
          methodName: inspect
`;
    await Deno.writeTextFile(source, behavior("2026.10.30.1"));
    const definition = `${repo.dir}/workflows/workflow-probe.yaml`;
    await Deno.writeTextFile(definition, workflow("Before reload"));
    const socket = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const port = (socket.addr as Deno.NetAddr).port;
    socket.close();
    const proc = await repo.spawn([
        "serve",
        "--port",
        String(port),
        "--auth-mode",
        "none",
        "--no-schedule",
        "--remote-only",
        "--hot-reload",
        "--repo-dir",
        repo.dir,
    ]);
    const output = proc.output();
    let stopped = false;
    const status = proc.status.then((value) => {
        stopped = true;
        return value;
    });
    try {
        const deadline = Date.now() + 30_000;
        while (true) {
            try {
                const response = await fetch(
                    `http://127.0.0.1:${port}/health`,
                    { signal: AbortSignal.timeout(1000) },
                );
                await response.body?.cancel();
                if (response.ok) break;
            } catch {
                // Startup has not bound the loopback socket yet.
            }
            if (stopped || Date.now() >= deadline) {
                fail("Serve failed to become healthy");
            }
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        const endpoint = ["--server", `ws://127.0.0.1:${port}`];
        const describe = async () =>
            JSON.parse(
                (await repo.cli([
                    "model",
                    "type",
                    "describe",
                    "@nckrtl/reload-probe",
                    ...endpoint,
                ])).stdout,
            );
        assertEquals((await describe()).version, "2026.10.30.1");
        await Deno.writeTextFile(source, behavior("2026.10.30.2"));
        await Deno.writeTextFile(definition, workflow("After reload"));
        await Deno.writeTextFile(
            `${repo.dir}/extensions/behaviors/new.ts`,
            behavior("2026.10.30.1").replace(
                "@nckrtl/reload-probe",
                "@nckrtl/reload-added",
            ),
        );
        // Another CLI advances the on-disk catalog before the serve process reloads.
        const indexed = JSON.parse(
            (await repo.cli([
                "model",
                "type",
                "describe",
                "@nckrtl/reload-probe",
            ])).stdout,
        );
        assertEquals(indexed.version, "2026.10.30.2");
        const response = JSON.parse(
            (await repo.cli(["serve", "reload", ...endpoint])).stdout,
        );
        assertEquals(response.success, true);
        assertEquals(response.errors, []);
        const visible = JSON.parse(
            (await repo.cli(["workflow", "get", "reload-probe", ...endpoint]))
                .stdout,
        );
        assertEquals(visible.description, "After reload");
        assertEquals((await describe()).version, "2026.10.30.2");
        const added = JSON.parse(
            (await repo.cli([
                "model",
                "type",
                "describe",
                "@nckrtl/reload-added",
                ...endpoint,
            ])).stdout,
        );
        assertEquals(added.version, "2026.10.30.1");
        assert(!stopped);
        assertEquals(
            Number(await Deno.readTextFile(`${repo.dir}/.swamp/serve.pid`)),
            proc.pid,
        );
    } finally {
        if (!stopped) proc.kill("SIGTERM");
        let timer: ReturnType<typeof setTimeout> | undefined;
        const exited = await Promise.race([
            status.then(() => true),
            new Promise<boolean>((resolve) => {
                timer = setTimeout(() => resolve(false), 15_000);
            }),
        ]);
        clearTimeout(timer);
        if (!exited) proc.kill("SIGKILL");
        const result = await output;
        if (result.code !== 0) {
            console.error(new TextDecoder().decode(result.stderr));
        }
    }
});
