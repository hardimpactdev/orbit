<?php

declare(strict_types=1);

it('uses correct macOS certificate path in Mac TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/TrustRootCa.php');

    expect($sourceCode)->toContain('/Library/Application Support/Caddy/pki/authorities/local/root.crt');
});

it('uses correct Linux certificate path in Linux TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/TrustRootCa.php');

    expect($sourceCode)->toContain('/.local/share/caddy/pki/authorities/local/root.crt');
});

it('does not use docker exec in Mac TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/TrustRootCa.php');

    expect($sourceCode)->not->toContain('docker exec');
});

it('does not use docker exec in Linux TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/TrustRootCa.php');

    expect($sourceCode)->not->toContain('docker exec');
});

it('mentions authorization in Mac TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/TrustRootCa.php');

    // Mac uses keychain authorization, not sudo
    expect($sourceCode)->toContain('authorization required');
});

it('mentions sudo authorization in Linux TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/TrustRootCa.php');

    expect($sourceCode)->toContain('sudo authorization required');
});

it('skips trust when skipTrust is true in Mac TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/TrustRootCa.php');

    expect($sourceCode)->toContain('if ($context->skipTrust)');
    expect($sourceCode)->toContain('Certificate trust skipped');
});

it('skips trust when skipTrust is true in Linux TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/TrustRootCa.php');

    expect($sourceCode)->toContain('if ($context->skipTrust)');
    expect($sourceCode)->toContain('Certificate trust skipped');
});

it('returns success with warning when certificate not found in Mac TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/TrustRootCa.php');

    expect($sourceCode)->toContain('Caddy root certificate not found');
    expect($sourceCode)->toContain('StepResult::success()');
});

it('returns success with warning when certificate not found in Linux TrustRootCa', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/TrustRootCa.php');

    expect($sourceCode)->toContain('Caddy root certificate not found');
    expect($sourceCode)->toContain('StepResult::success()');
});
