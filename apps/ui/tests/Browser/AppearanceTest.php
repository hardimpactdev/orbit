<?php

declare(strict_types=1);

it('renders light, dark, and system modes without javascript errors', function () {
    $page = visit('/');

    $page->assertSee('Launch your next idea')
        ->assertNoJavaScriptErrors()
        ->click('[aria-label^="Theme"]')
        ->assertNoJavaScriptErrors()
        ->click('text="Light"')
        ->assertNoJavaScriptErrors()
        ->click('[aria-label^="Theme"]')
        ->assertNoJavaScriptErrors()
        ->click('text="Dark"')
        ->assertNoJavaScriptErrors()
        ->click('[aria-label^="Theme"]')
        ->assertNoJavaScriptErrors()
        ->click('text="System"')
        ->assertNoJavaScriptErrors();
});

it('hydrates and renders without javascript errors when the OS prefers dark mode', function () {
    $page = visit('/')->inDarkMode();

    $page->assertSee('Launch your next idea')
        ->assertNoJavaScriptErrors();
});

it('hydrates and renders without javascript errors on mobile in dark mode', function () {
    $page = visit('/')->on()->mobile()->inDarkMode();

    $page->assertSee('Launch your next idea')
        ->assertNoJavaScriptErrors();
});

it('allows interaction across stack layers and cta prompt without javascript errors', function () {
    $page = visit('/');

    $page->assertSee('The stack')
        ->assertNoJavaScriptErrors()
        ->click('[role="tab"][aria-label="React"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('The UI runtime')
        ->click('[role="tab"][aria-label="shadcn"]')
        ->assertNoJavaScriptErrors()
        ->assertScript('(() => {
            const label = document.querySelector("#stack-tab-6 .mono-label");
            return label ? window.getComputedStyle(label).color : null;
        })()', 'rgb(255, 255, 255)')
        ->click('[aria-label^="Copy prompt"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Copied instructions to clipboard');
});

it('supports keyboard arrow navigation on stack tabs and space activation on cta prompt', function () {
    $page = visit('/');

    $page->assertSee('The stack')
        ->assertNoJavaScriptErrors()
        ->keys('#stack-tab-0', 'ArrowUp')
        ->assertNoJavaScriptErrors()
        ->assertSee('The glue between Laravel and React')
        ->keys('#stack-tab-1', 'ArrowUp')
        ->assertNoJavaScriptErrors()
        ->assertSee('The UI runtime')
        ->keys('button[aria-label^="Copy prompt"]', 'Space')
        ->assertNoJavaScriptErrors()
        ->assertSee('Copied instructions to clipboard');
});

it('exposes valid desktop ARIA tablist, tab, and tabpanel relationships with active-only visibility', function () {
    $page = visit('/');

    $page->assertSee('The stack')
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll(\'[role="tablist"]\').length', 1)
        ->assertScript('document.querySelectorAll(\'[role="tab"]\').length', 7)
        ->assertScript('document.querySelectorAll(\'[role="tabpanel"]\').length', 7)
        ->assertScript('(() => {
            const tabs = Array.from(document.querySelectorAll(\'[role="tab"]\'));
            return tabs.every((tab) => {
                const tabId = tab.id;
                const panelId = tab.getAttribute("aria-controls");
                const panel = document.getElementById(panelId);
                return tabId && panelId && panel && panel.getAttribute("aria-labelledby") === tabId && panel.getAttribute("role") === "tabpanel";
            });
        })()', true)
        ->assertScript('(() => {
            const activeTabs = document.querySelectorAll(\'[role="tab"][aria-selected="true"]\');
            const rovingTabs = document.querySelectorAll(\'[role="tab"][tabindex="0"]\');
            const visiblePanels = Array.from(document.querySelectorAll(\'[role="tabpanel"]\'))
                .filter(p => p.getAttribute("aria-hidden") === "false");
            return activeTabs.length === 1 && rovingTabs.length === 1 && visiblePanels.length === 1;
        })()', true)
        ->keys('#stack-tab-0', 'ArrowUp')
        ->assertNoJavaScriptErrors()
        ->assertScript('(() => {
            const tab1 = document.getElementById("stack-tab-1");
            const panel1 = document.getElementById("stack-panel-1");
            const panel0 = document.getElementById("stack-panel-0");
            return tab1.getAttribute("aria-selected") === "true"
                && tab1.getAttribute("tabindex") === "0"
                && panel1.getAttribute("aria-hidden") === "false"
                && panel0.getAttribute("aria-hidden") === "true";
        })()', true);
});

it('removes orphan tabpanels and dangling aria references on mobile', function () {
    $page = visit('/')->on()->mobile();

    $page->assertSee('The stack')
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll(\'[role="tablist"]\').length', 0)
        ->assertScript('document.querySelectorAll(\'[role="tab"]\').length', 0)
        ->assertScript('document.querySelectorAll(\'[role="tabpanel"]\').length', 0)
        ->assertScript('document.querySelectorAll(\'[aria-labelledby^="stack-tab-"]\').length', 0)
        ->assertScript('document.querySelectorAll(\'[id^="stack-panel-"]\').length', 0)
        ->assertScript('(() => {
            const panels = Array.from(document.querySelectorAll(\'#stack div[aria-hidden]\'));
            const active = panels.filter(p => p.getAttribute("aria-hidden") === "false");
            const hidden = panels.filter(p => p.getAttribute("aria-hidden") === "true");
            return active.length === 1 && hidden.length === 6;
        })()', true);
});
