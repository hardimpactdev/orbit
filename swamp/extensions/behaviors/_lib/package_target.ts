import { type PackageTarget } from "./package_target_schema.ts";
export {
  type PackageTarget,
  PackageTargetSchema,
} from "./package_target_schema.ts";
export function packageDescription(t: PackageTarget) {
  return t.targets.map((t) =>
    `${t.path}: ${t.manager} ${t.package} → ${t.version} (${t.purpose})${
      t.advisories.length
        ? `; advisories: ${
          t.advisories.map((id, i) => `${id}: ${t.advisoryUrls[i]}`).join(", ")
        }`
        : ""
    }`
  ).join("; ");
}
