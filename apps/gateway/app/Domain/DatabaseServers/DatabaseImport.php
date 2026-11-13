<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;

/**
 * database:import (docs/cli/database/import.mdx): the path rule, the one argv the run sends over SSH, and the reading of
 * its one result line. The shell runs as the Node's SSH user without sudo, so it opens only a file that user can open;
 * it then requires the open file to have exactly the given path (no symbolic link), to be regular and to be owned by
 * that user. The client runs in a throwaway container of the server's image on the server's network and logs in as the
 * owner over TCP at the server container's own address, never its loopback, which the PostgreSQL image trusts. The password
 * comes from the first stdin line.
 *
 * A dump is caller text, and the client would run a client command in it. That container shares the Docker network with
 * other servers, so the client never runs one: psql reads the dump after Orbit's own `\restrict <random key>`, which
 * refuses every backslash command but `\unrestrict` with that key. A pg_dump plain dump has its own `\restrict` after its
 * header comment and the matching `\unrestrict` at its end; those two lines are dropped, never others. mysql runs with its commands
 * (such as system and source) off; a client without these options refuses to start.
 */
final class DatabaseImport
{
    /** The step's time limit: a 2 GB dump at 1 MB/s with room for both table counts. */
    public const int TimeoutSeconds = 3600;

    private const string PATH = '#\A(?:/[A-Za-z0-9_.-]+)+\.sql(?:\.gz)?\z#D';

    private const string NAME = '/\A[A-Za-z0-9_]{1,64}\z/D';

    private const string IMAGE = '/\A[A-Za-z0-9][A-Za-z0-9._\/:@-]*\z/D';

    private const string LINE = '/^orbit-import outcome=(ok|failed|file_refused|login_failed|not_empty) state=(done|unchanged|empty|partial|unknown)'
        .'(?: exit=(\d+))?(?: bytes=(\d+))?(?: tables=(\d+|unknown))?(?: error=([A-Za-z0-9 ()]{1,64}))?$/m';

    private const string SCRIPT = <<<'BASH'
set -u -o pipefail
f=$1 c=$2 img=$3 drv=$4 u=$5 d=$6
say() { printf 'orbit-import %s\n' "$1"; exit 0; }
IFS= read -r pw || say 'outcome=login_failed state=unchanged'
# A FIFO would hold the open until the time limit; the checks on the open file below still decide.
[ -f "$f" ] && [ ! -p "$f" ] && exec 3<"$f" 2>/dev/null || say 'outcome=file_refused state=unchanged'
[ "$(readlink "/proc/$$/fd/3")" = "$f" ] && [ -f "/proc/$$/fd/3" ] && [ "$(stat -L -c %u "/proc/$$/fd/3")" = "$(id -u)" ] \
  || say 'outcome=file_refused state=unchanged'
# The client never shares the server's 127.0.0.1: the server image trusts loopback logins. It joins the server's network
# and reaches the container's own address, where the server asks for the owner's password.
read -r net ip < <(sudo -n docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{$v.IPAddress}}{{"\n"}}{{end}}' "$c" 2>/dev/null | head -n 1) \
  && [[ $net =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ && $net != host && $ip =~ ^[0-9]+(\.[0-9]+){3}$ ]] || say 'outcome=login_failed state=unchanged'
if [ "$drv" = pgsql ]; then
  cl='IFS= read -r PGPASSWORD && export PGPASSWORD && u=$1 d=$2 h=$3 && shift 3 && exec psql --no-psqlrc --host="$h" --username="$u" --dbname="$d" --no-align --tuples-only --quiet --set=ON_ERROR_STOP=1 --set=VERBOSITY=sqlstate "$@"'
  count="SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f') AND n.nspname <> 'information_schema' AND n.nspname NOT LIKE 'pg\_%';"
  load=(--single-transaction --file=-)
  key=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
  [[ $key =~ ^[0-9a-f]{48}$ ]] || say 'outcome=login_failed state=unchanged'
  # Orbit's key first; the dump's own restrict pair is dropped, so nothing in the dump can lift the restriction.
  fence() { printf '\\restrict %s\n' "$key"; awk 'head && /^\\restrict [A-Za-z0-9]+\r?$/ { k = $2; sub(/\r$/, "", k); head = 0; next }
    head && !/^(--.*)?\r?$/ { head = 0 } k != "" && ($0 == "\\unrestrict " k || $0 == "\\unrestrict " k "\r") { next } { print }' head=1; }
else
  fence() { cat; }
  cl='IFS= read -r MYSQL_PWD && export MYSQL_PWD && u=$1 d=$2 h=$3 && shift 3 && exec mysql --skip-commands --skip-system-command --protocol=TCP --host="$h" --user="$u" --database="$d" --batch --skip-column-names "$@"'
  count='SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();'
  load=(--max-allowed-packet=1G)
fi
run() {
  { printf '%s\n' "$pw"; cat; } | sudo -n docker run --rm -i --pull=never --network "$net" --read-only --tmpfs /tmp \
    --cap-drop ALL --security-opt no-new-privileges --user 65534:65534 --entrypoint sh "$img" -c "$cl" sh "$u" "$d" "$ip" "$@" 3<&-
}
tables() { local n; n=$(printf '%s\n' "$count" | run 2>/dev/null) && [[ $n =~ ^[0-9]+$ ]] && printf '%s' "$n"; }
before=$(tables) || say 'outcome=login_failed state=unchanged'
[ "$before" = 0 ] || say "outcome=not_empty state=unchanged tables=$before"
out=$({ { case "$f" in *.gz) gzip -dc <&3 ;; *) cat <&3 ;; esac | fence | run "${load[@]}" >/dev/null; } 2>&1; printf 'rc=%s\n' "$?"; } \
  | sed -n -E -e 's/^(ERROR [0-9]+ \([0-9A-Z]{5}\) at line [0-9]+).*/\1/p' -e 's/^psql:[^:]*:([0-9]+): ERROR: +([0-9A-Z]{5}).*/ERROR \2 at line \1/p' \
    -e 's/^gzip: .*/gzip failed/p' -e 's/^(rc=[0-9]+)$/\1/p')
