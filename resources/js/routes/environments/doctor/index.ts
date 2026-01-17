import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::quick
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
export const quick = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: quick.url(args, options),
    method: 'get',
})

quick.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/doctor/quick',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::quick
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
quick.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return quick.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::quick
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
quick.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: quick.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::quick
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
quick.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: quick.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::fix
* @see app/Http/Controllers/EnvironmentController.php:1573
* @route '/environments/{environment}/doctor/fix/{check}'
*/
export const fix = (args: { environment: number | { id: number }, check: string | number } | [environment: number | { id: number }, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fix.url(args, options),
    method: 'post',
})

fix.definition = {
    methods: ["post"],
    url: '/environments/{environment}/doctor/fix/{check}',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::fix
* @see app/Http/Controllers/EnvironmentController.php:1573
* @route '/environments/{environment}/doctor/fix/{check}'
*/
fix.url = (args: { environment: number | { id: number }, check: string | number } | [environment: number | { id: number }, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        check: args.check,
    }

    return fix.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::fix
* @see app/Http/Controllers/EnvironmentController.php:1573
* @route '/environments/{environment}/doctor/fix/{check}'
*/
fix.post = (args: { environment: number | { id: number }, check: string | number } | [environment: number | { id: number }, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fix.url(args, options),
    method: 'post',
})

const doctor = {
    quick: Object.assign(quick, quick),
    fix: Object.assign(fix, fix),
}

export default doctor