import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\ProvisioningController::create
* @see app/Http/Controllers/ProvisioningController.php:14
* @route '/provision'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/provision',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ProvisioningController::create
* @see app/Http/Controllers/ProvisioningController.php:14
* @route '/provision'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProvisioningController::create
* @see app/Http/Controllers/ProvisioningController.php:14
* @route '/provision'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ProvisioningController::create
* @see app/Http/Controllers/ProvisioningController.php:14
* @route '/provision'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\ProvisioningController::store
* @see app/Http/Controllers/ProvisioningController.php:25
* @route '/provision'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/provision',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProvisioningController::store
* @see app/Http/Controllers/ProvisioningController.php:25
* @route '/provision'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProvisioningController::store
* @see app/Http/Controllers/ProvisioningController.php:25
* @route '/provision'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProvisioningController::checkServer
* @see app/Http/Controllers/ProvisioningController.php:224
* @route '/provision/check-server'
*/
export const checkServer = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkServer.url(options),
    method: 'post',
})

checkServer.definition = {
    methods: ["post"],
    url: '/provision/check-server',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProvisioningController::checkServer
* @see app/Http/Controllers/ProvisioningController.php:224
* @route '/provision/check-server'
*/
checkServer.url = (options?: RouteQueryOptions) => {
    return checkServer.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProvisioningController::checkServer
* @see app/Http/Controllers/ProvisioningController.php:224
* @route '/provision/check-server'
*/
checkServer.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkServer.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProvisioningController::run
* @see app/Http/Controllers/ProvisioningController.php:53
* @route '/provision/{environment}/run'
*/
export const run = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: run.url(args, options),
    method: 'post',
})

run.definition = {
    methods: ["post"],
    url: '/provision/{environment}/run',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProvisioningController::run
* @see app/Http/Controllers/ProvisioningController.php:53
* @route '/provision/{environment}/run'
*/
run.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return run.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProvisioningController::run
* @see app/Http/Controllers/ProvisioningController.php:53
* @route '/provision/{environment}/run'
*/
run.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: run.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProvisioningController::status
* @see app/Http/Controllers/ProvisioningController.php:128
* @route '/provision/{environment}/status'
*/
export const status = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/provision/{environment}/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ProvisioningController::status
* @see app/Http/Controllers/ProvisioningController.php:128
* @route '/provision/{environment}/status'
*/
status.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return status.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProvisioningController::status
* @see app/Http/Controllers/ProvisioningController.php:128
* @route '/provision/{environment}/status'
*/
status.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ProvisioningController::status
* @see app/Http/Controllers/ProvisioningController.php:128
* @route '/provision/{environment}/status'
*/
status.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(args, options),
    method: 'head',
})

const provision = {
    create: Object.assign(create, create),
    store: Object.assign(store, store),
    checkServer: Object.assign(checkServer, checkServer),
    run: Object.assign(run, run),
    status: Object.assign(status, status),
}

export default provision