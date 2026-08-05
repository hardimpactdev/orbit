<?php

declare(strict_types=1);

use App\Services\Nodes\ManagedConfigAgentReadAclAssessor;

it('accepts the exact agent read-only config ACL shape', function (): void {
    $assessor = new ManagedConfigAgentReadAclAssessor;

    expect($assessor->isManagedAgentReadOnly(
        "user::rw-\nuser:agent:r--\t#effective:r--\ngroup::---\nmask::r--\nother::---\n",
    ))->toBeTrue();
});

it('rejects a zeroed ACL mask that makes agent read ineffective', function (): void {
    $assessor = new ManagedConfigAgentReadAclAssessor;

    expect($assessor->isManagedAgentReadOnly(
        "user::rw-\nuser:agent:r--\t#effective:---\ngroup::---\nmask::---\nother::---\n",
    ))->toBeFalse();
});

it('rejects ordinary group-readable exposure without the agent named ACL', function (): void {
    $assessor = new ManagedConfigAgentReadAclAssessor;

    expect($assessor->isManagedAgentReadOnly(
        "user::rw-\ngroup::r--\nmask::r--\nother::---\n",
    ))->toBeFalse();
});

it('rejects other-readable exposure even when agent is named', function (): void {
    $assessor = new ManagedConfigAgentReadAclAssessor;

    expect($assessor->isManagedAgentReadOnly(
        "user::rw-\nuser:agent:r--\ngroup::---\nmask::r--\nother::r--\n",
    ))->toBeFalse();
});

it('rejects additional named user ACL entries on config', function (): void {
    $assessor = new ManagedConfigAgentReadAclAssessor;

    expect($assessor->isManagedAgentReadOnly(
        "user::rw-\nuser:agent:r--\nuser:other:r--\ngroup::---\nmask::r--\nother::---\n",
    ))->toBeFalse();
});

it('rejects agent write or execute bits on config', function (): void {
    $assessor = new ManagedConfigAgentReadAclAssessor;

    expect($assessor->isManagedAgentReadOnly(
        "user::rw-\nuser:agent:r-x\ngroup::---\nmask::r-x\nother::---\n",
    ))->toBeFalse();
});
