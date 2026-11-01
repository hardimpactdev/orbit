import { z } from "npm:zod@4.4.3";
import { safeVersion, ToolRefusal, type ToolInput } from "./contract.ts";
const Row = z.record(z.string(), z.unknown());
const sha = (v: unknown) => typeof v === "string" && /^[a-f0-9]{64}$/.test(v);
const prefixKinds = "binary manpage bash_completion fish_completion zsh_completion generate_completions_from_executable".split(" ");
const homeKinds = "font colorpicker dictionary internet_plugin prefpane qlplugin mdimporter screen_saver service audio_unit_plugin vst_plugin vst3_plugin".split(" ");
const privilegedKinds = "pkg installer keyboard_layout input_method".split(" ");
const uninstallKeys = "quit signal login_item trash delete rmdir pkgutil kext launchctl".split(" ");
export function homebrewBlock(
    input: Pick<ToolInput, "manager" | "package" | "platform" | "architecture">,
    facts: Record<string, unknown>,
): string | null {
    const r = Row.parse(facts.homebrew);
    const symbols: Record<number, string> = {
        27: "golden_gate", 26: "tahoe", 15: "sequoia", 14: "sonoma",
        13: "ventura", 12: "monterey", 11: "big_sur",
    };
    const symbol = symbols[z.number().int().parse(facts.os_major)];
    const cpu = ({ amd64: "x86_64", aarch64: "arm64" } as Record<string, string>)[input.architecture] ?? input.architecture;
    const tag = input.platform === "linux" ? (cpu === "x86_64" ? "x86_64_linux" : "")
        : symbol && ["arm64", "x86_64"].includes(cpu) ? (cpu === "arm64" ? "arm64_" : "") + symbol : "";
    if (!tag) throw new ToolRefusal("tool.version_probe_failed");
    if (input.manager === "brew") {
        if (r.name !== input.package || r.full_name !== input.package || r.tap !== "homebrew/core") return "unsupported_source";
        const kegs = z.array(z.object({ installed_on_request: z.boolean().optional(), version: z.string() })).parse(r.installed);
        if (!kegs.some((k) => k.installed_on_request)) return "dependency";
        if (z.boolean().parse(r.disabled)) return "unsupported_artifact";
        const versions = Row.parse(r.versions);
        const files = Row.parse(Row.parse(Row.parse(r.bottle).stable).files);
        const bottle = Row.parse(files[tag] ?? files.all ?? {});
        const checksum = sha(bottle.sha256) && typeof bottle.url === "string" &&
            bottle.url.startsWith("https://ghcr.io/v2/homebrew/core/") && bottle.url.endsWith("sha256:" + bottle.sha256);
        return tag && versions.bottle === true && checksum ? null : "bottle_unavailable";
    }
    const value = { ...r, ...(tag ? Row.parse(Row.parse(r.variations ?? {})[tag] ?? {}) : {}) };
    if (value.token !== input.package || value.full_token !== input.package || value.tap !== "homebrew/cask" ||
        typeof value.url !== "string" || !/^https:\/\/\S+$/.test(value.url)) return "unsupported_source";
    if (z.boolean().parse(value.disabled)) return "unsupported_artifact";
    const bases = ["~", "$HOME", "/$HOME", "$HOMEBREW_PREFIX", z.string().parse(facts.prefix), z.string().parse(facts.home)];
    const userPath = (p: string) => p.length > 0 && !p.includes("..") && !/[\x00-\x1f\x7f]/.test(p) &&
        (!p.includes("/") || bases.some((b) => p === b || p.startsWith(b + "/")));
    let installs = 0, authorization = false;
    for (const artifact of z.array(Row).parse(value.artifacts)) {
        for (const [kind, item] of Object.entries(artifact)) {
            if (["target", "zap"].includes(kind)) continue;
            if (kind === "uninstall") {
                for (const directive of z.array(Row).parse(item)) {
                    if (Object.keys(directive).some((k) => !uninstallKeys.includes(k))) return "unsupported_artifact";
                    if (["pkgutil", "kext", "launchctl"].some((k) => k in directive)) authorization = true;
                    for (const k of ["trash", "delete", "rmdir"]) {
                        if (!(k in directive)) continue;
                        const paths = z.union([z.string(), z.array(z.string())]).parse(directive[k]);
                        if (!(typeof paths === "string" ? [paths] : paths).every(userPath)) authorization = true;
                    }
                }
                continue;
            }
            const prefixKind = prefixKinds.includes(kind), homeKind = homeKinds.includes(kind);
            if (!["app", "suite", "artifact", ...privilegedKinds].includes(kind) && !prefixKind && !homeKind) {
                return "unsupported_artifact";
            }
            installs++;
            const options = Array.isArray(item) ? item.filter((v) => v && typeof v === "object") : [];
            const paths = [artifact.target, ...options.map((v) => v.target)].filter((p) => p !== undefined);
            if (!paths.length) paths.push(prefixKind ? "$HOMEBREW_PREFIX" : homeKind ? "/$HOME" : "/Applications");
            if (privilegedKinds.includes(kind) || !z.array(z.string()).parse(paths).every(userPath)) authorization = true;
        }
    }
    if (!installs) return "unsupported_artifact";
    if (authorization) return "authorization_required";
    if (!sha(value.sha256)) return "checksum_unavailable";
    return safeVersion(value.version) && value.version !== "latest" ? null : "version_unreadable";
}
