"""Observe TCP reachability from the control worker without authenticating."""

import json
import socket
import sys


def probe(host, port):
    try:
        with socket.create_connection((host, port), timeout=5):
            return {"reachable": True}
    except (ConnectionRefusedError, TimeoutError):
        return {"reachable": False}
    except OSError as error:
        if error.errno in (101, 113):
            return {"reachable": False}
        raise


if __name__ == "__main__":
    print(json.dumps(probe(sys.argv[1], int(sys.argv[2]))))
