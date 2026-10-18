<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use SensitiveParameter;

/**
 * One user of a Valkey Database server as `ACL LIST` reports it. `rules` is the line without `user <name>` and without
 * the password hashes, with single spaces, such as `on ~orbit-gateway:* resetchannels -@all
 * +@connection -@admin -@dangerous -function -script`. Two users compare by their rule tokens: flags, key patterns,
 * and channel rules in any order, command rules in order, because only their order changes what Valkey allows.
 * Payload sanitization flags are Valkey metadata: Orbit never sets them and does not compare them.
 */
final readonly class ValkeyAclUser
{
    /** The admin user. It always has the Gateway's admin password, so no connection gets an ACL user of this name. */
    public const string ADMIN = 'default';

    /** The rules of `default` as `ACL LIST` reports them: every key, channel, and command. */
    public const string ADMIN_RULES = 'on ~* &* +@all';

    /** @param list<string> $passwordHashes */
    public function __construct(
        public string $name,
        public string $rules,
        public array $passwordHashes,
    ) {}

    /** Parse one `ACL LIST` line, `user <name> <rule> ...`. Another line is null. */
    public static function fromAclListLine(string $line): ?self
    {
        $tokens = preg_split('/ +/', trim($line), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) < 2 || $tokens[0] !== 'user') {
            return null;
        }

        $rules = [];
        $hashes = [];

        foreach (array_slice($tokens, 2) as $token) {
            if (preg_match('/\A#([0-9a-f]{64})\z/D', $token, $hash) === 1) {
                $hashes[] = $hash[1];

                continue;
            }

            $rules[] = $token;
        }

        return new self($tokens[1], implode(' ', $rules), $hashes);
    }

    /** The user that one password logs in as, with these rules. */
    public static function withPassword(string $name, string $rules, #[SensitiveParameter] string $password): self
    {
        return new self($name, $rules, [self::passwordHash($password)]);
    }

    /** The form in which a password reaches Valkey and its ACL file: the lowercase SHA-256 hex digest. */
    public static function passwordHash(#[SensitiveParameter] string $password): string
    {
        return hash('sha256', $password);
    }

    /** Whether the user has exactly one password, and it is this one. */
    public function hasOnlyPassword(#[SensitiveParameter] string $password): bool
    {
        return $this->passwordHashes === [self::passwordHash($password)];
    }

    /** Whether the other user has this name, these password hashes, and the same rule tokens. */
    public function sameAs(self $other): bool
    {
        $hashes = $this->passwordHashes;
        $otherHashes = $other->passwordHashes;
        sort($hashes);
        sort($otherHashes);

        return $this->name === $other->name
            && $hashes === $otherHashes
            && $this->ruleTokens() === $other->ruleTokens();
    }

    /**
     * The rule tokens by kind. Flags, key patterns, and channel rules are sorted; command rules keep their order.
     *
     * @return array{flags: list<string>, keys: list<string>, channels: list<string>, commands: list<string>}
     */
    private function ruleTokens(): array
    {
        $tokens = ['flags' => [], 'keys' => [], 'channels' => [], 'commands' => []];

        foreach (explode(' ', $this->rules) as $token) {
            $kind = match (true) {
                in_array($token, ['', 'sanitize-payload'], true) => null,
                str_starts_with($token, '~'), str_starts_with($token, '%'), in_array($token, ['allkeys', 'resetkeys'], true) => 'keys',
                str_starts_with($token, '&'), in_array($token, ['allchannels', 'resetchannels'], true) => 'channels',
                str_starts_with($token, '+'), str_starts_with($token, '-'), in_array($token, ['allcommands', 'nocommands'], true) => 'commands',
                default => 'flags',
            };

            if ($kind !== null) {
                $tokens[$kind][] = $token;
            }
        }

        sort($tokens['flags']);
        sort($tokens['keys']);
        sort($tokens['channels']);

        return $tokens;
    }
}
