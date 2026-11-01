import { runLocalSource } from "./source_system.ts";
import {
    isProductionHomeAction,
    runLocalProductionHome,
} from "./production_home_system.ts";
import { invokeLocalEnvironment } from "../instance-environment/local.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

/** Retained Tasks caller only; Instance create/clone use native placed actions. */
export function invokeLocalProvisioning(call: NodeCall, signal?: AbortSignal) {
    if (
        isProductionHomeAction(call.name) &&
        (call.name !== "instance.inspect-clone-candidate" ||
            call.input.environment === "production")
    ) {
        return runLocalProductionHome(call, signal);
    }
    switch (call.name) {
        case "instance.inspect-environment-sync":
        case "instance.write-environment":
            return invokeLocalEnvironment({ ...call, name: call.name }, signal);
        default:
            return runLocalSource(call, signal);
    }
}
