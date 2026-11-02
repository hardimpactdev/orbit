/**
 * The swamp role's runtime as official steps over SSH from 209 (flow-tighten rule 22): the SSH identity, serve and
 * its workers. Serve and running workers are never restarted; a changed unit takes effect at the next restart.
 * Charts: docs/flows/node.role_converge.mmd, node.role_add.mmd.
 */
import { dotless } from "../_lib/behavior_name.ts";

type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const dependency = (job: string, type = "succeeded") => ({
    job,
    condition: { type },
});

const SwampIdentity = "/etc/orbit/swamp-role-worker/ssh-identity";
const IdentitySource = "/etc/orbit/node-proxy/id_ed25519";
const Serve = "orbit-swamp-serve.service";
const Template = "orbit-swamp-worker@.service";
const worker = (name: string) => `"orbit-swamp-worker@" + ${name} + ".service"`;

/** Steps that reach the role's Node with the admitted SSH execution input `ssh`. */
function connection(ssh: string) {
    return {
        cfgmgmt: {
            nodeHost: expr(`${ssh}.target.host`),
            nodeUser: expr(`${ssh}.target.user`),
            nodePort: expr(`${ssh}.target.port`),
            nodeIdentityFile: expr(`${ssh}.identity_file`),
            become: true,
        },
        exec: (name: string) => ({
            name: expr(name),
            transport: {
                kind: "ssh",
                user: expr(`${ssh}.target.user`),
                port: expr(`${ssh}.target.port`),
                identityFile: expr(`${ssh}.identity_file`),
            },
            hosts: [{
                name: "target",
                address: expr(`${ssh}.target.host`),
                transport: { hostKeys: expr(`${ssh}.target.host_keys`) },
            }],
        }),
    };
}

const behavior = (
    type: string,
    name: string,
    methodName: string,
    globalArgs: Fields,
    inputs: Fields = {},
) => ({
    type: "behavior",
    behaviorType: type,
    behaviorName: expr(name),
    methodName,
    globalArgs,
    inputs,
});

/**
 * Add and converge jobs; relocation shares them, but the Gateway refuses moving the swamp role. `runtime` is the CEL of the admitted role runtime and `skip` is true
 * for any other role. Returns the error code of the first failed step for the runtime outcome, or "".
 */
export function roleSwampRuntimeJobs(
    afterJob: string,
    runtime: string,
    skip: string,
) {
    const ssh = `${runtime}.ssh_execution`;
    const { cfgmgmt, exec } = connection(ssh);
    const node = `string(${runtime}.node_id)`;
    const name = (family: string) => `"${family}-" + ${node}`;
    const workers = expr(`${skip} ? [] : ${runtime}.workers`);
    const placed = { labels: { role: "swamp" }, guard: expr(skip) };
    const unit = (service: string, unitFile?: string, running = true) => ({
        ...cfgmgmt,
        service,
        ...(unitFile ? { unitFile: expr(unitFile) } : {}),
        ...(running ? { enabled: true, ensure: "running" } : {}),
    });
    const identity = {
        ...cfgmgmt,
        path: SwampIdentity,
        ensure: "present",
        owner: "root",
        group: expr(`${runtime}.user`),
        mode: "0440",
    };
    const jobs: Fields[] = [{
        name: "swamp_runtime_job",
        dependsOn: [dependency(afterJob)],
        steps: [{
            name: "swamp_identity",
            writes: true,
            ...placed,
            task: behavior(
                "@swamp/ssh",
                name("swamp-identity"),
                "exec",
                exec(name("swamp-identity")),
                {
                    hosts: "name:target",
                    argv: [
                        "/usr/bin/sudo",
                        "-n",
                        "--",
                        "/usr/bin/install",
                        // Compare first, so a matching key is never rewritten under live SSH.
                        "-C",
                        "-m",
                        "0440",
                        "-o",
                        "root",
                        "-g",
                        expr(`${runtime}.user`),
                        IdentitySource,
                        SwampIdentity,
                    ],
                },
            ),
        }, {
            name: "swamp_serve",
            writes: true,
            ...placed,
            task: behavior(
                "@swamp/cfgmgmt/systemd",
                name("swamp-serve"),
                "apply",
                unit(Serve, `${runtime}.units.serve`),
            ),
        }, {
            name: "swamp_worker_unit",
            writes: true,
            ...placed,
            task: behavior(
                "@swamp/cfgmgmt/systemd",
                name("swamp-worker-unit"),
                "apply",
                unit(Template, `${runtime}.units.worker`, false),
            ),
        }],
    }, {
        name: "swamp_workers_job",
        dependsOn: [dependency("swamp_runtime_job")],
        steps: [{
            name: "swamp_worker_" + expr("self.worker"),
            writes: true,
            ...placed,
            forEach: { item: "worker", in: workers },
            task: behavior(
                "@swamp/cfgmgmt/systemd",
                dotless(`${name("swamp-worker")} + "-" + self.worker`),
                "apply",
                unit(expr(worker("self.worker"))),
            ),
        }],
    }, {
        name: "swamp_check_job",
        dependsOn: [dependency("swamp_workers_job")],
        steps: [{
            name: "swamp_check_serve",
            ...placed,
            task: behavior(
                "@swamp/cfgmgmt/systemd",
                name("swamp-serve"),
                "check",
                unit(Serve, `${runtime}.units.serve`),
            ),
        }, {
            name: "swamp_check_worker_unit",
            ...placed,
            task: behavior(
                "@swamp/cfgmgmt/systemd",
                name("swamp-worker-unit"),
                "check",
                unit(Template, `${runtime}.units.worker`, false),
            ),
        }, {
            name: "swamp_check_worker_" + expr("self.worker"),
            ...placed,
            forEach: { item: "worker", in: workers },
            task: behavior(
                "@swamp/cfgmgmt/systemd",
                dotless(`${name("swamp-worker")} + "-" + self.worker`),
                "check",
                unit(expr(worker("self.worker"))),
            ),
        }, {
            name: "swamp_check_identity",
            ...placed,
            task: behavior(
                "@swamp/cfgmgmt/file",
                name("swamp-identity-file"),
                "check",
                identity,
            ),
        }],
    }, {
        name: "swamp_proven_job",
        dependsOn: [dependency("swamp_check_job")],
        steps: [{
            name: "swamp_proven",
            guard: expr(skip),
            task: {
                type: "assert",
                expr:
                    `["swamp_check_serve", "swamp_check_worker_unit", "swamp_check_identity"].all(k, steps[k].outputs.status == "compliant") && ${runtime}.workers.all(w, steps["swamp_check_worker_" + w].outputs.status == "compliant")`,
                severity: "high",
                message:
                    "The swamp role's serve, workers or SSH identity drifted",
            },
        }],
    }];
    const failed = (test: string) =>
        `steps.exists(k, (${test}) && steps[k].status == "failed")`;
    return {
        jobs,
        lastJob: "swamp_proven_job",
        failure: `${
            failed('k == "swamp_identity"')
        } ? "swamp.ssh_identity_failed" : ${
            failed('k == "swamp_serve"')
        } ? "swamp.serve_unit_failed" : ${
            failed('k.startsWith("swamp_worker_")')
        } ? "swamp.worker_unit_failed" : ${
            failed('k.startsWith("swamp_check_") || k == "swamp_proven"')
        } ? "swamp.runtime_check_failed" : ""`,
    };
}
