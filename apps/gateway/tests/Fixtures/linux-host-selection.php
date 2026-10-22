<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/LinuxHostCopyFixtures.php';

it('selects its delegated case', function (): void {
    linux_host_assert_delegated_selection($this, '__pest_evaluable_it_selects_its_delegated_case');
});

// The ordinary filter also matches this description, so dropping the XML exclusion broadens its selection.
it('selects its delegated case when privileged', function (): void {
    linux_host_assert_delegated_selection($this, '__pest_evaluable_it_selects_its_delegated_case_when_privileged');
})->group('privileged');
