import { type Paths, type ToolResult } from "./contract";
import { daemonAlive, dispatch, stopDaemon } from "./daemon";
import { loadFeatureMap, mapInvalidMessage } from "./map";
import { parseCommand } from "./parse";
import { assessRoute } from "./session";

/** Browser commands go to the shared daemon; map and argument checks stay in-process. */
export async function execute(paths: Paths, argv: readonly string[]): Promise<ToolResult> {
    const parsed = parseCommand(argv);
    if (parsed.kind === "fail") return parsed.result;
    if (parsed.kind === "routes") return routesResult(paths);
    if (parsed.kind === "stop") return stopDaemon(paths);
    if (parsed.kind === "click") {
        if (!daemonAlive(paths)) {
            return {
                ok: false,
                command: "click",
                error: "no-page",
                message: "There is no active page to click.",
                next: "Run bin/web-verify open <route> first.",
            };
        }

        return dispatch(paths, { command: "click", selector: parsed.selector });
    }

    const assessed = assessRoute(paths.mapFile, parsed.route);
    if (!assessed.ok) return { ...assessed.result, command: parsed.kind };
    if (parsed.kind === "console-errors") {
        return dispatch(paths, {
            command: "console-errors",
            route: parsed.route,
            device: "desktop",
            engine: "chromium",
        });
    }

    return dispatch(paths, {
        command: parsed.kind,
        route: parsed.route,
        device: parsed.device,
        engine: parsed.engine,
    });
}

function routesResult(paths: Paths): ToolResult {
    const loaded = loadFeatureMap(paths.mapFile);
    if (!loaded.ok) {
        return {
            ok: false,
            command: "routes",
            error: "map-invalid",
            message: mapInvalidMessage(loaded),
            next: `Fix ${loaded.field} in apps/web/feature-map.json.`,
        };
    }

    return { ok: true, command: "routes", routes: loaded.routes };
}
