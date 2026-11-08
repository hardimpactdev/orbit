"""Usage: probe.py ADDRESS PORT PROTO FAMILY SECONDS. Exit 0 if the port answers, 124 if nothing answers in time, 111 if it refuses."""
import socket
import sys

address, port, proto, family, seconds = sys.argv[1], int(sys.argv[2]), sys.argv[3], int(sys.argv[4]), float(sys.argv[5])
sock = socket.socket(socket.AF_INET6 if family == 6 else socket.AF_INET, socket.SOCK_DGRAM if proto == 'udp' else socket.SOCK_STREAM)
sock.settimeout(seconds)
try:
    sock.connect((address, port))
    if proto == 'udp':  # a datagram has no handshake: the listener's answer is the proof
        sock.send(b'probe\n')
        sock.recv(16)
except socket.timeout:
    sys.exit(124)
except ConnectionRefusedError:
    sys.exit(111)
except OSError:
    sys.exit(1)
