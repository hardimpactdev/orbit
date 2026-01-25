<?php

it('has www.conf disabling logic in Mac ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('www.conf.disabled');
    expect($sourceCode)->toContain('www.conf');
});

it('has www.conf disabling logic in Linux ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('www.conf.disabled');
    expect($sourceCode)->toContain('www.conf');
});

it('has socket path configuration in Mac ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('.sock');
});

it('has socket path configuration in Linux ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('.sock');
});

it('creates socket directory in Mac ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('makeDirectory');
    expect($sourceCode)->toContain('php');
});

it('creates socket directory in Linux ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('makeDirectory');
    expect($sourceCode)->toContain('php');
});

it('validates PHP-FPM configuration in Mac ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('validateConfiguration');
    expect($sourceCode)->toContain('php-fpm');
});

it('validates PHP-FPM configuration in Linux ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('validateConfiguration');
    expect($sourceCode)->toContain('php-fpm');
});

it('uses stub template for pool configuration in Mac ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Mac/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('php-fpm-pool.conf.stub');
    expect($sourceCode)->toContain('ORBIT_PHP_VERSION');
});

it('uses stub template for pool configuration in Linux ConfigurePhpFpm', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Linux/ConfigurePhpFpm.php');

    expect($sourceCode)->toContain('php-fpm-pool.conf.stub');
    expect($sourceCode)->toContain('ORBIT_PHP_VERSION');
});
