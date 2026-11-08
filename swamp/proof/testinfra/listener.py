"""Usage: listener.py LOG PROTO FAMILY PORT [PORT ...]. Listen on every port, answer `ok`, and log each client. PROTO is tcp or udp, FAMILY 4 or 6."""
import os
import socket
import socketserver
import sys
import threading
import time

log, proto, family = sys.argv[1], sys.argv[2], int(sys.argv[3])
ports = [int(item) for item in sys.argv[4:]]


def note(address):
    with open(log, 'a') as stream:
        stream.write('%.3f %s\n' % (time.time(), address))


class TcpHandler(socketserver.BaseRequestHandler):
    def handle(self):
        note(self.client_address[0])
        self.request.sendall(b'ok\n')


class UdpHandler(socketserver.BaseRequestHandler):
    def handle(self):
        note(self.client_address[0])
        self.request[1].sendto(b'ok\n', self.client_address)


class Bound:
    allow_reuse_address = True
    request_queue_size = 16
    address_family = socket.AF_INET6 if family == 6 else socket.AF_INET

    def server_bind(self):
        if family == 6:  # only IPv6: a listener of the other family may use the same port
            self.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
        super().server_bind()


class Tcp(Bound, socketserver.TCPServer):
    pass


class Udp(Bound, socketserver.UDPServer):
    pass


# Binding and listening happen in the constructors. Only then does the parent report the pid and exit, so a caller
# that waits for this program knows every port accepts connections.
kind, handler = (Tcp, TcpHandler) if proto == 'tcp' else (Udp, UdpHandler)
servers = [kind(('::' if family == 6 else '0.0.0.0', port), handler) for port in ports]
child = os.fork()
if child:
    print(child, flush=True)
    os._exit(0)
os.setsid()
quiet = os.open(os.devnull, os.O_RDWR)
for descriptor in (0, 1, 2):
    os.dup2(quiet, descriptor)
for server in servers[1:]:
    threading.Thread(target=server.serve_forever, daemon=True).start()
servers[0].serve_forever()
