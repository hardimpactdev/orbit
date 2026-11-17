<?php

declare(strict_types=1);
use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Executor\ExecutorProofOperation;
use App\Domain\Executor\SignedExecutorOrder;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitResult;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

// Test support only. Runs inside the nested Gateway as its managed user, never installed in a release.
//
// The executor's proof key (docs/reference/executor.md#proof-key). `key` makes a proof-only Ed25519 key pair once: the secret
// half stays in this Gateway's own `storage/app/private`, mode 0600, readable only by this user, and becomes its signing key
// ORBIT_EXECUTOR_SIGNING_KEY; the public half is pinned through ORBIT_EXECUTOR_PUBLIC_KEYS in this Gateway's own `.env`, so its
// next converge of an app-dev Node pins it there. `order` signs one `proof.noop` order with it, or a tampered one for the order
// security tests. `publish` sends orders over the observer's WebSocket through the Gateway's own signer and publisher.
// `round_trip` and `lost_result` also wait for each result as a waiting request does. Every order published here belongs to a real
// `executor.proof` Operation on the Node (docs/reference/executor.md#the-proof-operation): this script is the only caller of
// ExecutorProofOperation. No key is ever committed, printed or leaves this Gateway.

/** An action no executor knows: no row of the order table (docs/reference/executor.md), so `unknown_action` can never run. */
const PROOF_UNKNOWN_ACTION = 'proof.unknown';

/** @return array{public_key: string, changed: bool} */
function proofKey(string $secretPath, string $envPath): array
{
    $changed = false;
    if (! is_file($secretPath)) {
        @mkdir(dirname($secretPath), 0o700, true);
        $previous = umask(0o077);
        try {
            if (file_put_contents($secretPath, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), LOCK_EX) === false) {
                throw new RuntimeException('The proof key cannot be written.');
            }
        } finally {
            umask($previous);
        }
        $changed = true;
    }
    chmod($secretPath, 0o600);
    $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey(secret($secretPath)));
    $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
    $next = envLine(envLine($env, 'ORBIT_EXECUTOR_PUBLIC_KEYS', $public), 'ORBIT_EXECUTOR_SIGNING_KEY', base64_encode(secret($secretPath)));
    if ($next !== $env) {
        file_put_contents($envPath, $next, LOCK_EX);
        $changed = true;
    }

    return ['public_key' => $public, 'changed' => $changed];
}

function envLine(string $env, string $name, string $value): string
{
    $line = $name.'='.$value;
    $pinned = preg_replace('/^'.$name.'=.*$/m', $line, $env, -1, $count);

    return $count > 0 ? (string) $pinned : rtrim($env, "\n")."\n".$line."\n";
}

/**
 * One `proof.noop` order. `tamper` makes the order of one order security test: `bad_signature` (another key signs),
 * `changed_action` or `changed_target` (the payload changes after signing), `expired`, `unknown_action` or `wrong_node`.
 *
 * @param  array<string, mixed>  $input
 * @return array{order: string, nonce: string}
 */
function proofOrder(string $secretPath, array $input): array
{
    if (! is_file($secretPath)) {
        throw new RuntimeException('No executor proof key: run the key mode first.');
    }
    $tamper = $input['tamper'] ?? null;
    $nonce = is_string($input['nonce'] ?? null) ? $input['nonce'] : bin2hex(random_bytes(16));
    $now = (int) floor(microtime(true) * 1000);
    $fields = [
        'action' => $tamper === 'unknown_action' ? PROOF_UNKNOWN_ACTION : 'proof.noop',
        'node' => $tamper === 'wrong_node' ? (string) ((int) $input['node'] + 1000) : (string) $input['node'],
        'target' => (string) ($input['target'] ?? $input['node']),
        'operation' => (string) $input['operation'],
        'nonce' => $nonce,
        'expires_ms' => $tamper === 'expired' ? $now - 1000 : $now + 5000,
    ];
    $payload = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $key = $tamper === 'bad_signature' ? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()) : secret($secretPath);
    $signature = sodium_crypto_sign_detached($payload, $key);
    if (in_array($tamper, ['changed_action', 'changed_target'], true)) {
        $fields[$tamper === 'changed_action' ? 'action' : 'target'] = $tamper === 'changed_action' ? 'proof.other' : $fields['target'].'0';
        $payload = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    return ['order' => base64_encode($payload).'.'.base64_encode($signature), 'nonce' => $nonce];
}

