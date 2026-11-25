#!/usr/bin/env bash
# Proof of the Conn librarian push (docs/reference/conn.md#the-thread-librarian): the real Gateway route, queue worker and librarian
# on a throwaway SQLite store, with only the host Conn Server and ai-router faked (conn-librarian-push-fakes.ts).
# Usage: tools/proofs/conn-librarian-push.sh [main]   ("main" runs only the response check, for the code on main).
set -euo pipefail
cd "$(dirname "$0")/../../apps/gateway"
mode="${1:-branch}"
unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy
export NO_PROXY=127.0.0.1,localhost
tmp="$(mktemp -d /tmp/conn-librarian-push.XXXXXX)"
mkdir -p "$tmp"/storage/{framework/{cache/data,sessions,views},logs,app}
export LARAVEL_STORAGE_PATH="$tmp/storage" ORBIT_HOME="$tmp" DB_CONNECTION=sqlite DB_DATABASE="$tmp/gateway.sqlite" APP_ENV=local APP_DEBUG=false
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" CACHE_STORE=file ORBIT_GATEWAY_QUEUE_DRIVER=database LOG_CHANNEL=single
gw=47830 conn=47831 router=47832
export ANTHROPIC_BASE_URL="http://127.0.0.1:$router" ORBIT_MODEL_KEY=proof-model-route-key
pids=()
# A queue worker's start resets the Documents cleanup gate, which lives under /run/orbit; a copy without it gets it for the run.
made_run=""
if [ ! -d /run/orbit ]; then sudo install -d -o "$(id -u)" -m 0755 /run/orbit; made_run=1; fi
cleanup() { kill "${pids[@]}" 2>/dev/null || true; wait 2>/dev/null || true; rm -rf "$tmp"; if [ -n "$made_run" ]; then sudo rm -rf /run/orbit; fi; }
trap cleanup EXIT

tinker() { php artisan tinker --execute="$1" 2>&1 | grep -v '^$'; }
work() { php artisan queue:work gateway --queue=tasks --stop-when-empty --tries=1 2>&1 | sed -E 's/^ +//; s/ +\.{3,} */ /' | grep -v '^$' | sed 's/^/  worker: /'; }
poll() { tinker 'app()->call([new Orbit\Extensions\Conn\Jobs\PollConnHostThreadsJob, "handle"]); echo "poll pass done\n";'; }
jobs() { tinker 'foreach (DB::table("jobs")->orderBy("id")->get() as $j) { echo "  queued on ".$j->queue.": ".json_decode($j->payload)->displayName."\n"; } echo "  jobs queued: ".DB::table("jobs")->count()."\n";'; }
rows() { tinker 'foreach (Orbit\Extensions\Conn\Models\ConnThreadClassification::query()->orderBy("id")->get() as $r) { echo "  classification ".$r->thread_id.": ".json_encode($r->result)." completed=".($r->completed_at !== null ? "yes" : "no")."\n"; } echo "  given_up rows: ".Orbit\Extensions\Conn\Models\ConnThreadClassification::query()->where("result->result", "given_up")->count().", failed jobs: ".DB::table("failed_jobs")->count()."\n";'; }
stats() { curl -s "http://127.0.0.1:$conn/__stats" | php -r '$s = json_decode(stream_get_contents(STDIN), true); echo "  fake totals: ai-router calls ".$s["modelCalls"].", file commands ".$s["fileCommands"]."\n";'; }
fakelog() { curl -s "http://127.0.0.1:$conn/__stats" | php -r '$s = json_decode(stream_get_contents(STDIN), true); foreach (array_slice($s["log"], (int) $argv[1]) as $l) echo "  fake: $l\n";' "$1"; }
loglen() { curl -s "http://127.0.0.1:$conn/__stats" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true)["log"]);'; }
thread() { curl -s -X POST "http://127.0.0.1:$conn/__thread" -H 'content-type: application/json' -d "$1" >/dev/null; }
push() {
  echo "\$ POST /api/v1/conn/environments/env-host/threads {\"thread_id\":\"$1\",\"title\":\"Proof $1\"}"
  curl -s -o "$tmp/body" -w '%{http_code}' -X POST "http://127.0.0.1:$gw/api/v1/conn/environments/env-host/threads" \
    -H 'accept: application/json' -H 'content-type: application/json' -d "{\"thread_id\":\"$1\",\"title\":\"Proof $1\"}" > "$tmp/status"
  echo "  status $(cat "$tmp/status") body $(cat "$tmp/body")"
}

echo "# Conn librarian push proof ($mode)"
echo "started: $(date -u +%FT%TZ)"
echo "checkout commit: $(git rev-parse HEAD)$(git diff --quiet HEAD -- . || echo ' (with the code on main checked out over it)')"
php artisan migrate --force >/dev/null
project="$(tinker 'app(App\Domain\Extensions\ExtensionStore::class)->set("conn", true);
$n = App\Models\Node::withoutEvents(fn () => App\Models\Node::query()->create(["name" => "agent", "status" => "active", "platform" => "linux", "public_ssh_host" => "127.0.0.1", "wireguard_ip" => "127.0.0.1", "user" => "orbit"]));
$n->accessibleNodes()->attach($n->id);
$p = App\Models\Project::query()->create(["name" => "Orbit", "slug" => "orbit", "type" => "generic", "node_id" => $n->id]);
Orbit\Extensions\Conn\Models\ConnEnvironment::query()->create(["environment_id" => "env-host", "label" => "Agent", "url" => "http://127.0.0.1:'"$conn"'", "node_id" => $n->id, "admin_session" => "proof-admin-session", "admin_session_expires_at" => now()->addDay(), "registered_at" => now()]);
echo $p->id;' | tail -1)"
echo "seeded: host Node 127.0.0.1 (active), Orbit Project $project, host Conn environment env-host (instance_id null)"
bun ../../tools/proofs/conn-librarian-push-fakes.ts "$conn" "$router" "$project" & pids+=($!)
(cd public && exec php -S "127.0.0.1:$gw" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1) & pids+=($!)
sleep 1.5

if [ "$mode" = main ]; then
  echo; echo "## Response of conn:thread:record"
  push thread-r; push thread-r
  exit 0
fi

echo; echo "## The host's first librarian poll sets its cutoff (no threads yet)"
poll; work

echo; echo "## A: one push for a new host General thread queues one classification and files it"
thread '{"id":"thread-a"}'; mark=$(loglen)
push thread-a
jobs
work
fakelog "$mark"; rows; stats

echo; echo "## B: the same push plus polls adds no classification"
thread '{"id":"thread-b"}'; mark=$(loglen)
push thread-b
echo "worker runs only the push's discovery:"; php artisan queue:work gateway --queue=tasks --once --tries=1 >/dev/null 2>&1; jobs
echo "a poll while the classification waits:"; poll; jobs
work
echo "a poll after the thread is filed:"; poll; jobs
fakelog "$mark"; rows; stats

echo; echo "## C: a push before the first message is readable reads again, then files it, with no given_up row"
thread '{"id":"thread-c","hiddenReads":2}'; mark=$(loglen)
push thread-c
work
fakelog "$mark"; rows; stats

echo; echo "## Response of conn:thread:record"
push thread-r; push thread-r
work >/dev/null
echo "finished: $(date -u +%FT%TZ)"
