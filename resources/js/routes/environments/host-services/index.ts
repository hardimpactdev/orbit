import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/environments/{environment}/host-services/{service}/start'
*/
export const start = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

start.definition = {
    methods: ["post"],
    url: '/environments/{environment}/host-services/{service}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/environments/{environment}/host-services/{service}/start'
*/
start.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            service: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        service: args.service,
    }

    return start.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/environments/{environment}/host-services/{service}/start'
*/
start.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/environments/{environment}/host-services/{service}/stop'
*/
export const stop = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/environments/{environment}/host-services/{service}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/environments/{environment}/host-services/{service}/stop'
*/
stop.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            service: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        service: args.service,
    }

    return stop.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/environments/{environment}/host-services/{service}/stop'
*/
stop.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/environments/{environment}/host-services/{service}/restart'
*/
export const restart = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restart.url(args, options),
    method: 'post',
})

restart.definition = {
    methods: ["post"],
    url: '/environments/{environment}/host-services/{service}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/environments/{environment}/host-services/{service}/restart'
*/
restart.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            service: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        service: args.service,
    }

    return restart.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/environments/{environment}/host-services/{service}/restart'
*/
restart.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restart.url(args, options),
    method: 'post',
})

const hostServices = {
    start: Object.assign(start, start),
    stop: Object.assign(stop, stop),
    restart: Object.assign(restart, restart),
}

export default hostServices