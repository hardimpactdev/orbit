<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\RoleImage;
use RuntimeException;

/** Imports the host-built base image supplied to a task VM. */
final readonly class RecipeRoleImages
{
    public function __construct(private IncusHost $host) {}

    /**
     * @param  list<RoleImage>  $needed
     * @return array<string, string>
     */
    public function import(string $manifest, array $needed): array
    {
        $document = is_file($manifest) ? file_get_contents($manifest) : false;
        $data = $document === false ? null : json_decode($document, true);
        $identity = is_array($data) ? $data['recipe_identity'] ?? null : null;
        if (! is_string($identity) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $identity) !== 1 || ! is_array($data['images'] ?? null)) {
            throw new RuntimeException('The claimed recipe image manifest is missing or invalid.');
        }
        $image = $data['images']['base'] ?? null;
        $fingerprint = is_array($image) ? $image['fingerprint'] ?? null : null;
        if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1 || ($image['type'] ?? null) !== 'virtual-machine') {
            throw new RuntimeException('The claimed recipe base image is invalid.');
        }
        $found = $this->find($fingerprint);
        if ($found === null) {
            $archive = dirname($manifest).'/base.tar';
            if (! is_file($archive) || is_link($archive)) {
                throw new RuntimeException('The claimed recipe base image archive is missing.');
            }
            $this->host->importImage($archive);
            $found = $this->find($fingerprint);
        }
        if ($found === null || $found['type'] !== 'virtual-machine'
            || ($found['properties']['orbit.recipe'] ?? null) !== $identity
            || ($found['properties']['orbit.profile'] ?? null) !== 'base') {
            throw new RuntimeException('The imported base image does not match the claimed recipe, profile, fingerprint, or type.');
        }
        $archive = dirname($manifest).'/base.tar';
        if (is_file($archive) && ! is_link($archive) && ! unlink($archive)) {
            throw new RuntimeException('The imported recipe archive could not be removed.');
        }
        $this->host->pointAlias(RoleImage::Gateway->alias(), $fingerprint);
        $imported = array_fill_keys(array_map(static fn (RoleImage $role): string => $role->value, $needed), $fingerprint);

        return $imported;
    }

    /** @return array{fingerprint:string,type:string,aliases:list<string>,properties:array<string,string>,created_at:string}|null */
    private function find(string $fingerprint): ?array
    {
        foreach ($this->host->images() as $image) {
            if ($image['fingerprint'] === $fingerprint) {
                return $image;
            }
        }

        return null;
    }
}