/**
 * Publishes `count` orders, one after another, as `executor-order` on the Node's channel through the Gateway's own publisher.
 * An untampered order goes through the Gateway's own signer (ORBIT_EXECUTOR_SIGNING_KEY); a tampered one is made by
 * {@see proofOrder}. `replay` publishes each order a second time, byte for byte, right after the first. Each order gets its own
 * `executor.proof` Operation on this Node, so the listener records its result. After the last publish each Operation waits once
 * for its result and closes. Each entry records the Gateway time just before its publish, for the latency to the executor.
 *
 * @param  array<string, mixed>  $input
 * @return array{orders: list<array{operation: string, nonce: string, published_ms: int, replay: bool}>}
 */
function proofPublish(string $secretPath, array $input): array
{
    $node = Node::query()->findOrFail((int) $input['node']);
    $count = max(1, min(50, (int) ($input['count'] ?? 1)));
    $publisher = app(ExecutorOrderPublisher::class);
    $proof = app(ExecutorProofOperation::class);

    // One proof run: a throw at any point, in a publish or a wait, fails every Operation it recorded that is still open.
    return $proof->run($node, function (Closure $start) use ($secretPath, $input, $node, $count, $publisher, $proof): array {
        $operations = [];
        $orders = [];
        for ($index = 0; $index < $count; $index++) {
            $operation = $operations[] = $start();
            if (isset($input['tamper'])) {
                $made = proofOrder($secretPath, ['node' => (string) $node->id, 'operation' => (string) $operation->id] + $input);
                $order = new SignedExecutorOrder($made['order'], (string) $operation->id, $made['nonce'], 0);
            } else {
                $order = ExecutorOrderSigner::fromConfig()->sign($operation, 'proof.noop', (string) $node->id);
            }
            $published = (int) floor(microtime(true) * 1000);
            $publisher->publish($operation, $order);
            $orders[] = ['operation' => $order->operation, 'nonce' => $order->nonce, 'published_ms' => $published, 'replay' => false];
            // A true replay: the exact wire again, same signature and Operation, at once and so well inside its 5 s expiry.
            if (($input['replay'] ?? false) === true) {
                $replayed = (int) floor(microtime(true) * 1000);
                $publisher->publish($operation, $order);
                $orders[] = ['operation' => $order->operation, 'nonce' => $order->nonce, 'published_ms' => $replayed, 'replay' => true];
            }
        }
        foreach ($operations as $operation) {
            $proof->finish($operation, $proof->wait($operation));
        }

        return ['orders' => $orders];
    });
}

/**
 * `count` round trips, one after another, on the waiting request's path (ADR 0266 "The path"): each records an `executor.proof`
 * Operation, signs and publishes its order, and waits with ExecutorResultWait within ORBIT_EXECUTOR_WAIT_MS. `latency_ms` runs from
 * just before the publish to the result in the waiter; `recorded` is what the listener stored on the Operation.
 *
 * @param  array<string, mixed>  $input
 * @return array{wait_ms: int, round_trips: list<array<string, mixed>>}
 */
function proofRoundTrip(array $input): array
{
    $node = Node::query()->findOrFail((int) $input['node']);
    $proof = app(ExecutorProofOperation::class);
    $count = max(1, min(50, (int) ($input['count'] ?? 1)));

    return $proof->run($node, function (Closure $start) use ($proof, $count): array {
        $trips = [];
        for ($index = 0; $index < $count; $index++) {
            $operation = $start();
            $started = hrtime(true);
            $order = $proof->send($operation, ExecutorOrderSigner::fromConfig());
            $result = $proof->wait($operation);
            $trips[] = ['operation' => (string) $operation->id, 'nonce' => $order->nonce, 'latency_ms' => elapsedMs($started)] + waited($result)
                + ['recorded' => $operation->refresh()->executor_result];
            $proof->finish($operation, $result);
        }

        return ['wait_ms' => (int) round(ExecutorResultWait::boundSeconds() * 1000), 'round_trips' => $trips];
    });
}

