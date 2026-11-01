import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/);
export const IncusOwnership = z.object({
    project: Name,
    pool: Name,
    operator_owned: z.array(z.string()).max(4096),
    live_names: z.array(Name).max(4096),
    max_task_vm_id: z.number().int().nonnegative(),
    max_build_id: z.number().int().nonnegative(),
    recorded_build_ids: z.array(Id).max(4096),
    retired_build_ids: z.array(Id).max(4096),
    protected_build_ids: z.array(Id).max(4096),
}).strict();

/** Public inventory selection only; provider actions belong to placed native steps. */
export function selectIncusOrphans(
    raw: unknown,
    instancesStdout: string,
    volumesStdout: string,
) {
    const scope = IncusOwnership.parse(raw);
    const instances = z.array(z.object({ name: Name }).passthrough()).max(4096)
        .parse(JSON.parse(instancesStdout));
    const volumes = z.array(
        z.object({ name: Name, type: z.string() }).passthrough(),
    ).max(4096).parse(JSON.parse(volumesStdout));
    const operatorOwns = (name: string) =>
        scope.operator_owned.includes(`${scope.project}/${name}`) ||
        scope.operator_owned.includes(`${scope.project}/*`);
    const orphanVm = (name: string) => {
        const match = /^tvm-([0-9]+)$/.exec(name);
        return match !== null && Number(match[1]) <= scope.max_task_vm_id &&
            !scope.live_names.includes(name);
    };
    const orphanInstance = (name: string) => {
        if (operatorOwns(name) || scope.live_names.includes(name)) return false;
        if (orphanVm(name)) return true;
        const build = /^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/
            .exec(
                name,
            );
        if (build === null) return false;
        const id = Number(build[1]);
        return id <= scope.max_build_id &&
            !scope.protected_build_ids.includes(id) &&
            (scope.retired_build_ids.includes(id) ||
                !scope.recorded_build_ids.includes(id));
    };
    const observed = [...new Set(instances.map((row) => row.name))];
    return {
        observed_names: observed,
        instances: observed.filter(orphanInstance),
        volumes: [
            ...new Set(
                volumes.filter((row) => {
                    if (
                        row.type !== "custom" || operatorOwns(row.name)
                    ) return false;
                    const match = /^(tvm-[0-9]+)-topology-seed$/.exec(row.name);
                    return match !== null && orphanVm(match[1]) &&
                        !observed.includes(match[1]);
                }).map((row) => row.name),
            ),
        ],
    };
}
