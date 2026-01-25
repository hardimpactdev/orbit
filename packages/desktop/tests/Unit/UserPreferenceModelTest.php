<?php

use HardImpact\Orbit\Models\UserPreference;

test('preference can be created', function () {
    UserPreference::create([
        'key' => 'theme',
        'value' => ['mode' => 'dark'],
    ]);

    $this->assertDatabaseHas('user_preferences', [
        'key' => 'theme',
    ]);
});

test('get value returns preference', function () {
    UserPreference::create([
        'key' => 'theme',
        'value' => ['mode' => 'dark'],
    ]);

    $value = UserPreference::getValue('theme');

    expect($value)->toBe(['mode' => 'dark']);
});

test('get value returns default when not found', function () {
    $value = UserPreference::getValue('nonexistent', 'default');

    expect($value)->toBe('default');
});

test('set value creates preference', function () {
    UserPreference::setValue('theme', ['mode' => 'light']);

    $this->assertDatabaseHas('user_preferences', [
        'key' => 'theme',
    ]);

    expect(UserPreference::getValue('theme'))->toBe(['mode' => 'light']);
});

test('set value updates existing preference', function () {
    UserPreference::setValue('theme', ['mode' => 'dark']);
    UserPreference::setValue('theme', ['mode' => 'light']);

    $this->assertDatabaseCount('user_preferences', 1);

    expect(UserPreference::getValue('theme'))->toBe(['mode' => 'light']);
});

test('delete key removes preference', function () {
    UserPreference::setValue('theme', ['mode' => 'dark']);

    $result = UserPreference::deleteKey('theme');

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('user_preferences', ['key' => 'theme']);
});

test('delete key returns false when not found', function () {
    $result = UserPreference::deleteKey('nonexistent');

    expect($result)->toBeFalse();
});
