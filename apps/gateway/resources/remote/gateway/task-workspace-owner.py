# Program: gateway.task-workspace-owner
# Uses: lib/program.py
# Outcomes: inspected
# Timeout: 30
# Input: {"host": recorded hostname, "boot": original boot UUID, "pid": exact process ID, "started": start ticks}
# Result: inspected: {"host": observed hostname, "dead": bool}.
# A changed boot on the pinned matching host proves death; the original boot requires exact process identity.
import os
import socket


def main(payload):
    host, boot, pid, started = (payload[key] for key in ('host', 'boot', 'pid', 'started'))
    dead = False
    if socket.gethostname() == host:
        with open('/proc/sys/kernel/random/boot_id') as source:
            current_boot = source.read().strip()
        if not current_boot:
            return 'inspected', {'host': socket.gethostname(), 'dead': False}
        if current_boot != boot:
            dead = True
        else:
            try:
                with open('/proc/' + str(pid) + '/stat') as source:
                    stat = source.read()
                dead = stat[stat.rfind(')') + 2:].split()[19] != started
            except FileNotFoundError:
                dead = not os.path.exists('/proc/' + str(pid))
    return 'inspected', {'host': socket.gethostname(), 'dead': dead}


run_program(main)
