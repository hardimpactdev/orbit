<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubRepository;
use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use SensitiveParameter;
use Throwable;

/**
 * Remembers, for one repository, the installation of the Gateway GitHub App that covers it and the read tokens
 * minted for it, so a check that runs every minute does not ask GitHub for them each time
 * ([Automatic deployments](/reference/deployments#automatic-deployments)). A token lives one hour; the cache keeps it
 * {@see self::TokenSeconds} seconds. Tokens are stored encrypted with the application key. An entry of another App is
 * a miss. A read that a cached value made fail calls {@see self::forget()}, so the next read asks GitHub again.
 */
final readonly class GitHubTokenCache
{
    public const int TokenSeconds = 3000;

    public const int InstallationSeconds = 3600;

    /** The cache store is resolved when it is used, so building a reader never touches the cache. */
    public function __construct(private Factory $caches, private Encrypter $encrypter) {}

    /** @param Closure(): ?int $resolve */
    public function installation(int $appId, GitHubRepository $repository, Closure $resolve): ?int
    {
        $key = $this->key('installation', $repository);
        $cached = $this->cache()->get($key);

        if (is_array($cached) && ($cached['app'] ?? null) === $appId && is_int($cached['installation'] ?? null)) {
            return $cached['installation'];
        }

        $installation = $resolve();

        if ($installation !== null) {
            $this->cache()->put($key, ['app' => $appId, 'installation' => $installation], self::InstallationSeconds);
        }

        return $installation;
    }

    /** @param Closure(): string $mint */
    public function token(int $appId, int $installation, GitHubRepository $repository, string $permission, Closure $mint): string
    {
        $key = $this->key('token:'.$permission, $repository);
        $cached = $this->cache()->get($key);

        if (is_array($cached) && ($cached['app'] ?? null) === $appId && ($cached['installation'] ?? null) === $installation && is_string($cached['token'] ?? null)) {
            try {
                $token = $this->encrypter->decryptString($cached['token']);

                if ($token !== '') {
                    return $token;
                }
            } catch (Throwable) {
                // A value the current key cannot read is a miss.
            }
        }

        $token = $mint();
        $this->store($key, $appId, $installation, $token);

        return $token;
    }

    /** Drops the installation and every token of the repository. */
    public function forget(GitHubRepository $repository): void
    {
        $this->cache()->forget($this->key('installation', $repository));

        foreach (CachedGitHubApi::Permissions as $permission) {
            $this->cache()->forget($this->key('token:'.$permission, $repository));
        }
    }

    private function store(string $key, int $appId, int $installation, #[SensitiveParameter] string $token): void
    {
        $this->cache()->put($key, ['app' => $appId, 'installation' => $installation, 'token' => $this->encrypter->encryptString($token)], self::TokenSeconds);
    }

    private function key(string $kind, GitHubRepository $repository): string
    {
        return 'orbit:github:'.$kind.':'.strtolower($repository->owner.'/'.$repository->name);
    }

    private function cache(): Repository
    {
        return $this->caches->store();
    }
}
