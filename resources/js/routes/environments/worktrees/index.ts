import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::unlink
* @see app/Http/Controllers/EnvironmentController.php:829
* @route '/environments/{environment}/worktrees/unlink'
*/
export const unlink = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unlink.url(args, options),
    method: 'post',
})

unlink.definition = {
    methods: ["post"],
    url: '/environments/{environment}/worktrees/unlink',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::unlink
* @see app/Http/Controllers/EnvironmentController.php:829
* @route '/environments/{environment}/worktrees/unlink'
*/
unlink.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { environment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { environment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            environment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
    }

    return unlink.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::unlink
* @see app/Http/Controllers/EnvironmentController.php:829
* @route '/environments/{environment}/worktrees/unlink'
*/
unlink.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unlink.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::refresh
* @see app/Http/Controllers/EnvironmentController.php:848
* @route '/environments/{environment}/worktrees/refresh'
*/
export const refresh = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

refresh.definition = {
    methods: ["post"],
    url: '/environments/{environment}/worktrees/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::refresh
* @see app/Http/Controllers/EnvironmentController.php:848
* @route '/environments/{environment}/worktrees/refresh'
*/
refresh.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { environment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { environment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            environment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
    }

    return refresh.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::refresh
* @see app/Http/Controllers/EnvironmentController.php:848
* @route '/environments/{environment}/worktrees/refresh'
*/
refresh.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

const worktrees = {
    unlink: Object.assign(unlink, unlink),
    refresh: Object.assign(refresh, refresh),
}

export default worktrees