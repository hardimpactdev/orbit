import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::available
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
export const available = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: available.url(args, options),
    method: 'get',
})

available.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services/available',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::available
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
available.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return available.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::available
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
available.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: available.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::available
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
available.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: available.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/environments/{environment}/services/{service}/start'
*/
export const start = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

start.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/environments/{environment}/services/{service}/start'
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
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/environments/{environment}/services/{service}/start'
*/
start.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/environments/{environment}/services/{service}/stop'
*/
export const stop = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/environments/{environment}/services/{service}/stop'
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
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/environments/{environment}/services/{service}/stop'
*/
stop.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/environments/{environment}/services/{service}/restart'
*/
export const restart = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restart.url(args, options),
    method: 'post',
})

restart.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/environments/{environment}/services/{service}/restart'
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
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/environments/{environment}/services/{service}/restart'
*/
restart.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restart.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::logs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
export const logs = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logs.url(args, options),
    method: 'get',
})

logs.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services/{service}/logs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::logs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
logs.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return logs.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::logs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
logs.get = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logs.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::logs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
logs.head = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: logs.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::enable
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/environments/{environment}/services/{service}/enable'
*/
export const enable = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enable.url(args, options),
    method: 'post',
})

enable.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::enable
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/environments/{environment}/services/{service}/enable'
*/
enable.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return enable.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::enable
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/environments/{environment}/services/{service}/enable'
*/
enable.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enable.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::disable
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/environments/{environment}/services/{service}'
*/
export const disable = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disable.url(args, options),
    method: 'delete',
})

disable.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/services/{service}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::disable
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/environments/{environment}/services/{service}'
*/
disable.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return disable.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::disable
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/environments/{environment}/services/{service}'
*/
disable.delete = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disable.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\EnvironmentController::config
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/environments/{environment}/services/{service}/config'
*/
export const config = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: config.url(args, options),
    method: 'put',
})

config.definition = {
    methods: ["put"],
    url: '/environments/{environment}/services/{service}/config',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\EnvironmentController::config
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/environments/{environment}/services/{service}/config'
*/
config.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return config.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::config
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/environments/{environment}/services/{service}/config'
*/
config.put = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: config.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\EnvironmentController::info
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
export const info = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: info.url(args, options),
    method: 'get',
})

info.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services/{service}/info',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::info
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
info.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return info.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::info
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
info.get = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: info.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::info
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
info.head = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: info.url(args, options),
    method: 'head',
})

const services = {
    available: Object.assign(available, available),
    start: Object.assign(start, start),
    stop: Object.assign(stop, stop),
    restart: Object.assign(restart, restart),
    logs: Object.assign(logs, logs),
    enable: Object.assign(enable, enable),
    disable: Object.assign(disable, disable),
    config: Object.assign(config, config),
    info: Object.assign(info, info),
}

export default services