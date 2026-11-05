/**
 * The host scripts of a seed refresh against a fake `incus` and `zfs`: the promotion, its cleanup and the crash matrix. The
 * fake keeps volumes and VMs as files; a volume's file holds its label, so a test sees which seed sits under which name.
 */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.14";

const tools = new URL("../../tools/task-vm-seed/", import.meta.url).pathname;
const FAKE_INCUS = `#!/usr/bin/env bash
set -u
state=$FAKE
[[ $1 == --project ]] && shift 2
case "$1 \${2:-} \${3:-}" in
  "storage get "*) echo "fast/$3"; exit 0 ;;
esac
case "$1" in
  info) [[ -e $state/inst/$2 ]]; exit ;;
  delete) rm -f "$state/inst/\${@: -1}"; exit 0 ;;
  list) ls "$state/inst"; exit 0 ;;
esac
[[ $1 == storage && $2 == volume ]] || exit 2
shift 2
case "$1" in
  show) [[ -e $state/vol/$3 ]]; exit ;;
  delete) rm -f "$state/vol/$3"; exit 0 ;;
  list) for v in $(ls "$state/vol"); do echo "custom,$v"; done; exit 0 ;;
  rename)
    n=$(($(cat "$state/renames" 2>/dev/null || echo 0) + 1)); echo $n > "$state/renames"
    [[ \${FAKE_SLEEP_RENAME:-} ]] && sleep "$FAKE_SLEEP_RENAME"
    [[ \${FAKE_FAIL_RENAME:-} == "$n" ]] && exit 1
    mv "$state/vol/$3" "$state/vol/$4"
    [[ \${FAKE_KILL_RENAME:-} == "$n" ]] && kill -KILL $PPID
    exit 0 ;;
esac
exit 2
`;
const FAKE_ZFS = `#!/usr/bin/env bash
case "$*" in
  *"value used"*) echo 111 ;;
  *"value referenced"*) echo 222 ;;
  promote*) echo "$2" >> "$FAKE/promoted" ;;
esac
exit 0
`;

async function world(volumes: Record<string, string>, instances: string[]) {
    const dir = await Deno.makeTempDir();
    await Deno.mkdir(`${dir}/bin`);
    await Deno.mkdir(`${dir}/vol`);
    await Deno.mkdir(`${dir}/inst`);
    await Deno.writeTextFile(`${dir}/bin/incus`, FAKE_INCUS, { mode: 0o755 });
    await Deno.writeTextFile(`${dir}/bin/zfs`, FAKE_ZFS, { mode: 0o755 });
    for (const [name, label] of Object.entries(volumes)) {
        await Deno.writeTextFile(`${dir}/vol/${name}`, label);
    }
    for (const name of instances) {
        await Deno.writeTextFile(`${dir}/inst/${name}`, "");
    }
    return dir;
}

function run(
    dir: string,
    script: string,
    args: string[],
    env: Record<string, string> = {},
) {
    return new Deno.Command("bash", {
        args: [`${tools}${script}`, ...args],
        env: {
            ...env,
            PATH: `${dir}/bin:${Deno.env.get("PATH")}`,
            FAKE: dir,
            ORBIT_SEED_LOCK: `${dir}/lock`,
        },
        stdout: "piped",
        stderr: "piped",
    });
}

const names = ["p", "pool", "seed", "seed-refresh", "seed-previous", "builder"];
const volumes = async (dir: string) =>
    Object.fromEntries(
        await Promise.all(
            [...Deno.readDirSync(`${dir}/vol`)].map(async (
                e,
            ) => [e.name, await Deno.readTextFile(`${dir}/vol/${e.name}`)]),
        ),
    );
const cleanup = (dir: string) =>
    run(dir, "cleanup-builder.sh", [
        "p",
        "pool",
        "builder",
        "seed-refresh",
        "seed-previous",
        "seed",
    ]).output();

Deno.test("a promotion swaps the clone in, keeps the old seed as the rollback copy and prints one JSON line", async () => {
    const dir = await world({ seed: "old", "seed-refresh": "new" }, [
        "builder",
    ]);
    const result = await run(dir, "promote-seed.sh", names.slice(0, 6))
        .output();
    assertEquals(result.code, 0);
    assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), {
        seed: "seed",
        previous: "seed-previous",
        referenced_bytes: 222,
        written_bytes: 111,
    });
    assertEquals(await volumes(dir), { seed: "new", "seed-previous": "old" });
    assertFalse(
        await Deno.stat(`${dir}/inst/builder`).then(() => true, () => false),
    );
    assertEquals(
        (await Deno.readTextFile(`${dir}/promoted`)).trim(),
        "fast/pool/custom/p_seed-refresh",
    );
});

