#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\Certificates\OrbitRootCa;
use Symfony\Component\Process\Process;

// Disposable topology setup only, until native Gateway bootstrap owns fresh root creation.
require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';

umask(0077);
$directory = '/home/orbit/.orbit/ca';
$candidate = $directory.'/.topology-candidate';
$private = $directory.'/root.key';
$certificate = $directory.'/root.pem';

try {
    if ($argc !== 1 || is_link($directory) || is_link($private) || is_link($certificate)) {
        throw new RuntimeException;
    }
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException;
    }
    chmod($directory, 0700);
    if (file_exists($private) || file_exists($certificate)) {
        if (! is_file($private) || ! is_file($certificate)
            || ! @OrbitRootCa::pairIsValid(file_get_contents($certificate), file_get_contents($private), time())) {
            throw new RuntimeException;
        }
    } else {
        if (! mkdir($candidate, 0700)) {
            throw new RuntimeException;
        }
        try {
            foreach ([
                ['openssl', 'genrsa', '-out', $candidate.'/root.key', '4096'],
                ['openssl', 'req', '-x509', '-new', '-key', $candidate.'/root.key', '-out', $candidate.'/root.pem',
                    '-days', '3650', '-subj', '/CN=Orbit Root CA',
                    '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign'],
            ] as $argv) {
                $process = new Process($argv, timeout: 60);
                if ($process->run() !== 0) {
                    throw new RuntimeException;
                }
            }
            if (! @OrbitRootCa::pairIsValid(file_get_contents($candidate.'/root.pem'), file_get_contents($candidate.'/root.key'), time())) {
                throw new RuntimeException;
            }
            if (! rename($candidate.'/root.key', $private)) {
                throw new RuntimeException;
            }
            if (! rename($candidate.'/root.pem', $certificate)) {
                rename($private, $candidate.'/root.key');
                throw new RuntimeException;
            }
        } finally {
            foreach (['root.key', 'root.pem'] as $name) {
                if (is_file($candidate.'/'.$name)) {
                    unlink($candidate.'/'.$name);
                }
            }
            rmdir($candidate);
        }
    }
    chmod($private, 0600);
    chmod($certificate, 0644);
    echo json_encode(['topology_ca' => 'fixture-set'], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "e2e.topology_ca_invalid\n");
    exit(1);
}
