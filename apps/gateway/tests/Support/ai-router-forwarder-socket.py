#!/usr/bin/env python3
"""TCP stand-in and probe for the model forwarder's real-kernel table proof."""

import socket
import sys

mode, address, port = sys.argv[1], sys.argv[2], int(sys.argv[3])
if mode == "listen":
    with socket.socket() as listener:
        listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        listener.bind((address, port))
        listener.listen()
        print("ready", flush=True)
        while True:
            connection, _ = listener.accept()
            connection.close()
elif mode == "connect":
    try:
        with socket.create_connection((address, port), timeout=0.4):
            pass
    except ConnectionRefusedError:
        sys.exit(7)
    except TimeoutError:
        sys.exit(28)
    except OSError as error:
        print(error, file=sys.stderr)
        sys.exit(1)
else:
    sys.exit("Expected listen or connect")
