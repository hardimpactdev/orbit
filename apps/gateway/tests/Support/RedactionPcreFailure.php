<?php

declare(strict_types=1);

namespace Tests\Support {

    use Closure;

    final class RedactionPcreFailure
    {
        public static bool $failNextBudget = false;

        public static function run(Closure $callback): mixed
        {
            $limit = ini_get('pcre.backtrack_limit');
            self::$failNextBudget = true;

            try {
                return $callback();
            } finally {
                self::$failNextBudget = false;
                ini_set('pcre.backtrack_limit', $limit);
            }
        }
    }
}

namespace App\Infrastructure\Activity {

    use Tests\Support\RedactionPcreFailure;

    /** Inject a real match-limit failure without changing the production budget. */
    function ini_set(string $option, string|int|float|bool|null $value): string|false
    {
        if ($option === 'pcre.backtrack_limit' && RedactionPcreFailure::$failNextBudget) {
            RedactionPcreFailure::$failNextBudget = false;

            return \ini_set($option, '0');
        }

        return \ini_set($option, $value);
    }
}
