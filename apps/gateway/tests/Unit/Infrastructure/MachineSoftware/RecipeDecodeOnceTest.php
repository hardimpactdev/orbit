<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\RecipeLoader;

/** The shipped recipe text, with `$change` applied to its decoded document when one is given. */
function shippedRecipeText(?Closure $change = null): string
{
    $text = (string) file_get_contents(dirname(__DIR__, 4).'/resources/machine-software/core.yaml');
    if ($change === null) {
        return $text;
    }
    $document = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    $change($document);

    return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('parses one recipe text once in a process and gives every reader the same value', function (): void {
    $text = shippedRecipeText();

    expect(new RecipeLoader()->decode($text))->toBe(new RecipeLoader()->decode($text))
        ->and(new RecipeLoader()->load())->toBe(new RecipeLoader()->load());
});

it('reads a changed recipe text as its own recipe', function (): void {
    // Whitespace gives this test its own cache entry, even after other tests have loaded the shipped bytes.
    $original = shippedRecipeText()."\n ";
    $shipped = new RecipeLoader()->decode($original);
    $changed = new RecipeLoader()->decode(shippedRecipeText(static function (array &$document): void {
        $document['git']['usn_since'] = '2001-02-03';
    }));

    expect($changed)->not->toBe($shipped)
        ->and($changed->json)->toContain('2001-02-03')
        ->and($shipped->json)->not->toContain('2001-02-03')
        ->and(new RecipeLoader()->decode($original))->toBe($shipped);
});

it('refuses a bad recipe text on every read', function (): void {
    $bad = shippedRecipeText(static function (array &$document): void {
        $document['schema_version'] = 2;
    });

    foreach ([1, 2] as $read) {
        expect(static fn (): mixed => new RecipeLoader()->decode($bad))
            ->toThrow(InvalidArgumentException::class, 'schema_version: expected integer 1.');
    }
});

it('reads the current recipe again after more texts than it keeps passed through', function (): void {
    $shipped = new RecipeLoader()->decode(shippedRecipeText());
    foreach (range(1, 12) as $day) {
        $date = sprintf('2001-02-%02d', $day);
        $recipe = new RecipeLoader()->decode(shippedRecipeText(static function (array &$document) use ($date): void {
            $document['git']['usn_since'] = $date;
        }));
        expect($recipe->json)->toContain($date);
    }

    $again = new RecipeLoader()->decode(shippedRecipeText());

    // The first value was dropped: the loader keeps a bounded number of texts.
    expect($again)->not->toBe($shipped)
        ->and($again->identity)->toBe($shipped->identity);
});
