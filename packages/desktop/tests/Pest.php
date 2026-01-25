<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class Pest "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Browser Testing
|--------------------------------------------------------------------------
|
| Browser tests use Playwright to automate browser interactions. These tests
| run against the actual frontend and can test JavaScript-driven functionality.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeSuccessful', function () {
    return $this->toHaveKey('success', true);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createEnvironment(array $attributes = []): \HardImpact\Orbit\Models\Environment
{
    return \HardImpact\Orbit\Models\Environment::create(array_merge([
        'name' => 'Test Environment',
        'host' => 'ai',
        'user' => 'launchpad',
        'port' => 22,
        'is_local' => false,
        'is_default' => true,
        'status' => 'active',
    ], $attributes));
}
