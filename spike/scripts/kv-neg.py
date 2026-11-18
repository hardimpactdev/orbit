import json, socket, time
c = json.load(open('/etc/orbit/agent/nats.json'))
host, port = c['url'].replace('nats://', '').split(':')
s = socket.create_connection((host, int(port)), 3); s.recv(4096)
s.sendall(('CONNECT %s\r\n' % json.dumps({'user': c['user'], 'pass': c['password'], 'verbose': False, 'pedantic': False})).encode())
def ask(subject):
    s.sendall(('SUB _INBOX.t 1\r\nPUB %s _INBOX.t 0\r\n\r\nPING\r\n' % subject).encode()); time.sleep(0.5)
    out = s.recv(65536).decode(errors='replace')
    return 'denied' if 'Permissions Violation' in out else ('reply' if 'MSG' in out else out.strip()[:60])
print('  own key  (n3):', ask('$JS.API.DIRECT.GET.KV_CONTENT.$KV.CONTENT.n3.' + '0' * 64))
print('  other key (n4):', ask('$JS.API.DIRECT.GET.KV_CONTENT.$KV.CONTENT.n4.' + '0' * 64))
