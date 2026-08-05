<?php

declare(strict_types=1);

use App\Services\Nodes\NodeHostPaths;

it('uses /var/root for macOS root and /Users for other macOS users', function (): void {
    expect(NodeHostPaths::homeDirectoryFor('macos_14', 'root'))
        ->toBe('/var/root')
        ->and(NodeHostPaths::homeDirectoryFor('darwin_arm64', 'orbit'))
        ->toBe('/Users/orbit');
});

it('uses /root for Linux root and /home for other Linux users', function (): void {
    expect(NodeHostPaths::homeDirectoryFor('ubuntu_24-04', 'root'))
        ->toBe('/root')
        ->and(NodeHostPaths::homeDirectoryFor('ubuntu_24-04', 'orbit'))
        ->toBe('/home/orbit');
});