rc=$(printf '%s\n' "$out" | sed -n 's/^rc=//p' | tail -n 1)
err=$(printf '%s\n' "$out" | grep -v '^rc=' | head -n 1)
bytes=$(sed -n 's/^pos:[[:space:]]*//p' "/proc/$$/fdinfo/3")
after=$(tables) || after=unknown
if [ "$rc" = 0 ]; then say "outcome=ok state=done exit=0 bytes=$bytes tables=$after"; fi
case $after in 0) state=empty ;; unknown) state=unknown ;; *) state=partial ;; esac
say "outcome=failed state=$state exit=$rc bytes=$bytes tables=$after${err:+ error=$err}"
BASH;

    /** The Gateway's half of the path rule; the Node checks the open file. */
    public static function path(string $path): string
    {
        if (strlen($path) > 1024 || preg_match(self::PATH, $path) !== 1 || preg_match('#/\.{1,2}(?:/|\z)#', $path) === 1) {
            throw new ResourceOperationException('database.import_path_invalid',
                'The file must be an absolute path ending in .sql or .sql.gz, with only letters, digits, ".", "_", "-" and "/", and no "." or ".." part.', 422);
        }

        return $path;
    }

    /**
     * The argv for official SSH exec: run as the Node's SSH user, password line on stdin.
     *
     * @return list<string>
     */
    public static function argv(DatabaseDriver $driver, string $file, string $container, string $image, string $user, string $database): array
    {
        if (! in_array($driver, [DatabaseDriver::Mysql, DatabaseDriver::Pgsql], true) || preg_match(self::NAME, $user) !== 1
            || preg_match(self::NAME, $database) !== 1 || preg_match(self::IMAGE, $image) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $container) !== 1) {
            throw new ResourceOperationException('database.import_unsupported', 'The connection is not a MySQL or PostgreSQL database on a Database server.', 422);
        }

        return ['timeout', (string) self::TimeoutSeconds, 'bash', '-c', self::SCRIPT, 'bash', self::path($file), $container, $image,
            $driver->value, $user, $database];
    }

    /**
     * The run's result line as the Operation's error code and result. No table name or data is in it.
     *
     * @return array{error_code: ?string, result: array<string, int|string|null>}
     */
    public static function outcome(string $output): array
    {
        if (preg_match_all(self::LINE, $output, $matches, PREG_SET_ORDER) !== 1) {
            return ['error_code' => 'database.import_failed', 'result' => ['database_state' => 'unknown']];
        }
        [, $outcome, $state] = $matches[0];
        $number = static fn (int $index): ?int => ($matches[0][$index] ?? '') === '' || $matches[0][$index] === 'unknown' ? null : (int) $matches[0][$index];
        $result = array_filter(['database_state' => $state === 'done' ? null : $state, 'exit_code' => $number(3), 'bytes_read' => $number(4),
            'tables' => $number(5), 'error' => ($matches[0][6] ?? '') === '' ? null : $matches[0][6]], static fn (mixed $value): bool => $value !== null);

        return ['error_code' => match ($outcome) {
            'ok' => null,
            'file_refused' => 'database.import_file_refused',
            'login_failed' => 'database.import_login_failed',
            'not_empty' => 'database.not_empty',
            default => 'database.import_failed',
        }, 'result' => $result];
    }
}
