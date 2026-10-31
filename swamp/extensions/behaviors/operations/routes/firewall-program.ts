// Text imports keep the single canonical UFW source in native bundles.
import source from "./firewall-command/scripts/firewall.py" with {
    type: "text",
};
export const FirewallProgram = source;
