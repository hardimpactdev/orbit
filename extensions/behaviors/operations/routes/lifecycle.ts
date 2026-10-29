/** Route ordering. The adapter uses the shared Operation primitives for each stage. */
import { RouteRefusal } from "./contract.ts";
export { RouteRefusal } from "./contract.ts";

export type Stages = {
  /** Each stage is keyed on the admitted Operation and resumes proven child receipts. */
  run(name: string): Promise<void>;
  completed(name: string): boolean;
};

async function forward(stages: Stages, names: readonly string[]) {
  for (const name of names) {
    if (!stages.completed(name)) await stages.run(name);
  }
}

/** A transport failure leaves the original intent pending; it is never evidence for rollback. */
export async function replaceDomain(
  stages: Stages,
  options: { public: boolean; production: boolean },
) {
  const environment = options.production
    ? "environment-synchronized"
    : "laravel-url";
  try {
    await forward(stages, [
      "workload-certificate",
      "workload-caddy",
      "router-certificate",
      "firewall-policy",
      "workload-verified",
      "router-caddy",
      ...(options.public
        ? ["ingress-certificate", "public-edge-verified"]
        : []),
      environment,
      "dns-published",
      "database-cutover",
    ]);
  } catch (error) {
    if (
      error instanceof RouteRefusal && !stages.completed("database-cutover")
    ) {
      // A rollback stage that fails stops withdrawal. In particular, failed DNS restoration
      // keeps the candidate serving the addresses that clients may still have cached.
      await forward(stages, [
        ...(options.public ? ["rollback-public-edge"] : []),
        "rollback-environment",
        "rollback-dns",
        "rollback-caddy",
        "rollback-certificates",
        "rollback-record",
      ]);
    }
    throw error;
  }
  if (options.public) {
    try {
      await forward(stages, ["public-activated", "ingress-firewall"]);
    } catch (error) {
      if (
        error instanceof RouteRefusal && !stages.completed("public-activated")
      ) {
        await forward(stages, ["rollback-public-edge"]);
      }
      throw error;
    }
  }
  await forward(stages, [
    "prepare-cleanup",
    "cleanup-caddy",
    "cleanup-certificates",
    "synchronize-current-environment",
    "verify-current-workload",
    "complete-replacement",
  ]);
}

export async function replaceRouter(stages: Stages) {
  try {
    await forward(stages, [
      "baseline",
      "router-certificate",
      "firewall-policy",
      "workload-verify",
      "router-caddy",
    ]);
  } catch (error) {
    if (error instanceof RouteRefusal && !stages.completed("database")) {
      await forward(stages, [
        "restore-dns",
        "await-restored-dns-expiry",
        "withdraw-candidate-sites",
        "remove-candidate-certificates",
        "remove-candidate-firewall",
      ]);
    }
    throw error;
  }
  await forward(stages, [
    "database",
    "dns-publication",
    "prune-dns",
    "await-old-dns-expiry",
    "withdraw-old-sites",
    "remove-old-certificates",
    "remove-old-firewall",
    "remove-old-baseline",
    "complete-router",
  ]);
}

/** Removal always recovers forward. Certificates leave only once their sites are gone. */
export async function removeRoute(stages: Stages, targeted: boolean) {
  await forward(stages, [
    "dns",
    "caddy",
    ...(targeted ? ["php"] : []),
    "certificates",
    "firewall",
    "record",
  ]);
}

/** Preparing the next pool never changes authoritative associations before all preparations pass. */
export async function changeTargets(stages: Stages) {
  try {
    await forward(stages, [
      "target-workload-certificates",
      "target-runtime-prepared",
      "target-workload-caddy",
      "target-router-certificates",
      "target-firewall-prepared",
      "target-workload-verified",
      "database-committed",
    ]);
  } catch (error) {
    if (
      error instanceof RouteRefusal && !stages.completed("database-committed")
    ) {
      await forward(stages, [
        "rollback-preparations-caddy",
        "rollback-preparations-certificates",
        "rollback-preparations-record",
      ]);
    }
    throw error;
  }
  await forward(stages, [
    "environment-synchronized",
    "target-live-certificates",
    "target-live-caddy",
    "router-published",
    "vacated-published",
    "target-staging-cleanup",
    "removal",
    "completed",
  ]);
}