/**
 * A lost result recovered by a repeat. The first order runs, and the listener records its result and hands it to Valkey. A request
 * that takes the hand-off and dies loses it: here the first wait takes it and drops it, and the Operation's keys are deleted. A new
 * waiting request then gets nothing within its bound. The Gateway signs a new order, with a new nonce, for the same Operation: the
 * executor answers `repeat` with the first result, the listener pushes it again, and the waiting request gets it. The runner checks
 * the executor's journal for one run.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function proofLostResult(array $input): array
{
    $node = Node::query()->findOrFail((int) $input['node']);
    $proof = app(ExecutorProofOperation::class);

    return $proof->run($node, function (Closure $start) use ($proof): array {
        $operation = $start();
        $first = $proof->send($operation, ExecutorOrderSigner::fromConfig());
        $dropped = $proof->wait($operation);
        app('redis')->connection('gateway')->command('del', [ExecutorResults::resultKey($operation->id), ExecutorResults::acceptedKey($operation->id)]);
        $lost = $proof->wait($operation);
        $started = hrtime(true);
        $repeat = $proof->send($operation, ExecutorOrderSigner::fromConfig());
        $recovered = $proof->wait($operation);
        $latency = elapsedMs($started);
        $proof->finish($operation, $recovered);

        return ['operation' => (string) $operation->id, 'first_nonce' => $first->nonce, 'repeat_nonce' => $repeat->nonce,
            'recorded' => $operation->refresh()->executor_result, 'dropped' => waited($dropped), 'lost' => waited($lost),
            'recovered' => ['latency_ms' => $latency] + waited($recovered), 'status' => $operation->status->value];
    });
}

/** @return array{status: string, accepted_ms: float|null, wait_ms: float, line: array<string, mixed>|null} */
function waited(ExecutorWaitResult $result): array
{
    return ['status' => $result->status->value, 'accepted_ms' => $result->acceptedMs, 'wait_ms' => $result->elapsedMs, 'line' => $result->line];
}

function elapsedMs(int $started): float
{
    return round((hrtime(true) - $started) / 1_000_000, 3);
}

function secret(string $secretPath): string
{
    $secret = base64_decode(trim((string) file_get_contents($secretPath)), true);
    if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('The executor proof key is malformed.');
    }

    return $secret;
}

/**
 * One durable power proof Operation. The caller resets the same nested Instance between samples.
 * No production route or schedule calls this mode.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function proofPower(array $input): array
{
    $node = Node::query()->findOrFail((int) $input['node']);
    $target = (string) $input['instance'];
    $instance = ctype_digit($target) ? Instance::query()->findOrFail((int) $target)
        : Instance::query()->where('node_id', $node->id)->where('name', $target)->sole();
    $action = (string) ($input['action'] ?? 'sleep');
    $proof = app(ExecutorProofOperation::class);

    return $proof->run($node, function (Closure $start) use ($proof, $instance): array {
        $operation = $start();
        $started = hrtime(true);
        $proof->send($operation, ExecutorOrderSigner::fromConfig());
        $result = $proof->wait($operation, 9.0);
        $latency = elapsedMs($started);
        $proof->finish($operation, $result);

        return ['operation' => (string) $operation->id, 'instance' => $instance->id, 'instance_name' => $instance->name, 'latency_ms' => $latency] + waited($result)
            + ['recorded' => $operation->refresh()->executor_result, 'operation_status' => $operation->status->value];
    }, $instance, $action);
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require __DIR__.'/../../apps/gateway/vendor/autoload.php';
    $app = require __DIR__.'/../../apps/gateway/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $secretPath = storage_path('app/private/executor-proof.key');

    $result = match ($input['mode'] ?? null) {
        'key' => proofKey($secretPath, base_path('.env')),
        'order' => proofOrder($secretPath, $input),
        'publish' => proofPublish($secretPath, $input),
        'round_trip' => proofRoundTrip($input),
        'power' => proofPower($input),
        'lost_result' => proofLostResult($input),
        default => throw new InvalidArgumentException('Unknown executor proof mode.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}
