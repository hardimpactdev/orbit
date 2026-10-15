<?php

declare(strict_types=1);
use PHPacker\PHPacker\Command\Build;
use PHPacker\PHPacker\Command\Download;
use PHPacker\PHPacker\Contracts\RemoteRepositoryService;
use PHPacker\PHPacker\Exceptions\RepositoryRequestException;
use PHPacker\PHPacker\Support\Config\ConfigManager;
use PHPacker\PHPacker\Support\GitHub;
use Symfony\Component\Console\Application;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Download the public PHPacker archive without sending a credential through redirects. */
function orbit_phpacker_archive(string $repository, string $tag, string $destination, #[SensitiveParameter] string $token): string
{
    if ($token === '' || strpbrk($token, "\r\n") !== false) {
        throw new RuntimeException('PHPacker downloads require GITHUB_TOKEN or GH_TOKEN.');
    }
    if (preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~D', $repository) !== 1 || $tag === '') {
        throw new RuntimeException('The PHPacker release identity is invalid.');
    }
    $context = stream_context_create(['http' => [
        'header' => ['User-Agent: Orbit-PHPacker', 'Authorization: Bearer '.$token],
        'follow_location' => 0,
        'timeout' => 120,
    ]]);
    $remote = @fopen('https://codeload.github.com/'.$repository.'/legacy.zip/'.rawurlencode($tag), 'rb', false, $context);
    if ($remote === false) {
        throw new RuntimeException('The authenticated PHPacker archive download failed.');
    }
    $path = $destination.'/latest.zip';
    try {
        $local = fopen($path, 'wb');
        if ($local === false) {
            throw new RuntimeException('The PHPacker archive cache could not be opened.');
        }
        try {
            if (stream_copy_to_stream($remote, $local) === false || ! feof($remote)) {
                throw new RuntimeException('The PHPacker archive download did not finish.');
            }
        } finally {
            fclose($local);
        }
    } catch (Throwable $exception) {
        @unlink($path);
        throw $exception;
    } finally {
        fclose($remote);
    }

    return $path;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

require __DIR__.'/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
$dispatcher = new EventDispatcher;
ConfigManager::bootstrap($dispatcher);
$application = new Application('phpacker');
$application->add(new Build);
$application->add(new class('download') extends Download
{
    protected function repository(): RemoteRepositoryService
    {
        return once(fn () => new class(ConfigManager::get('repository')) extends GitHub
        {
            public function releaseData(): ?array
            {
                if (empty($_ENV['GITHUB_TOKEN'])) {
                    throw new RepositoryRequestException('PHPacker downloads require GITHUB_TOKEN or GH_TOKEN.');
                }

                return parent::releaseData();
            }

            public function downloadReleaseAssets(string $destination): string
            {
                $tag = $this->releaseData()['tag_name'] ?? null;
                if (! is_string($tag)) {
                    throw new RepositoryRequestException('The PHPacker release has no tag.');
                }

                return orbit_phpacker_archive($this->repository, $tag, $destination, $_ENV['GITHUB_TOKEN']);
            }
        });
    }
});
$application->setDispatcher($dispatcher);
exit($application->run());