/** Initial projection for both development creation and a production clone. */
export async function convergeRoute(
  stages: Stages,
  options: {
    production: boolean;
    remoteRouter: boolean;
    public: boolean;
    webRoot: boolean;
  },
) {
  try {
    await forward(stages, [
      "workload-certificate",
      ...(options.production && options.webRoot
        ? ["prepare-web-root", "synchronize-web-root-urls"]
        : []),
      "publish-sites",
      ...(options.production
        ? ["production-runtime", "workload-firewall"]
        : ["workload-prepared"]),
      "workload-caddy",
      ...(options.remoteRouter
        ? [
          "router-certificate",
          "firewall-policy",
          "workload-verified",
          "router-caddy",
        ]
        : []),
      "dns-published",
      ...(options.public
        ? [
          "ingress-certificate",
          "public-edge-verified",
          "public-activated",
          "ingress-firewall",
        ]
        : []),
      ...(!options.production && options.webRoot
        ? ["synchronize-web-root-urls"]
        : []),
      "activate-route",
    ]);
  } catch (error) {
    if (
      error instanceof RouteRefusal && options.production && options.webRoot
    ) {
      await forward(stages, [
        "rollback-initial-dns",
        "rollback-initial-caddy",
        "rollback-initial-php",
        "synchronize-web-root-urls.rollback",
        "rollback-initial-certificates",
        "rollback-initial-firewall",
      ]);
    }
    throw error;
  }
}

/** Source cleanup uses the transfer's old placement; destination state stays authoritative. */
export async function retireSource(stages: Stages) {
  await forward(stages, [
    "rebuild-transfer-caddy",
    "source-php-pools",
    "dns-published",
    "remove-unused-source-certificate",
    "remove-source-firewall",
    "remove-unused-source-router-certificate",
    "remove-source-web-root-artifacts",
    "complete-source-retirement",
  ]);
}

export function webRootPhases(production: boolean, rollback = false): string[] {
  const sequence = [
    "web-root-stored",
    "workload-certificate",
    ...(production
      ? ["prepare-web-root", "synchronize-web-root-urls", "production-runtime"]
      : ["workload-prepared"]),
    "workload-caddy",
    ...(!production ? ["synchronize-web-root-urls"] : []),
  ];
  return rollback
    ? sequence.map((phase) =>
      phase === "synchronize-web-root-urls"
        ? "synchronize-web-root-urls.rollback"
        : `rollback-${phase}`
    )
    : sequence;
}

export async function changeWebRoot(
  stages: Stages,
  production: boolean,
  changed: boolean,
) {
  if (!changed) return await stages.run("state-recorded");
  try {
    await forward(stages, webRootPhases(production));
  } catch (error) {
    if (error instanceof RouteRefusal) {
      await forward(stages, webRootPhases(production, true));
    }
    throw error;
  }
  await forward(stages, ["web-root-certificate-remove"]);
}

export async function changePublication(
  stages: Stages,
  activate: boolean,
  withdraw: boolean,
) {
  await forward(stages, ["publication-stored"]);
  if (activate) {
    try {
      await forward(stages, [
        "public-edge-verified",
        "public-activated",
        "ingress-firewall",
      ]);
    } catch (error) {
      if (
        error instanceof RouteRefusal && !stages.completed("public-activated")
      ) {
        await forward(stages, [
          "rollback-public-edge",
          "rollback-public-firewall",
        ]);
      }
      throw error;
    }
  } else if (withdraw) {
    await forward(stages, [
      "public-withdraw-caddy",
      "public-withdraw-firewall",
      "public-withdraw-certificates",
    ]);
  }
  await forward(stages, ["publication-completed"]);
}
