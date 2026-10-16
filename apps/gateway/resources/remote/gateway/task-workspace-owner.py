# Program: gateway.task-workspace-owner
# Uses: lib/program.py
# Outcomes: inspected
# Timeout: 30
# Input: {"host": recorded hostname, "boot": original boot UUID, "pid": exact process ID, "started": start ticks}
# Result: inspected: {"host": observed hostname, "dead": bool}.
# Proves death only on the recorded original host boot; ambiguity retains ownership.
import os
import socket


def main(payload):
    host, boot, pid, started = (payload[key] for key in ('host', 'boot', 'pid', 'started'))
    dead = False
    if socket.gethostname() == host:
        with open('/proc/sys/kernel/random/boot_id') as source:
            current_boot = source.read().strip()
        # An equal boot binds this proof to the original host, even with duplicate hostnames.
        if current_boot == boot:
            try:
                with open('/proc/' + str(pid) + '/stat') as source:
                    stat = source.read()
                dead = stat[stat.rfind(')') + 2:].split()[19] != started
            except FileNotFoundError:
                dead = not os.path.exists('/proc/' + str(pid))
    return 'inspected', {'host': socket.gethostname(), 'dead': dead}


run_program(main)
