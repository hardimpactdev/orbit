/** Exercise the shipped fleet phase against isolated stock and DNS boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { find, guests, hasAnsible, intent, reads, run } from "./_support/task_vm_fleet_build.ts";
import { existingGatewayDns, freshGatewayDnsCheck, productionGatewayDns, withdrawGatewayDns } from "./_support/task_vm_stock_roles.ts";

Deno.test({
  name:
    "a blank seed gets the network, then the containers, the Gateway bootstrap and the nested Orbit steps in order, then the operator join",
  ignore: !hasAnsible,
  fn() {
    const { log, preseed, passed, output, pushedInput } = run([], false); // The run stops at the operator join, which needs root and systemd (see the header).
    assertEquals(passed, false, output);
    // The run stops at the start of the operator join: at the fake `wg`, or one task earlier where `/etc/wireguard` is not readable,
    // as on a CI runner.
    const failed = output.indexOf("FAILED!");
    const stop = output.lastIndexOf("TASK [", failed);
    assert(
      failed >= 0 && stop >= 0 && [
        output.indexOf("TASK [Look for the operator key]"),
        output.indexOf("TASK [Make the operator key]"),
      ].includes(stop) &&
        output.includes("failed=1"),
      output,
    );
    assertStringIncludes(preseed, "name: oe-nested");
    assertStringIncludes(preseed, "ipv4.address: 10.232.2.1/24");
    assertStringIncludes(
      pushedInput,
      "ssh-ed25519 AAAAgatewaykey orbit@nested-gateway",
    );
    const steps = [
      "incus admin init --preseed",
      "incus list --format json",
      "incus init images:ubuntu/resolute/cloud nested-gateway --storage topology-seed --network oe-nested -c security.nesting=true -c limits.cpu=2 -c limits.memory=4GiB -c boot.autostart=true",
      "incus init images:ubuntu/resolute/cloud nested-app-dev",
      "incus init images:ubuntu/resolute/cloud nested-app-prod",
      "incus config device set nested-gateway eth0 ipv4.address=10.232.2.11 hwaddr=10:66:6a:5d:00:11",
      "incus start nested-gateway nested-app-dev nested-app-prod",
      "incus exec nested-gateway -- cloud-init status --wait",
      "incus exec nested-app-prod -- touch /etc/cloud/cloud-init.disabled",
      "incus config device add nested-gateway club-key disk source=/etc/orbit/swamp-club-api-key path=/home/orbit/.config/swamp/api-key",
      "swamp workflow run gateway-bootstrap --repo-dir /home/orbit/apps/orbit/workspace/swamp",
      "php artisan orbit:node-converge gateway",
      "select status from operations where id = 1",
      "php artisan orbit:role-add gateway gateway --converge",
      "select status from operations where id = 2",
      "php artisan orbit:role-add gateway websocket",
      "select status from operations where id = 3",
      "incus file push --uid 1000 --gid 1000 --mode 0600 - nested-app-dev/home/orbit/.ssh/authorized_keys",
      "php artisan orbit:node-add app-dev 10.232.2.12 --role=app-dev --wireguard-ip=10.45.0.2 --tld=nested-dev --host-key-fingerprint=SHA256:fp-nested-app-dev",
      "select status from operations where id = 4",
      "php artisan orbit:node-add app-prod 10.232.2.13 --role=app-prod --wireguard-ip=10.45.0.3 --tld=nested-prod --host-key-fingerprint=SHA256:fp-nested-app-prod",
      "select status from operations where id = 5",
      "ufw allow from 10.232.2.0/24 comment proof-network",
      "systemctl stop orbit-agent-view.service",
      "incus config device add nested-gateway checkout disk source=/home/orbit/apps/orbit/workspace path=/home/orbit/orbit shift=true",
      "incus file push --mode 0644 - nested-app-dev/etc/systemd/system/home-orbit-orbit-apps-gateway-.env.mount",
      "systemctl start --job-mode=ignore-requirements home-orbit-orbit-apps-gateway-.env.mount",
      "systemctl start php8.5-fpm.service",
      "https://10.45.0.1/api/v1/doctor",
      "incus list --format json",
    ];
    let at = 0;
    for (const step of steps) {
      const found = find(log, step, at);
      assert(
        found >= 0,
        `${step} after call ${at} in:\n${log.join("\n")}`,
      );
      at = found + 1;
    }
    // The bootstrap input: what the Gateway sent, and what the run adds: the Swamp pin, the machine with its pinned key, the release.
    const bootstrap = log.find((call) => call.startsWith("swamp "))!;
    const input = JSON.parse(
      bootstrap.slice(
        bootstrap.indexOf("--input ") + 8,
        bootstrap.lastIndexOf(" key="),
      ),
    ).intent;
    const { identity_file: identity, ...ssh } = input.ssh;
    assertEquals(ssh, {
      host: "10.232.2.11",
      port: 22,
      user: "orbit",
      host_keys: ["ssh-ed25519 AAAAgateway"],
    });
    assert(identity.endsWith("/home/.ssh/id_ed25519"), identity);
    assertEquals(input.release, {
      repository: intent.checkout.repository,
      commit: intent.checkout.commit,
    });
    assertEquals(input.swamp, {
      url: "https://swamp/x",
      sha256: "b".repeat(64),
    });
    assertEquals(input.operator, intent.fleet.bootstrap.operator);
    assertEquals(input.apt_proxy, "http://10.232.2.1:3142");
    assert(bootstrap.endsWith("key=/etc/orbit/swamp-club-api-key"));
    // A blank seed has no fleet to remove.
    assertEquals(find(log, "delete --force"), -1);
  },
});

Deno.test({
  name: "a half-made fleet starts from nothing: its containers go first",
  ignore: !hasAnsible,
  fn() {
    const { log, output } = run(["nested-gateway"], true, true);
    const writes = log.filter((call) => !reads.test(call));
    assertEquals(writes[0], "incus delete --force nested-gateway", output);
    assert(
      find(log, "init images:ubuntu/resolute/cloud nested-gateway") > 0,
    );
  },
});

Deno.test({
  name:
    "a fleet whose build stopped after its containers existed has no operator stamp: all three go and the build runs whole",
  ignore: !hasAnsible,
  fn() {
    const { log, stamped, output } = run(Object.values(guests), true);
    const writes = log.filter((call) => !reads.test(call));
    assertEquals(
      writes[0],
      "incus delete --force nested-gateway nested-app-dev nested-app-prod",
      output,
    );
    assert(find(log, "swamp workflow run gateway-bootstrap") > 0, output);
    assertEquals(stamped, false);
  },
});

Deno.test({
  name:
    "a build removes the operator stamp first, so a build that stops leaves the fleet incomplete",
  ignore: !hasAnsible,
  fn() {
    // A complete fleet that lost a container: the stamp of its earlier join goes before anything else changes.
    const { stamped, output } = run(
      ["nested-gateway", "nested-app-dev"],
      true,
      true,
    );
    assertEquals(stamped, false, output);
  },
});

Deno.test({
  name:
    "a complete fleet creates nothing, restarts nothing and changes nothing",
  ignore: !hasAnsible,
  fn() {
    const { log, preseed, stamped, passed, output } = run(
      Object.values(guests),
      true,
      true,
    );
    assertEquals(stamped, true);
    assert(passed, output);
    assertEquals(preseed, "");
    assertEquals(log, [
      "incus storage list --format csv",
      "incus network list --format csv",
      "incus list --format json",
    ]);
    assertStringIncludes(output, "changed=0");
  },
});

/**
 * The existing Incus fixture executes selected nested role contracts and generates real WireGuard keys in its own folder.
 * It stops at "Restart the nested hub": applying wg-quick requires an actual nested container, systemd and the kernel. Snapshot,
 * candidate task start and promotion remain unexecuted. app-dev/app-prod cover software selection, not their installed runtimes.
 * The websocket health endpoint is a DNS dependency probe, not the external Reverb application.
 */
