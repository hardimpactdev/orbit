# Program: database.write
# Uses: lib/program.py
# Outcomes: applied refused
# Timeout: 60
# Input: recorded PDO DSN and username, protected password and one validated SQL statement.
# Result: affected row count, without native output or diagnostics.
import subprocess

PHP = r'''
$p = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
try {
    $pdo = new PDO($p['dsn'], $p['username'], $p['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_EMULATE_PREPARES => false]);
    $statement = $pdo->query($p['sql']);
    echo json_encode(['row_count' => max(0, $statement->rowCount()), 'truncated' => false]);
} catch (Throwable $error) { exit(1); }
'''


def main(payload):
    result = subprocess.run(['php', '-r', PHP], input=json.dumps(payload), text=True, capture_output=True, timeout=45)
    if result.returncode != 0:
        return 'refused', {'error_code': 'database.query_failed'}
    return 'applied', {'backend': 'active', **json.loads(result.stdout)}


run_program(main)
