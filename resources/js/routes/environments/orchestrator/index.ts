import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::enable
* @see app/Http/Controllers/EnvironmentController.php:328
* @route '/environments/{environment}/orchestrator/enable'
*/
export const enable = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enable.url(args, options),
    method: 'post',
})

enable.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::enable
* @see app/Http/Controllers/EnvironmentController.php:328
* @route '/environments/{environment}/orchestrator/enable'
*/
enable.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return enable.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::enable
* @see app/Http/Controllers/EnvironmentController.php:328
* @route '/environments/{environment}/orchestrator/enable'
*/
enable.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enable.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::disable
* @see app/Http/Controllers/EnvironmentController.php:348
* @route '/environments/{environment}/orchestrator/disable'
*/
export const disable = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: disable.url(args, options),
    method: 'post',
})

disable.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/disable',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::disable
* @see app/Http/Controllers/EnvironmentController.php:348
* @route '/environments/{environment}/orchestrator/disable'
*/
disable.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return disable.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::disable
* @see app/Http/Controllers/EnvironmentController.php:348
* @route '/environments/{environment}/orchestrator/disable'
*/
disable.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: disable.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::install
* @see app/Http/Controllers/EnvironmentController.php:364
* @route '/environments/{environment}/orchestrator/install'
*/
export const install = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: install.url(args, options),
    method: 'post',
})

install.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/install',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::install
* @see app/Http/Controllers/EnvironmentController.php:364
* @route '/environments/{environment}/orchestrator/install'
*/
install.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return install.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::install
* @see app/Http/Controllers/EnvironmentController.php:364
* @route '/environments/{environment}/orchestrator/install'
*/
install.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: install.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::detect
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
export const detect = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: detect.url(args, options),
    method: 'get',
})

detect.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/orchestrator/detect',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::detect
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
detect.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return detect.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::detect
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
detect.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: detect.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::detect
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
detect.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: detect.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::reconcile
* @see app/Http/Controllers/EnvironmentController.php:384
* @route '/environments/{environment}/orchestrator/reconcile'
*/
export const reconcile = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reconcile.url(args, options),
    method: 'post',
})

reconcile.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/reconcile',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::reconcile
* @see app/Http/Controllers/EnvironmentController.php:384
* @route '/environments/{environment}/orchestrator/reconcile'
*/
reconcile.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return reconcile.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::reconcile
* @see app/Http/Controllers/EnvironmentController.php:384
* @route '/environments/{environment}/orchestrator/reconcile'
*/
reconcile.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reconcile.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::services
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
export const services = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: services.url(args, options),
    method: 'get',
})

services.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/orchestrator/services',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::services
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
services.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return services.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::services
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
services.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: services.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::services
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
services.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: services.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
export const projects = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projects.url(args, options),
    method: 'get',
})

projects.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/orchestrator/projects',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::projects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
projects.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return projects.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::projects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
projects.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projects.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
projects.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: projects.url(args, options),
    method: 'head',
})

const orchestrator = {
    enable: Object.assign(enable, enable),
    disable: Object.assign(disable, disable),
    install: Object.assign(install, install),
    detect: Object.assign(detect, detect),
    reconcile: Object.assign(reconcile, reconcile),
    services: Object.assign(services, services),
    projects: Object.assign(projects, projects),
}

export default orchestrator