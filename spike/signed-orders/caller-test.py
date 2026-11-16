"""SPIKE(signed-orders): connect to an executor socket from the current process and send one order file."""
import socket
import sys

path, order_file = sys.argv[1], sys.argv[2]
s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
try:
    s.connect(path)
except OSError as e:
    print(f"connect refused by the kernel: {e.strerror}")
    sys.exit(0)
s.sendall(open(order_file, 'rb').read())
s.shutdown(socket.SHUT_WR)
print(s.recv(4096).decode() or "(connection closed, no reply)")
