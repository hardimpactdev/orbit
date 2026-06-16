<?php

declare(strict_types=1);

it('keeps CLI enums under App\\Enums and exceptions under App\\Exceptions', function (): void {
    $expectedEnums = [
        'App\\Enums\\Trust\\TrustStoreInstallReason',
    ];

    $expectedExceptions = [
        'App\\Exceptions\\NodeWriteInputException',
    ];

    foreach ($expectedEnums as $class) {
        expect(class_exists($class))->toBeTrue("Expected {$class} to exist.");
    }

    foreach ($expectedExceptions as $class) {
        expect(class_exists($class))->toBeTrue("Expected {$class} to exist.");

        if (class_exists($class)) {
            expect((new ReflectionClass($class))->isFinal())->toBeTrue("Expected {$class} to be final.");
        }
    }

    foreach (cliLegacyServiceNamespaceClasses() as $class) {
        expect(class_exists($class))->toBeFalse("Legacy service-namespace class {$class} should not exist.");
    }
});

/**
 * @return list<string>
 */
function cliLegacyServiceNamespaceClasses(): array
{
    return [
        'App\\Services\\Node\\NodeWriteInputException',
        'App\\Services\\Trust\\TrustStoreInstallReason',
    ];
}
