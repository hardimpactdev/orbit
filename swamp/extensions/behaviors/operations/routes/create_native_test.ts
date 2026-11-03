import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { instanceFixture } from "../../../../tests/_support/instance_fixture.ts";
import {
    routeFixture,
    skipped,
} from "../../../../tests/_support/route_fixture.ts";

Deno.test("state-only Route create touches no Node", async () => {
    await using f = await routeFixture("create");
    const { run, calls, steps } = await f.perform();
    assertEquals([run.status, calls], ["succeeded", []]);
    assert(
        Object.keys(steps).every((n) =>
            [
                "Make a CSR on the Node",
                "Sign the CSR",
                "Allow the Router on LAN",
                "Install cert, publish site",
                "Probe the site over TLS",
                "Open the Ingress edge",
                "Publish private DNS",
            ].includes(n)
        ),
    );
});
Deno.test("Route create grants run in list order inside the site step", async () => {
    await using f = await routeFixture("create");
    const paths = ["/home", "/home/orbit", "/home/orbit/apps"];
    f.intent.site_nodes = [7];
    f.intent.acl = paths.map((path) => ({
        node_id: 7,
        argv: ["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", path],
    }));
    const { run, calls, steps } = await f.perform();
    assertEquals(run.status, "succeeded");
    assertEquals(calls[0].slice(-3), ["getent", "passwd", "orbit"]);
    const grants = calls.filter((c) => c.includes("/usr/bin/setfacl"));
    assertEquals(grants.map((c) => c.at(-1)), paths);
    assertEquals(grants[0].slice(0, 5), ["sudo", "-n", "--", "timeout", "120"]);
    assertEquals(steps["Install cert, publish site-7"].status, "succeeded");
});
Deno.test("failed Route create command stops PHP tests and probes", async () => {
    await using f = await routeFixture("create");
    f.intent.site_nodes = [7];
    f.intent.acl = [{
        node_id: 7,
        argv: ["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", "/home"],
    }];
    f.intent.php_tests = [{ node_id: 7, argv: ["/usr/sbin/php-fpm8.5", "-t"] }];
    f.intent.probes = [{
        node_id: 7,
        address: "127.0.0.1",
        domain: "shop.test",
    }];
    const { run, calls, steps } = await f.perform(1, false);
    assertEquals(run.status, "failed");
    assert(!calls.some((c) => c.join(" ").includes("php-fpm8.5")));
    assertEquals(steps["Install cert, publish site-7"].status, "failed");
    skipped(steps, "Probe the site over TLS");
});
Deno.test("failed Route create TLS probe fails the run", async () => {
    await using f = await routeFixture("create");
    f.intent.probes = [{
        node_id: 7,
        address: "127.0.0.1",
        domain: "shop.test",
    }];
    const { run, steps } = await f.perform(1, false);
    assertEquals(run.status, "failed");
    assert(
        Object.entries(steps).some(([n, s]) =>
            n.startsWith("Probe the site over TLS") && s.status === "failed"
        ),
    );
});

async function preparedSite(checkedMode = "0644") {
    const f = await instanceFixture();
    const group = new TextDecoder().decode(
        new Deno.Command("id", { args: ["-gn"] }).outputSync().stdout,
    ).trim();
    await f.workflow("route-create-site", (flow) => {
        for (const job of flow.jobs) {
            for (const step of job.steps) {
                if (step.task?.behaviorType === "@swamp/cfgmgmt/file") {
                    Object.assign(step.task.globalArgs, {
                        owner: f.user,
                        group,
                        become: false,
                    });
                    if (step.name === "Check the awake marker") {
                        step.task.globalArgs.mode = checkedMode;
                    }
                }
            }
        }
    });
    const marker = f.node + "/instance.awake";
    const intent = {
        ...Object.fromEntries(
            "acl links php_files php_tests php_reloads leaves leaf_indexes caddy url_lines env_files trust"
                .split(" ").map((key) => [key, []]),
        ),
        awake: [{ node_id: 7, instance_id: 42, path: marker }],
        root_certificate: "",
        nodes: {
            "7": {
                host: "fixture.invalid",
                user: f.user,
                port: 22,
                identity_file: f.connection.identity_file,
                account: f.user,
                host_keys: f.connection.target.host_keys,
            },
        },
    };
    return {
        f,
        marker,
        inputs: { intent, node_id: 7, signed: [], execution_secrets: {} },
    };
}

Deno.test("prepared development site becomes awake after publication and rerun preserves its marker", async () => {
    const { f, marker, inputs } = await preparedSite();
    await using fixture = f;
    const first = await f.run("route-create-site", inputs);
    assertEquals(
        first.steps["Check the awake marker-0"].outputs.status,
        "compliant",
    );
    const before = await Deno.stat(marker);
    await f.run("route-create-site", inputs);
    const after = await Deno.stat(marker);
    assertEquals(after.mtime, before.mtime);
    assertEquals(after.mode! & 0o777, 0o644);
});

Deno.test("failed site preparation leaves the development Instance asleep", async () => {
    const { f, marker, inputs } = await preparedSite();
    await using fixture = f;
    inputs.intent.nodes["7"].account = "orbit-fixture-missing-account";
    await f.run("route-create-site", inputs, false);
    await assertRejects(() => Deno.stat(marker), Deno.errors.NotFound);
});

Deno.test("non-compliant awake check fails the native end assertion", async () => {
    const { f, inputs } = await preparedSite("0600");
    await using fixture = f;
    const { steps } = await f.run("route-create-site", inputs, false);
    assertEquals(
        steps["Check the awake marker-0"].outputs.status,
        "non_compliant",
    );
    assertEquals(steps["Verify the awake state"].status, "failed");
});

Deno.test("Route create probes TLS from the pinned control host with literal argv", async () => {
    await using f = await routeFixture("create");
    const domain = "shop.test; printf should-not-run";
    f.intent.probes = [{ node_id: 7, address: "10.44.0.7", domain }];
    const { run, calls } = await f.perform();
    assertEquals(run.status, "succeeded");
    assertEquals(calls, [[
        "timeout",
        "20",
        "openssl",
        "s_client",
        "-no_ign_eof",
        "-connect",
        "10.44.0.7:443",
        "-servername",
        domain,
        "-verify_hostname",
        domain,
        "-verify_return_error",
        "-CAfile",
        "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
    ]]);
});
