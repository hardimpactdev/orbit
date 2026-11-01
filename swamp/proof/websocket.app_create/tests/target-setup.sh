#!/usr/bin/env bash
# Disposable F10 container only. Credentials come from the proof runner.
set -euo pipefail
umask 077
proof_root=/var/lib/orbit-swamp-proof/websocket.app_create
secret_file="${PROOF_KIND_DIR:?}/secrets/websocket-registry-8f61a312-2b19-4ef7-92d5-39dff3d42381"
test -r "$secret_file"
install -d -m 0700 "$proof_root"
command -v php >/dev/null
command -v composer >/dev/null
php -r 'exit(version_compare(PHP_VERSION, "8.4", ">=") ? 0 : 1);'
git clone --quiet https://github.com/hardimpactdev/orbit-reverb.git "$proof_root/reverb"
git -C "$proof_root/reverb" fetch --quiet origin 735c5ccc253b83d312f8ce53c64b2b0ce6dfaed0
git -C "$proof_root/reverb" checkout --quiet --detach 735c5ccc253b83d312f8ce53c64b2b0ce6dfaed0
composer --working-dir="$proof_root/reverb" install --no-interaction --prefer-dist --no-progress >"$proof_root/install.log" 2>&1
cat >"$proof_root/authority.py" <<'PY'
"""A loopback-only disposable registry authority, never a production Gateway."""
import json
import os
import secrets
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

ROOT = Path('/var/lib/orbit-swamp-proof/websocket.app_create')
TOKEN = Path(os.environ['PROOF_REGISTRY_TOKEN_FILE']).read_text()
state = {'boot_id': None, 'revision': 0, 'state': 'starting', 'reads': 0}
material = {}


def app(name, generation=1):
    secret = material.setdefault(name + ':' + str(generation), secrets.token_hex(20))
    return {'app_id': name, 'key': name + '-key', 'secret': secret,
            'credential_generation': generation, 'ping_interval': 60, 'activity_timeout': 30,
            'allowed_origins': ['*'], 'max_message_size': 10000, 'max_connections': None,
            'accept_client_events_from': 'members', 'rate_limiting': None, 'options': []}


def snapshot(revision, apps):
    return {'schema_version': 1, 'epoch': 'proof-registry', 'revision': revision, 'apps': apps}


(ROOT / 'snapshot.json').write_text(json.dumps(snapshot(1, [app('retired'), app('rotating'), app('unrelated')])))
(ROOT / 'next.json').write_text(json.dumps(snapshot(2, [app('created'), app('rotating', 2), app('unrelated')])))
(ROOT / 'publisher-secrets.json').write_text(json.dumps(material))


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def reply(self, code, value=None):
        body = b'' if value is None else json.dumps(value).encode()
        self.send_response(code)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == '/state':
            return self.reply(200, state)
        if self.path != '/registry' or self.headers.get('Authorization') != 'Bearer ' + TOKEN:
            return self.reply(403)
        state['reads'] += 1
        return self.reply(200, json.loads((ROOT / 'snapshot.json').read_text()))

    def do_POST(self):
        if not self.path.endswith('/boots') or self.headers.get('Authorization') != 'Bearer ' + TOKEN:
            return self.reply(403)
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        state['boot_id'] = data['boot_id']
        return self.reply(204)

    def do_PUT(self):
        if self.headers.get('Authorization') != 'Bearer ' + TOKEN:
            return self.reply(403)
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if data['boot_id'] != state['boot_id']:
            return self.reply(409)
        state.update({key: data[key] for key in ['revision', 'state']})
        return self.reply(204)


HTTPServer(('127.0.0.1', 18793), Handler).serve_forever()
PY
cat >"$proof_root/boot.php" <<'PHP'
<?php
require '/var/lib/orbit-swamp-proof/websocket.app_create/reverb/vendor/autoload.php';
$app = require '/var/lib/orbit-swamp-proof/websocket.app_create/reverb/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set([
    'registry.registry_url' => 'http://127.0.0.1:18793/registry',
    'registry.registry_token' => file_get_contents(getenv('PROOF_REGISTRY_TOKEN_FILE')),
    'registry.notification_token' => null,
    'registry.process_id' => '8f61a312-2b19-4ef7-92d5-39dff3d42381',
    'registry.heartbeat_interval' => 300,
    'registry.reconcile_interval' => 300,
    'cache.default' => 'array',
    'reverb.servers.reverb.port' => 18792,
    'reverb.servers.reverb.scaling.enabled' => false,
]);
app(Laravel\Reverb\ServerProviderManager::class)->forgetDrivers();
app(Laravel\Reverb\ServerProviderManager::class)->register();
Illuminate\Support\Facades\Artisan::call('orbit:reverb:start', ['--host' => '127.0.0.1']);
PHP
systemd-run --quiet --unit=orbit-proof-authority-websocket.app_create --setenv="PROOF_REGISTRY_TOKEN_FILE=$secret_file" /usr/bin/python3 "$proof_root/authority.py"
systemd-run --quiet --unit=orbit-proof-reverb-websocket.app_create --working-directory="$proof_root/reverb" --setenv=APP_ENV=testing --setenv=CACHE_STORE=array --setenv=DB_CONNECTION=sqlite --setenv=DB_DATABASE=:memory: --setenv="PROOF_REGISTRY_TOKEN_FILE=$secret_file" "$(command -v php)" "$proof_root/boot.php"
# Bounded setup readiness only. The kind workflow itself has no wait or retry.
python3 - <<'PY'
import json
import socket
import time
import urllib.request
from pathlib import Path

for _ in range(100):
    try:
        with urllib.request.urlopen('http://127.0.0.1:18793/state', timeout=.2) as response:
            state = json.load(response)
        if state['revision'] == 1 and state['state'] == 'serving':
            with socket.create_connection(('127.0.0.1', 18792), timeout=.2):
                pass
            Path('/var/lib/orbit-swamp-proof/websocket.app_create/next.json').replace('/var/lib/orbit-swamp-proof/websocket.app_create/snapshot.json')
            break
    except (OSError, ValueError):
        pass
    time.sleep(.1)
else:
    raise SystemExit('Disposable Reverb did not become ready.')
PY
