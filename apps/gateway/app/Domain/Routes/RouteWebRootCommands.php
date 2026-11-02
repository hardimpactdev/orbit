<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Instance;
use App\Support\ValidatedData;

/** Caddy access to served roots and the selected release's .env links, as one command or link per row. */
final readonly class RouteWebRootCommands
{
    /**
     * The grant as ordered commands: deny Caddy each checkout, open passage through every directory above each served
     * root, then grant read on the root and, for a Laravel public root, on storage/app/public behind its link.
     *
     * @param  array<string,mixed>  $access
     * @return list<array{node_id:int,argv:list<string>}>
     */
    public static function access(int $nodeId, array $access, Instance $instance): array
    {
        $rows = [];
        $as = ['/usr/sbin/runuser', '-u', ValidatedData::string($access['user']), '--'];
        $sites = RouteExecutionProof::objects($access['sites']);
        foreach (array_values(array_unique(array_map(static fn (array $site): string => rtrim(ValidatedData::string($site['checkout']), '/'), $sites))) as $checkout) {
            $rows[] = ['node_id' => $nodeId, 'argv' => [...$as, '/usr/bin/find', '-P', $checkout, '-type', 'd', '-exec', '/usr/bin/setfacl', '-m', 'd:u:caddy:---', '--', '{}', '+']];
            $rows[] = ['node_id' => $nodeId, 'argv' => [...$as, '/usr/bin/setfacl', '-P', '-R', '-n', '-m', 'u:caddy:---', '--', $checkout]];
        }
        foreach ($sites as $site) {
            $root = rtrim(ValidatedData::string($site['checkout']), '/').'/'.trim(ValidatedData::string($site['relative_root']), '/');
            $served = [$root];
            if ($instance->source_is_laravel && str_ends_with($root, '/public')) {
                $served[] = rtrim(ValidatedData::string($site['application']), '/').'/storage/app/public';
            }
            $passage = [];
            foreach ($served as $path) {
                for ($directory = dirname($path); $directory !== '/' && $directory !== '.'; $directory = dirname($directory)) {
                    $passage[$directory] = true;
                }
            }
            foreach (array_keys($passage) as $directory) {
                $rows[] = ['node_id' => $nodeId, 'argv' => ['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', $directory]];
            }
            foreach ($served as $path) {
                $rows[] = ['node_id' => $nodeId, 'argv' => [...$as, '/usr/bin/find', '-P', $path, '-type', 'd', '-exec', '/usr/bin/setfacl', '-m', 'd:u:caddy:r-x', '--', '{}', '+']];
                $rows[] = ['node_id' => $nodeId, 'argv' => [...$as, '/usr/bin/setfacl', '-P', '-R', '-n', '-m', 'u:caddy:r-X', '--', $path]];
            }
        }

        return $rows;
    }

    /**
     * Production served roots in the selected release (the Instance's checkout path after a deploy): each served
     * directory's .env is a link into the shared env tree. Returns the links and the access the roots need.
     *
     * @param  array<string,mixed>  $served
     * @return array{links:list<array{node_id:int,path:string,owner:string,target:string}>,access:array{user:string,sites:list<array<string,string>>}}
     */
    public static function servedRoots(int $nodeId, array $served, Instance $instance): array
    {
        $release = rtrim($instance->checkout_path, '/');
        $user = ValidatedData::string($served['user']);
        $links = [];
        $sites = [['checkout' => $release, 'relative_root' => ValidatedData::string($served['default_web_root']),
            'application' => rtrim($release.'/'.ValidatedData::string($served['default_directory']), '/')]];
        foreach (RouteExecutionProof::objects($served['served']) as $entry) {
            $directory = ValidatedData::string($entry['directory']);
            $links[] = ['node_id' => $nodeId, 'path' => rtrim($release.'/'.$directory, '/').'/.env', 'owner' => $user,
                'target' => str_repeat('../', 2 + count(array_filter(explode('/', $directory)))).'env/'.($directory === '' ? '' : $directory.'/').'.env'];
            $sites[] = ['checkout' => $release, 'relative_root' => ValidatedData::string($entry['web_root']), 'application' => rtrim($release.'/'.$directory, '/')];
        }

        return ['links' => $links, 'access' => ['user' => $user, 'sites' => $sites]];
    }

    /**
     * One workload phase as commands and links.
     *
     * @param  array<string,mixed>  $phase
     * @return array{acl:list<array{node_id:int,argv:list<string>}>,links:list<array{node_id:int,path:string,owner:string,target:string}>}
     */
    public static function phase(int $nodeId, array $phase, Instance $instance): array
    {
        $links = [];
        $access = is_array($phase['source_access'] ?? null) ? $phase['source_access'] : null;
        if (is_array($phase['served_roots'] ?? null)) {
            ['links' => $links, 'access' => $access] = self::servedRoots($nodeId, ValidatedData::object($phase['served_roots']), $instance);
        }

        return ['acl' => $access === null ? [] : self::access($nodeId, ValidatedData::object($access), $instance), 'links' => $links];
    }
}
