"""Converge the TaskVM's only inbound SSH source through UFW's existing policy."""
import json
import subprocess

SOURCE = "10.44.0.15"
ALLOW = ["allow", "in", "on", "orbit", "proto", "tcp", "from", SOURCE,
         "to", "any", "port", "22", "comment", "orbit:task-vm-ssh-209"]
DENY = ["deny", "in", "proto", "tcp", "to", "any", "port", "22",
        "comment", "orbit:task-vm-ssh-only"]


def converge(run=subprocess.run):
    # Reinsert these exact managed rules ahead of the general WireGuard allow.
    for rule in (ALLOW, DENY):
        run(["/usr/sbin/ufw", "--force", "delete", *rule], check=True,
            capture_output=True)
    # prepend also works on a fresh guest's empty ruleset, where insert 1 is invalid.
    for rule in (DENY, ALLOW):
        run(["/usr/sbin/ufw", "prepend", *rule], check=True,
            capture_output=True)
    run(["/usr/sbin/ufw", "--force", "enable"], check=True, capture_output=True)
    return {"ssh_source": SOURCE, "ssh_firewall_ready": True}


if __name__ == "__main__":
    print(json.dumps(converge()))
