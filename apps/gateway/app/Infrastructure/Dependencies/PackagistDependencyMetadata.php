<?php

declare(strict_types=1);

namespace App\Infrastructure\Dependencies;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use Composer\Semver\VersionParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/** Public registry evidence, never a URL supplied by a repository. No persisted verdict. */
final readonly class PackagistDependencyMetadata
{
    public function __construct(private CommandDeadline $deadline) {}

    public function version(string $name, string $version): stdClass
    {
        $stream = null;
        try {
            $response = Http::withoutRedirecting()->timeout($this->deadline->cap(10))->connectTimeout(3)
                ->withOptions(['stream' => true])->get('https://repo.packagist.org/p2/'.$name.'.json');
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= 8 * 1024 * 1024) {
                $this->deadline->cap(10);
                $body .= $stream->read(min(65536, 8 * 1024 * 1024 - strlen($body) + 1));
            }
            if (! $response->successful() || strlen($body) > 8 * 1024 * 1024 || ! $stream->eof()) {
                throw new UnexpectedValueException;
            }
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $rows = $data instanceof stdClass && ($data->packages ?? null) instanceof stdClass ? ($data->packages->{$name} ?? null) : null;
            if (! is_array($rows) || ! array_is_list($rows) || ! in_array($data->minified ?? null, [null, 'composer/2.0'], true)) {
                throw new UnexpectedValueException;
            }
            $expanded = new stdClass;
            $wanted = (new VersionParser)->normalize($version);
            foreach ($rows as $row) {
                if (! $row instanceof stdClass) {
                    throw new UnexpectedValueException;
                }
                if (($data->minified ?? null) === null) {
                    $expanded = new stdClass;
                }
                foreach (get_object_vars($row) as $key => $value) {
                    if ($value === '__unset') {
                        unset($expanded->{$key});
                    } else {
                        $expanded->{$key} = $value;
                    }
                }
                if (is_string($expanded->version ?? null) && (new VersionParser)->normalize($expanded->version) === $wanted && ($expanded->name ?? null) === $name) {
                    return $expanded;
                }
            }
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (ConnectionException|JsonException|RuntimeException) {
            throw new ResourceOperationException('dependency_merge.packagist_unavailable', 'Fresh Packagist metadata is required.', 503);
        } finally {
            $stream?->close();
        }
        throw new ResourceOperationException('dependency_merge.contents_forbidden', 'The locked package version is absent from Packagist.', 409);
    }
}
