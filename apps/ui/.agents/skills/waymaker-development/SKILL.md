---
name: waymaker-development
description: Use when adding, changing, debugging, testing, or consuming Laravel controller routes defined with Waymaker attributes, generated routes/waymaker.php, or related Wayfinder action functions.
license: MIT
---

# Waymaker Development

## Overview

Waymaker turns controller attributes into Laravel routes. Controllers are the
source of truth; `routes/waymaker.php` is generated output. Wayfinder then turns
the loaded Laravel routes into typed frontend functions.

**REQUIRED SUB-SKILL:** Use `laravel-best-practices` for controller code,
`wayfinder-development` when frontend code consumes the route, and
`pest-testing` when adding or changing tests.

## Workflow

1. Inspect neighboring controllers, `routes/web.php`, `vite.config.ts`, and the
   installed Waymaker source. Verify fast-moving syntax with
   `composer show nckrtl/waymaker` and Artisan command help.
2. Add an explicit HTTP attribute to every public controller action that should
   be routable: `Get`, `Post`, `Put`, `Patch`, or `Delete`.
3. Express route details on the attribute with `uri`, `name`, `parameters`, and
   `middleware`. Use `routePrefix` and `routeMiddleware` for shared
   controller-level configuration.
4. Generate and inspect the route:

   ```bash
   php artisan waymaker:generate --no-interaction
   php artisan route:list --except-vendor
   ```

5. If the Vite development runner is not active, regenerate typed frontend
   actions with `php artisan wayfinder:generate --with-form --no-interaction`.
6. Use imports from `@/actions` or `@/routes` in React; never duplicate the URL.
7. Add focused route, response, authorization, and browser coverage appropriate
   to the changed outcome.

## Example

```php
use NckRtl\Waymaker\Get;
use NckRtl\Waymaker\Patch;

class TeamController extends Controller
{
    protected static string $routePrefix = 'teams';

    protected static array $routeMiddleware = ['auth'];

    #[Get]
    public function index(): Response
    {
        return inertia('Teams/Index');
    }

    #[Patch(parameters: ['team:slug'])]
    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $team->update($request->validated());

        return back();
    }
}
```

## Quick Reference

| Concern | Source of truth |
| --- | --- |
| HTTP method and URI | Controller route attribute |
| Shared prefix or middleware | Static controller properties |
| Loaded Laravel routes | `routes/web.php` calling `Waymaker::routes()` |
| Generated PHP routes | `routes/waymaker.php` |
| Frontend route functions | Wayfinder-generated `resources/js/actions` and `routes` |

## Common Mistakes

- Editing `routes/waymaker.php` instead of the controller.
- Omitting the explicit route attribute and wondering why no route appears.
- Adding both `uri` and `parameters` without checking the generated result.
- Leaving stale Wayfinder output after changing a route signature.
- Hardcoding a React URL that already has a generated function.
- Working around a duplicate-route exception instead of resolving the collision.
