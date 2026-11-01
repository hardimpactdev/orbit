/** Compile-time assembly only. Swamp retains every action, dependency and outcome. */
export type Fields = Record<string, unknown>;
export const expr = (source: string) => "${{ " + source + " }}";
export const placement = { target: "orbit-node-209" };

export function failed(names: string[]): string {
    return names.length
        ? `steps.exists(key, ${
            JSON.stringify(names)
        }.exists(prior, key == prior || key.startsWith(prior + "-")) && steps[key].status in ["failed", "cancelled"])`
        : "false";
}
export function succeeded(name: string): string {
    return `${JSON.stringify(name)} in steps && steps[${
        JSON.stringify(name)
    }].status == "succeeded"`;
}
export function sshStep(
    name: string,
    globals: Fields,
    argv: unknown,
    stdin?: string,
): Fields {
    return {
        name,
        ...placement,
        writes: true,
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globals.name,
            methodName: "exec",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                sudo: true,
                argv,
                ...(stdin ? { stdin: expr(stdin) } : {}),
            },
        },
    };
}
export function metadata(
    name: string,
    methodName: string,
    inputs: Fields,
): Fields {
    return {
        name,
        ...placement,
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: name,
            methodName,
            inputs,
        },
    };
}
export function proof(name: string, source: string, message: string): Fields {
    return {
        name,
        ...placement,
        task: { type: "assert", expr: source, severity: "high", message },
    };
}

export class RoleComposition {
    readonly steps: Fields[] = [];
    constructor(
        readonly dependency?: string,
        readonly guard?: string,
        readonly recovery = false,
    ) {}
    append(group: Fields[], active = "true", recover = this.recovery): string {
        const predecessors = [
            ...(this.dependency ? [this.dependency] : []),
            ...this.steps.map((step) => String(step.name)),
        ];
        group[0].dependsOn = [
            ...((group[0].dependsOn as Fields[] | undefined) ?? []),
            ...predecessors.map((step) => ({
                step,
                condition: { type: "always" },
            })),
        ];
        const blocked = recover ? "false" : failed(predecessors);
        for (const step of group) {
            const local = typeof step.guard === "string"
                ? step.guard.slice(3, -2).trim()
                : "false";
            step.guard = expr(
                `(${blocked}) || (${
                    this.guard ?? "false"
                }) || !(${active}) || (${local})`,
            );
            this.steps.push(step);
        }
        return String(group.at(-1)!.name);
    }
}