Deno.test("the rollback copy of the refresh before is deleted when the next promotion succeeds", async () => {
    const dir = await world({
        seed: "old",
        "seed-refresh": "new",
        "seed-previous": "older",
    }, []);
    assertEquals(
        (await run(dir, "promote-seed.sh", names.slice(0, 6)).output()).code,
        0,
    );
    assertEquals(await volumes(dir), { seed: "new", "seed-previous": "old" });
});

Deno.test("a promotion without a built seed or a current seed changes nothing", async () => {
    for (
        const have of [{ seed: "old" }, { "seed-refresh": "new" }] as Record<
            string,
            string
        >[]
    ) {
        const dir = await world(have, ["builder"]);
        const result = await run(dir, "promote-seed.sh", names.slice(0, 6))
            .output();
        assert(result.code !== 0);
        assertEquals(await volumes(dir), have);
        assert(
            await Deno.stat(`${dir}/inst/builder`).then(
                () => true,
                () => false,
            ),
        );
    }
});

Deno.test("when the second rename fails the first is undone and the old seed is current again", async () => {
    const dir = await world({ seed: "old", "seed-refresh": "new" }, []);
    const result = await run(dir, "promote-seed.sh", names.slice(0, 6), {
        FAKE_FAIL_RENAME: "2",
    }).output();
    assertEquals(result.code, 1);
    assertEquals(await volumes(dir), { seed: "old", "seed-refresh": "new" });
});

Deno.test("a kill between the two renames leaves no seed, and the cleanup renames the old seed back before it deletes anything", async () => {
    const dir = await world({ seed: "old", "seed-refresh": "new" }, [
        "builder",
    ]);
    const result = await run(dir, "promote-seed.sh", names.slice(0, 6), {
        FAKE_KILL_RENAME: "1",
    }).output();
    assertEquals(result.code, 137);
    assertEquals(await volumes(dir), {
        "seed-previous": "old",
        "seed-refresh": "new",
    });
    const repaired = await cleanup(dir);
    assertEquals(repaired.code, 0);
    assertEquals(await volumes(dir), { seed: "old" });
    assert(
        new TextDecoder().decode(repaired.stdout).split("\n").includes(
            "custom,seed",
        ),
    );
});

Deno.test("a kill after the second rename leaves a finished promotion that the cleanup keeps", async () => {
    const dir = await world({ seed: "old", "seed-refresh": "new" }, []);
    assertEquals(
        (await run(dir, "promote-seed.sh", names.slice(0, 6), {
            FAKE_KILL_RENAME: "2",
        }).output()).code,
        137,
    );
    assertEquals((await cleanup(dir)).code, 0);
    assertEquals(await volumes(dir), { seed: "new", "seed-previous": "old" });
});

Deno.test("the cleanup of a failed build deletes the builder and the clone, and keeps the seed and its rollback copy", async () => {
    const dir = await world({
        seed: "current",
        "seed-refresh": "half",
        "seed-previous": "rollback",
    }, ["builder"]);
    const result = await cleanup(dir);
    assertEquals(result.code, 0);
    assertEquals(await volumes(dir), {
        seed: "current",
        "seed-previous": "rollback",
    });
    assertEquals(new TextDecoder().decode(result.stdout).trim().split("\n"), [
        "custom,seed",
        "custom,seed-previous",
    ]);
});

Deno.test("the cleanup never deletes the clone when neither seed can be made current", async () => {
    const dir = await world({ "seed-refresh": "half" }, []);
    assertEquals((await cleanup(dir)).code, 0);
    assertEquals(await volumes(dir), {});
});

Deno.test("a TERM during the renames does not stop the promotion", async () => {
    const dir = await world({ seed: "old", "seed-refresh": "new" }, []);
    const child = run(dir, "promote-seed.sh", names.slice(0, 6), {
        FAKE_SLEEP_RENAME: "1.5",
    }).spawn();
    await new Promise((resolve) => setTimeout(resolve, 700));
    child.kill("SIGTERM");
    const result = await child.output();
    assertEquals(result.code, 0);
    assertEquals(await volumes(dir), { seed: "new", "seed-previous": "old" });
});

Deno.test("a promotion waits for the host seed lock that a claim's clone holds", async () => {
    const dir = await world({ seed: "old", "seed-refresh": "new" }, []);
    const holder = new Deno.Command("flock", {
        args: [`${dir}/lock`, "sleep", "1.5"],
    }).spawn();
    await new Promise((resolve) => setTimeout(resolve, 300));
    const started = Date.now();
    const result = await run(dir, "promote-seed.sh", names.slice(0, 6))
        .output();
    await holder.status;
    assertEquals(result.code, 0);
    assert(
        Date.now() - started >= 900,
        "the promotion ran while the lock was held",
    );
    assertEquals(await volumes(dir), { seed: "new", "seed-previous": "old" });
});
