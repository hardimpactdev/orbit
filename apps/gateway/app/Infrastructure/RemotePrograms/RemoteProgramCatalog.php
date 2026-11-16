<?php

declare(strict_types=1);

namespace App\Infrastructure\RemotePrograms;

use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramLanguage;
use LogicException;

/**
 * Loads the remote programs under `resources/remote`. It reads a program's header, puts the libraries it uses in
 * front of it, and checks the header against the file ([Remote programs](/reference/remote-programs#program-files)).
 */
final readonly class RemoteProgramCatalog
{
    private const string Segment = '[a-z0-9]+(?:-[a-z0-9]+)*';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? resource_path('remote');
    }

    public function program(string $name, ?string $user = null): RemoteProgram
    {
        if (preg_match('/\A('.self::Segment.')\.('.self::Segment.')\z/D', $name, $parts) !== 1 || $parts[1] === 'lib') {
            throw new LogicException("[{$name}] is not a remote program name.");
        }

        $candidates = array_values(array_filter(
            RemoteProgramLanguage::cases(),
            fn (RemoteProgramLanguage $language): bool => is_file("{$this->directory}/{$parts[1]}/{$parts[2]}.{$language->value}"),
        ));

        if (count($candidates) !== 1) {
            throw new LogicException("Remote program [{$name}] needs exactly one file.");
        }

        $language = $candidates[0];
        $program = $this->read("{$parts[1]}/{$parts[2]}.{$language->value}");
        $header = $this->header($program);

        if (($header['Program'] ?? null) !== $name) {
            throw new LogicException("The header of remote program [{$name}] names another program.");
        }

        $timeout = $header['Timeout'] ?? '';

        if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $timeout) !== 1) {
            throw new LogicException("Remote program [{$name}] needs a timeout in seconds.");
        }

        $source = '';

        foreach (preg_split('/\s+/', $header['Uses'] ?? '', flags: PREG_SPLIT_NO_EMPTY) ?: [] as $library) {
            if (preg_match('/\Alib\/'.self::Segment.'\.'.$language->value.'\z/D', $library) !== 1) {
                throw new LogicException("Remote program [{$name}] uses an unknown library [{$library}].");
            }

            $source .= $this->read($library);
        }

        return new RemoteProgram(
            name: $name,
            language: $language,
            source: $source.$program,
            outcomes: $this->outcomes($name, $language, $header),
            timeoutSeconds: (int) $timeout,
            user: $user,
        );
    }

    /** The bootstrap that starts a Python program from standard input. */
    public function bootstrap(): string
    {
        return $this->read('bootstrap.py');
    }

    private function read(string $path): string
    {
        $contents = is_file("{$this->directory}/{$path}") ? file_get_contents("{$this->directory}/{$path}") : false;

        if (! is_string($contents) || $contents === '') {
            throw new LogicException("Remote program file [{$path}] is missing.");
        }

        return str_ends_with($contents, "\n") ? $contents : $contents."\n";
    }

    /**
     * The `# Key: value` lines of the comment block that opens the file.
     *
     * @return array<string, string>
     */
    private function header(string $program): array
    {
        $header = [];

        foreach (explode("\n", $program) as $line) {
            if (! str_starts_with($line, '#')) {
                break;
            }

            if (preg_match('/\A# ([A-Z][a-z]+): (.+)\z/D', $line, $field) === 1) {
                $header[$field[1]] ??= trim($field[2]);
            }
        }

        return $header;
    }

    /**
     * @param  array<string, string>  $header
     * @return list<string>
     */
    private function outcomes(string $name, RemoteProgramLanguage $language, array $header): array
    {
        if ($language === RemoteProgramLanguage::Bash) {
            if (isset($header['Outcomes'])) {
                throw new LogicException("Bash program [{$name}] has the fixed outcomes succeeded and failed.");
            }

            return ['succeeded', 'failed'];
        }

        $outcomes = preg_split('/\s+/', $header['Outcomes'] ?? '', flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (
            $outcomes === []
            || count(array_unique($outcomes)) !== count($outcomes)
            || array_filter($outcomes, fn (string $outcome): bool => preg_match('/\A[a-z][a-z0-9_]*\z/D', $outcome) !== 1) !== []
        ) {
            throw new LogicException("Remote program [{$name}] needs a list of distinct outcomes.");
        }

        return $outcomes;
    }
}
