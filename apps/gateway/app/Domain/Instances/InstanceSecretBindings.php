<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\InstanceSecretBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Secret bindings (ADR 0254, docs/reference/development-containers.md#secret-bindings). A binding marks one stored environment key of
 * a container Instance. The stored value stays the only value: every write of the container's `.env` takes `environment()`, which
 * puts the placeholder in its place, and the same run writes `nodeFile()` for the Node's egress proxy, which holds the value.
 */
final readonly class InstanceSecretBindings
{
    public const int MaximumHosts = 8;

    public const int MaximumHeaders = 4;

    /** The value the app itself uses can never be a placeholder. */
    public const array RefusedKeys = ['APP_KEY'];

    /** Headers that frame the request or the proxy hop; the proxy never injects into them. */
    public const array RefusedHeaders = ['connection', 'content-length', 'host', 'keep-alive', 'te', 'trailer', 'transfer-encoding', 'upgrade'];

    private const string Alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct(private InstanceEnvironmentStore $store) {}

    /**
     * Marks a stored key, or changes the hosts and headers of its mark. A binding keeps its placeholder unless a new prefix is given.
     *
     * @param  list<string>  $hosts
     * @param  list<string>  $headers
     * @return array{binding: InstanceSecretBinding, changed: bool}
     */
    public function bind(Instance $instance, string $key, array $hosts, array $headers, ?string $prefix): array
    {
        $this->assertContainer($instance);
        $hosts = self::hosts($hosts);
        $headers = self::headers($headers === [] ? ['Authorization'] : $headers);
        if ($prefix !== null && preg_match('/\A[A-Za-z0-9_-]{1,16}\z/D', $prefix) !== 1) {
            throw ValidationException::withMessages(['prefix' => ['The prefix is 1 to 16 letters, digits, `_` or `-`.']]);
        }
        if (in_array($key, self::RefusedKeys, true)) {
            throw new ResourceOperationException('instance.secret_key_refused', "[{$key}] is used by the app itself and cannot be a placeholder.", 422);
        }

        return DB::transaction(function () use ($instance, $key, $hosts, $headers, $prefix): array {
            if (($this->store->values($instance)[$key] ?? '') === '') {
                throw new ResourceOperationException('instance.secret_key_missing', "The Instance has no stored value for [{$key}]. Set it with env:update first.", 422);
            }
            $binding = InstanceSecretBinding::query()->where('instance_id', $instance->id)->where('env_key', $key)->first();
            $placeholder = $binding !== null && ($prefix === null || str_starts_with($binding->placeholder, $prefix.'orbit_secret_'))
                ? $binding->placeholder
                : self::placeholder($prefix);
            if ($binding !== null && $binding->hosts === $hosts && $binding->headers === $headers && $binding->placeholder === $placeholder) {
                return ['binding' => $binding, 'changed' => false];
            }
            $binding ??= new InstanceSecretBinding(['instance_id' => $instance->id, 'env_key' => $key]);
            $binding->fill(['hosts' => $hosts, 'headers' => $headers, 'placeholder' => $placeholder])->save();

            return ['binding' => $binding, 'changed' => true];
        });
    }

    public function unbind(Instance $instance, string $key): InstanceSecretBinding
    {
        $binding = InstanceSecretBinding::query()->where('instance_id', $instance->id)->where('env_key', $key)->first();
        if ($binding === null) {
            throw new ResourceOperationException('instance.secret_binding_missing', "The Instance has no secret binding for [{$key}].", 404);
        }
        $binding->delete();

        return $binding;
    }

    /** @return list<InstanceSecretBinding> */
    public function list(Instance $instance): array
    {
        return array_values(InstanceSecretBinding::query()->where('instance_id', $instance->id)->orderBy('env_key')->get()->all());
    }

    /**
     * The values a container's `.env` gets: each bound key holds its placeholder instead of its value.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public function environment(Instance $instance, #[\SensitiveParameter] array $values): array
    {
        foreach ($this->list($instance) as $binding) {
            if (array_key_exists($binding->env_key, $values)) {
                $values[$binding->env_key] = $binding->placeholder;
            }
        }

        return $values;
    }

    /**
     * The proxy's file for this container, `/etc/orbit/egress/containers/<container>.json`: its address and each binding with the
     * stored value. A binding whose key has no value now is left out, as the key is gone from `.env` too. It reaches the Node only
     * through the vault, never the intent.
     *
     * @param  array<string, string>  $values  the stored values, not `environment()`'s
     */
    public function nodeFile(Instance $instance, InstanceRuntime $runtime, #[\SensitiveParameter] array $values): string
    {
        $bindings = [];
        foreach ($this->list($instance) as $binding) {
            $value = $values[$binding->env_key] ?? '';
            if ($value !== '') {
                $bindings[] = ['key' => $binding->env_key, 'placeholder' => $binding->placeholder, 'hosts' => $binding->hosts,
                    'headers' => $binding->headers, 'value' => $value];
            }
        }

        return json_encode(['name' => $runtime->container_name, 'address' => $runtime->bridge_address, 'bindings' => $bindings],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** A clone keeps its default's bindings and placeholders: its `.env` comes from the default's. */
    public function copyForClone(Instance $source, Instance $target): void
    {
        foreach ($this->list($source) as $binding) {
            InstanceSecretBinding::query()->updateOrCreate(['instance_id' => $target->id, 'env_key' => $binding->env_key],
                ['hosts' => $binding->hosts, 'headers' => $binding->headers, 'placeholder' => $binding->placeholder]);
        }
    }

    /** @return array<string, mixed> */
    public static function present(InstanceSecretBinding $binding): array
    {
        return ['instance_id' => $binding->instance_id, 'key' => $binding->env_key, 'hosts' => $binding->hosts,
            'headers' => $binding->headers, 'placeholder' => $binding->placeholder];
    }

    /**
     * Exact names or `*.` plus a name; letters, digits, `-` and `_`, no empty label, no address, and a last label that is not all
     * digits. The rule is the proxy's own (apps/egress/internal/proxy/names.go).
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    public static function hosts(array $hosts): array
    {
        if ($hosts === [] || count($hosts) > self::MaximumHosts) {
            throw ValidationException::withMessages(['hosts' => ['Give one to '.self::MaximumHosts.' hosts.']]);
        }
        $out = [];
        foreach ($hosts as $host) {
            $name = strtolower(rtrim($host, '.'));
            $bare = str_starts_with($name, '*.') ? substr($name, 2) : $name;
            $labels = explode('.', $bare);
            $last = end($labels);
            if (strlen($bare) > 253 || filter_var($bare, FILTER_VALIDATE_IP) !== false || ! is_string($last) || preg_match('/\A(?:[0-9]+|0x.*)\z/D', $last) === 1
                || array_filter($labels, static fn (string $label): bool => preg_match('/\A[a-z0-9_-]{1,63}\z/D', $label) !== 1) !== []) {
                throw ValidationException::withMessages(['hosts' => ["[{$host}] is not a host name or `*.` plus a host name."]]);
            }
            $out[] = $name;
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    public static function headers(array $headers): array
    {
        if (count($headers) > self::MaximumHeaders) {
            throw ValidationException::withMessages(['headers' => ['Give at most '.self::MaximumHeaders.' headers.']]);
        }
        $out = [];
        foreach ($headers as $header) {
            $lower = strtolower($header);
            if (preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}\z/D', $header) !== 1 || in_array($lower, self::RefusedHeaders, true) || str_starts_with($lower, 'proxy-')) {
                throw ValidationException::withMessages(['headers' => ["[{$header}] is not a header the proxy injects into."]]);
            }
            // The proxy matches names in canonical form, as Go's net/http writes them.
            $out[] = implode('-', array_map(static fn (string $part): string => ucfirst(strtolower($part)), explode('-', $header)));
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);

        return $out;
    }

    private static function placeholder(?string $prefix): string
    {
        $random = '';
        for ($i = 0; $i < 32; $i++) {
            $random .= self::Alphabet[random_int(0, strlen(self::Alphabet) - 1)];
        }

        return ($prefix ?? '').'orbit_secret_'.$random;
    }

    private function assertContainer(Instance $instance): void
    {
        if (! $instance->runtime()->exists()) {
            throw new ResourceOperationException('instance.secret_container_only', 'Secret bindings apply only to an Instance in a container: a checkout Instance has no egress proxy.', 409);
        }
    }
}
