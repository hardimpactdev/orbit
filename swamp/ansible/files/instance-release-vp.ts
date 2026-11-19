/** Run as the Instance user. The Swamp Node bundles the same selection rules used by direct deployments. */
import { execFileSync } from "node:child_process";
import {
    closeSync,
    fstatSync,
    openSync,
    readFileSync,
    realpathSync,
} from "node:fs";
import process from "node:process";
import { vpRequirements } from "../../extensions/behaviors/operations/instance-deploy/vp_requirements.ts";
import {
    material,
    verifyVpCli,
    verifyVpInventory,
    workspaceLinks,
} from "../../extensions/behaviors/operations/instance-vp-command/requirements.ts";

const application = realpathSync(process.argv[2]);
const store = "/home/orbit/.local/share/vite-plus";
const binary = "/opt/orbit-runtime/current/bin/vp";

function file(path: string): string {
    let resolved: string;
    try {
        resolved = realpathSync(path);
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === "ENOENT") return "";
        throw error;
    }
    if (!resolved.startsWith(application + "/")) {
        throw new Error("tool.project_invalid");
    }
    const fd = openSync(resolved, "r");
    try {
        const stat = fstatSync(fd);
        if (!stat.isFile() || stat.size > 4 * 1024 * 1024) {
            throw new Error("tool.project_invalid");
        }
        return readFileSync(fd, "utf8");
    } finally {
        closeSync(fd);
    }
}

function provision() {
    const pkg = file(application + "/package.json");
    const pnpm = file(application + "/pnpm-lock.yaml");
    const links = workspaceLinks(application, pkg, pnpm);
    const config = material({
        package: pkg,
        pnpm_lock: pnpm,
        npm_lock: file(application + "/package-lock.json"),
        node_version: file(application + "/.node-version"),
        nvmrc: file(application + "/.nvmrc"),
        home: "/home/orbit",
        store: store + "/bin/vp",
        links,
        workspaces: links.map(({ directory }, index) => ({
            step: "workspace-" + index,
            stdout: file(directory + "/package.json"),
        })),
    });
    const runtime = vpRequirements(config, store);
    if (!runtime) return [];
    const manager = runtime.package_manager + "@" +
        runtime.package_manager_version +
        (runtime.package_manager_hash
            ? "+" + runtime.package_manager_hash
            : "");
    const env = { ...process.env, VP_HOME: store };
    const vp = (...args: string[]) =>
        execFileSync(binary, args, {
            cwd: "/home/orbit",
            env,
            encoding: "utf8",
            timeout: 900_000,
            stdio: ["ignore", "pipe", "pipe"],
        });
    verifyVpCli(runtime, vp("--version"));
    vp("env", "install", "node@" + runtime.node_version, manager);
    verifyVpInventory(runtime, vp("env", "list", "--json"));
    return [
        "env",
        "VP_HOME=" + store,
        binary,
        "env",
        "exec",
        "--node",
        runtime.node_version,
        "--package-manager",
        manager,
        "--",
    ];
}
try {
    console.log(JSON.stringify(provision()));
} catch {
    process.exitCode = 1;
}
