import os
import socketserver
import sys
import time

port, log = int(sys.argv[1]), sys.argv[2]


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        with open(log, 'a') as stream:
            stream.write('%.3f %s\n' % (time.time(), self.client_address[0]))
        self.request.sendall(b'ok\n')


class Server(socketserver.TCPServer):
    allow_reuse_address = True
    request_queue_size = 16


# Binding and listening happen in the constructor. Only then does the parent report the pid and exit, so a caller
# that waits for this program knows the port accepts connections.
server = Server(('0.0.0.0', port), Handler)
child = os.fork()
if child:
    print(child, flush=True)
    os._exit(0)
os.setsid()
quiet = os.open(os.devnull, os.O_RDWR)
for descriptor in (0, 1, 2):
    os.dup2(quiet, descriptor)
server.serve_forever()
