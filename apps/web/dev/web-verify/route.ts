import { pathnameOf, USAGE_NEXT, type ToolResult } from "./contract";
import {
    canonicalRoute,
    concreteRoute,
    loadFeatureMap,
    mapInvalidMessage,
    matchRoute,
    unresolvedParameter,
} from "./map";

/**
 * Rejects a map pattern, an unknown path, or a broken feature map before a browser starts.
 * `command` is a placeholder; the caller sets the subcommand that was actually run.
 */
export function assessRoute(
    mapFile: string,
    route: string,
): { ok: true; pattern: string } | { ok: false; result: ToolResult } {
    if (!concreteRoute(route)) {
        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                error: "usage",
                message: `The route ${route} is not a concrete path.`,
                next: USAGE_NEXT,
            },
        };
    }
    const pathname = pathnameOf(route);
    if (unresolvedParameter(pathname)) {
        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                route,
                error: "unresolved-parameter",
                message: `The path ${route} still has a $ segment.`,
                next: "Replace each $name with one segment, such as open /tasks/12 instead of open /tasks/$id.",
            },
        };
    }
    if (canonicalRoute(route) === null) return { ok: false, result: offOrigin(route) };

    const loaded = loadFeatureMap(mapFile);
    if (!loaded.ok) {
        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                error: "map-invalid",
                message: mapInvalidMessage(loaded),
                next: `Fix ${loaded.field} in apps/web/feature-map.json.`,
            },
        };
    }
    const matched = matchRoute(loaded.routes, pathname);
    if (!matched.ok) {
        const ambiguous = matched.error === "ambiguous-route";

        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                route,
                error: matched.error,
                message: ambiguous
                    ? `The path ${route} matches more than one feature map entry.`
                    : `The path ${route} matches no feature map entry.`,
                next: "Run bin/web-verify routes and pass a concrete path.",
            },
        };
    }

    return matched;
}

export function offOrigin(route: string): ToolResult {
    return {
        ok: false,
        command: "open",
        route,
        error: "usage",
        message: "The route does not stay on the demo server.",
        next: USAGE_NEXT,
    };
}
