import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults, validateParameters } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::testConnection
* @see app/Http/Controllers/EnvironmentController.php:200
* @route '/api/environments/{environment}/test-connection'
*/
export const testConnection = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: testConnection.url(args, options),
    method: 'post',
})

testConnection.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/test-connection',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::testConnection
* @see app/Http/Controllers/EnvironmentController.php:200
* @route '/api/environments/{environment}/test-connection'
*/
testConnection.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return testConnection.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::testConnection
* @see app/Http/Controllers/EnvironmentController.php:200
* @route '/api/environments/{environment}/test-connection'
*/
testConnection.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: testConnection.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::status
* @see app/Http/Controllers/EnvironmentController.php:230
* @route '/api/environments/{environment}/status'
*/
export const status = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::status
* @see app/Http/Controllers/EnvironmentController.php:230
* @route '/api/environments/{environment}/status'
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
* @see \App\Http\Controllers\EnvironmentController::status
* @see app/Http/Controllers/EnvironmentController.php:230
* @route '/api/environments/{environment}/status'
*/
status.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::status
* @see app/Http/Controllers/EnvironmentController.php:230
* @route '/api/environments/{environment}/status'
*/
status.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::sites
* @see app/Http/Controllers/EnvironmentController.php:241
* @route '/api/environments/{environment}/sites'
*/
export const sites = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: sites.url(args, options),
    method: 'get',
})

sites.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/sites',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::sites
* @see app/Http/Controllers/EnvironmentController.php:241
* @route '/api/environments/{environment}/sites'
*/
sites.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return sites.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::sites
* @see app/Http/Controllers/EnvironmentController.php:241
* @route '/api/environments/{environment}/sites'
*/
sites.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: sites.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::sites
* @see app/Http/Controllers/EnvironmentController.php:241
* @route '/api/environments/{environment}/sites'
*/
sites.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: sites.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::getConfig
* @see app/Http/Controllers/EnvironmentController.php:690
* @route '/api/environments/{environment}/config'
*/
export const getConfig = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getConfig.url(args, options),
    method: 'get',
})

getConfig.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/config',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::getConfig
* @see app/Http/Controllers/EnvironmentController.php:690
* @route '/api/environments/{environment}/config'
*/
getConfig.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return getConfig.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::getConfig
* @see app/Http/Controllers/EnvironmentController.php:690
* @route '/api/environments/{environment}/config'
*/
getConfig.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getConfig.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::getConfig
* @see app/Http/Controllers/EnvironmentController.php:690
* @route '/api/environments/{environment}/config'
*/
getConfig.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getConfig.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::worktrees
* @see app/Http/Controllers/EnvironmentController.php:819
* @route '/api/environments/{environment}/worktrees'
*/
export const worktrees = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: worktrees.url(args, options),
    method: 'get',
})

worktrees.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/worktrees',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::worktrees
* @see app/Http/Controllers/EnvironmentController.php:819
* @route '/api/environments/{environment}/worktrees'
*/
worktrees.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return worktrees.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::worktrees
* @see app/Http/Controllers/EnvironmentController.php:819
* @route '/api/environments/{environment}/worktrees'
*/
worktrees.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: worktrees.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::worktrees
* @see app/Http/Controllers/EnvironmentController.php:819
* @route '/api/environments/{environment}/worktrees'
*/
worktrees.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: worktrees.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projectsApi
* @see app/Http/Controllers/EnvironmentController.php:283
* @route '/api/environments/{environment}/projects'
*/
export const projectsApi = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projectsApi.url(args, options),
    method: 'get',
})

projectsApi.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/projects',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::projectsApi
* @see app/Http/Controllers/EnvironmentController.php:283
* @route '/api/environments/{environment}/projects'
*/
projectsApi.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return projectsApi.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::projectsApi
* @see app/Http/Controllers/EnvironmentController.php:283
* @route '/api/environments/{environment}/projects'
*/
projectsApi.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projectsApi.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projectsApi
* @see app/Http/Controllers/EnvironmentController.php:283
* @route '/api/environments/{environment}/projects'
*/
projectsApi.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: projectsApi.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::workspacesApi
* @see app/Http/Controllers/EnvironmentController.php:1348
* @route '/api/environments/{environment}/workspaces'
*/
export const workspacesApi = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspacesApi.url(args, options),
    method: 'get',
})

workspacesApi.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/workspaces',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::workspacesApi
* @see app/Http/Controllers/EnvironmentController.php:1348
* @route '/api/environments/{environment}/workspaces'
*/
workspacesApi.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return workspacesApi.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::workspacesApi
* @see app/Http/Controllers/EnvironmentController.php:1348
* @route '/api/environments/{environment}/workspaces'
*/
workspacesApi.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspacesApi.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::workspacesApi
* @see app/Http/Controllers/EnvironmentController.php:1348
* @route '/api/environments/{environment}/workspaces'
*/
workspacesApi.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: workspacesApi.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::workspaceApi
* @see app/Http/Controllers/EnvironmentController.php:1402
* @route '/api/environments/{environment}/workspaces/{workspace}'
*/
export const workspaceApi = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspaceApi.url(args, options),
    method: 'get',
})

workspaceApi.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/workspaces/{workspace}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::workspaceApi
* @see app/Http/Controllers/EnvironmentController.php:1402
* @route '/api/environments/{environment}/workspaces/{workspace}'
*/
workspaceApi.url = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            workspace: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        workspace: args.workspace,
    }

    return workspaceApi.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::workspaceApi
* @see app/Http/Controllers/EnvironmentController.php:1402
* @route '/api/environments/{environment}/workspaces/{workspace}'
*/
workspaceApi.get = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspaceApi.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::workspaceApi
* @see app/Http/Controllers/EnvironmentController.php:1402
* @route '/api/environments/{environment}/workspaces/{workspace}'
*/
workspaceApi.head = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: workspaceApi.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/api/environments/{environment}/start'
*/
const starteedb10b69e3eb71aeb5f93ecd08234b0 = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: starteedb10b69e3eb71aeb5f93ecd08234b0.url(args, options),
    method: 'post',
})

starteedb10b69e3eb71aeb5f93ecd08234b0.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/api/environments/{environment}/start'
*/
starteedb10b69e3eb71aeb5f93ecd08234b0.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return starteedb10b69e3eb71aeb5f93ecd08234b0.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/api/environments/{environment}/start'
*/
starteedb10b69e3eb71aeb5f93ecd08234b0.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: starteedb10b69e3eb71aeb5f93ecd08234b0.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/environments/{environment}/start'
*/
const startbba81c72f14d99b82bff15dd5825dd65 = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startbba81c72f14d99b82bff15dd5825dd65.url(args, options),
    method: 'post',
})

startbba81c72f14d99b82bff15dd5825dd65.definition = {
    methods: ["post"],
    url: '/environments/{environment}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/environments/{environment}/start'
*/
startbba81c72f14d99b82bff15dd5825dd65.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return startbba81c72f14d99b82bff15dd5825dd65.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/environments/{environment}/start'
*/
startbba81c72f14d99b82bff15dd5825dd65.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startbba81c72f14d99b82bff15dd5825dd65.url(args, options),
    method: 'post',
})

export const start = {
    '/api/environments/{environment}/start': starteedb10b69e3eb71aeb5f93ecd08234b0,
    '/environments/{environment}/start': startbba81c72f14d99b82bff15dd5825dd65,
}

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/api/environments/{environment}/stop'
*/
const stop514f8a117b1b8fb3c540470b30763110 = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop514f8a117b1b8fb3c540470b30763110.url(args, options),
    method: 'post',
})

stop514f8a117b1b8fb3c540470b30763110.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/api/environments/{environment}/stop'
*/
stop514f8a117b1b8fb3c540470b30763110.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return stop514f8a117b1b8fb3c540470b30763110.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/api/environments/{environment}/stop'
*/
stop514f8a117b1b8fb3c540470b30763110.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop514f8a117b1b8fb3c540470b30763110.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/environments/{environment}/stop'
*/
const stop3a0836242701f844ee83bcb7a3d67939 = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop3a0836242701f844ee83bcb7a3d67939.url(args, options),
    method: 'post',
})

stop3a0836242701f844ee83bcb7a3d67939.definition = {
    methods: ["post"],
    url: '/environments/{environment}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/environments/{environment}/stop'
*/
stop3a0836242701f844ee83bcb7a3d67939.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return stop3a0836242701f844ee83bcb7a3d67939.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/environments/{environment}/stop'
*/
stop3a0836242701f844ee83bcb7a3d67939.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop3a0836242701f844ee83bcb7a3d67939.url(args, options),
    method: 'post',
})

export const stop = {
    '/api/environments/{environment}/stop': stop514f8a117b1b8fb3c540470b30763110,
    '/environments/{environment}/stop': stop3a0836242701f844ee83bcb7a3d67939,
}

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/api/environments/{environment}/restart'
*/
const restartf20d796e140854979f5e12d001b0b911 = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartf20d796e140854979f5e12d001b0b911.url(args, options),
    method: 'post',
})

restartf20d796e140854979f5e12d001b0b911.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/api/environments/{environment}/restart'
*/
restartf20d796e140854979f5e12d001b0b911.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return restartf20d796e140854979f5e12d001b0b911.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/api/environments/{environment}/restart'
*/
restartf20d796e140854979f5e12d001b0b911.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartf20d796e140854979f5e12d001b0b911.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/environments/{environment}/restart'
*/
const restartaf42b303592297ea0da39723b4cf303d = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartaf42b303592297ea0da39723b4cf303d.url(args, options),
    method: 'post',
})

restartaf42b303592297ea0da39723b4cf303d.definition = {
    methods: ["post"],
    url: '/environments/{environment}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/environments/{environment}/restart'
*/
restartaf42b303592297ea0da39723b4cf303d.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return restartaf42b303592297ea0da39723b4cf303d.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/environments/{environment}/restart'
*/
restartaf42b303592297ea0da39723b4cf303d.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartaf42b303592297ea0da39723b4cf303d.url(args, options),
    method: 'post',
})

export const restart = {
    '/api/environments/{environment}/restart': restartf20d796e140854979f5e12d001b0b911,
    '/environments/{environment}/restart': restartaf42b303592297ea0da39723b4cf303d,
}

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/api/environments/{environment}/services/available'
*/
const availableServicescfd2392e7134d95ea01ab14d3f3d6617 = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: availableServicescfd2392e7134d95ea01ab14d3f3d6617.url(args, options),
    method: 'get',
})

availableServicescfd2392e7134d95ea01ab14d3f3d6617.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/services/available',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/api/environments/{environment}/services/available'
*/
availableServicescfd2392e7134d95ea01ab14d3f3d6617.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return availableServicescfd2392e7134d95ea01ab14d3f3d6617.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/api/environments/{environment}/services/available'
*/
availableServicescfd2392e7134d95ea01ab14d3f3d6617.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: availableServicescfd2392e7134d95ea01ab14d3f3d6617.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/api/environments/{environment}/services/available'
*/
availableServicescfd2392e7134d95ea01ab14d3f3d6617.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: availableServicescfd2392e7134d95ea01ab14d3f3d6617.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
const availableServices2ccf304e67472ac205f0a675d2612fff = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: availableServices2ccf304e67472ac205f0a675d2612fff.url(args, options),
    method: 'get',
})

availableServices2ccf304e67472ac205f0a675d2612fff.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services/available',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
availableServices2ccf304e67472ac205f0a675d2612fff.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return availableServices2ccf304e67472ac205f0a675d2612fff.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
availableServices2ccf304e67472ac205f0a675d2612fff.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: availableServices2ccf304e67472ac205f0a675d2612fff.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::availableServices
* @see app/Http/Controllers/EnvironmentController.php:618
* @route '/environments/{environment}/services/available'
*/
availableServices2ccf304e67472ac205f0a675d2612fff.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: availableServices2ccf304e67472ac205f0a675d2612fff.url(args, options),
    method: 'head',
})

export const availableServices = {
    '/api/environments/{environment}/services/available': availableServicescfd2392e7134d95ea01ab14d3f3d6617,
    '/environments/{environment}/services/available': availableServices2ccf304e67472ac205f0a675d2612fff,
}

/**
* @see \App\Http\Controllers\EnvironmentController::startService
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/api/environments/{environment}/services/{service}/start'
*/
const startService79568550ac416da0bfd5c482f3c05ac5 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startService79568550ac416da0bfd5c482f3c05ac5.url(args, options),
    method: 'post',
})

startService79568550ac416da0bfd5c482f3c05ac5.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/services/{service}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::startService
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/api/environments/{environment}/services/{service}/start'
*/
startService79568550ac416da0bfd5c482f3c05ac5.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return startService79568550ac416da0bfd5c482f3c05ac5.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::startService
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/api/environments/{environment}/services/{service}/start'
*/
startService79568550ac416da0bfd5c482f3c05ac5.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startService79568550ac416da0bfd5c482f3c05ac5.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::startService
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/environments/{environment}/services/{service}/start'
*/
const startServiced8e81fa071feb225b0016bb510f10c12 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startServiced8e81fa071feb225b0016bb510f10c12.url(args, options),
    method: 'post',
})

startServiced8e81fa071feb225b0016bb510f10c12.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::startService
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/environments/{environment}/services/{service}/start'
*/
startServiced8e81fa071feb225b0016bb510f10c12.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return startServiced8e81fa071feb225b0016bb510f10c12.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::startService
* @see app/Http/Controllers/EnvironmentController.php:538
* @route '/environments/{environment}/services/{service}/start'
*/
startServiced8e81fa071feb225b0016bb510f10c12.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startServiced8e81fa071feb225b0016bb510f10c12.url(args, options),
    method: 'post',
})

export const startService = {
    '/api/environments/{environment}/services/{service}/start': startService79568550ac416da0bfd5c482f3c05ac5,
    '/environments/{environment}/services/{service}/start': startServiced8e81fa071feb225b0016bb510f10c12,
}

/**
* @see \App\Http\Controllers\EnvironmentController::stopService
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/api/environments/{environment}/services/{service}/stop'
*/
const stopService35fed42137c3f4e13833cf377bae7b98 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopService35fed42137c3f4e13833cf377bae7b98.url(args, options),
    method: 'post',
})

stopService35fed42137c3f4e13833cf377bae7b98.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/services/{service}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stopService
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/api/environments/{environment}/services/{service}/stop'
*/
stopService35fed42137c3f4e13833cf377bae7b98.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return stopService35fed42137c3f4e13833cf377bae7b98.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stopService
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/api/environments/{environment}/services/{service}/stop'
*/
stopService35fed42137c3f4e13833cf377bae7b98.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopService35fed42137c3f4e13833cf377bae7b98.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::stopService
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/environments/{environment}/services/{service}/stop'
*/
const stopService0e29bdafbe478119ac0327aca00edc88 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopService0e29bdafbe478119ac0327aca00edc88.url(args, options),
    method: 'post',
})

stopService0e29bdafbe478119ac0327aca00edc88.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stopService
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/environments/{environment}/services/{service}/stop'
*/
stopService0e29bdafbe478119ac0327aca00edc88.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return stopService0e29bdafbe478119ac0327aca00edc88.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stopService
* @see app/Http/Controllers/EnvironmentController.php:558
* @route '/environments/{environment}/services/{service}/stop'
*/
stopService0e29bdafbe478119ac0327aca00edc88.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopService0e29bdafbe478119ac0327aca00edc88.url(args, options),
    method: 'post',
})

export const stopService = {
    '/api/environments/{environment}/services/{service}/stop': stopService35fed42137c3f4e13833cf377bae7b98,
    '/environments/{environment}/services/{service}/stop': stopService0e29bdafbe478119ac0327aca00edc88,
}

/**
* @see \App\Http\Controllers\EnvironmentController::restartService
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/api/environments/{environment}/services/{service}/restart'
*/
const restartService4838eb9f5edebbdf1bdb3328d83862a2 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartService4838eb9f5edebbdf1bdb3328d83862a2.url(args, options),
    method: 'post',
})

restartService4838eb9f5edebbdf1bdb3328d83862a2.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/services/{service}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restartService
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/api/environments/{environment}/services/{service}/restart'
*/
restartService4838eb9f5edebbdf1bdb3328d83862a2.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return restartService4838eb9f5edebbdf1bdb3328d83862a2.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restartService
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/api/environments/{environment}/services/{service}/restart'
*/
restartService4838eb9f5edebbdf1bdb3328d83862a2.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartService4838eb9f5edebbdf1bdb3328d83862a2.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::restartService
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/environments/{environment}/services/{service}/restart'
*/
const restartServiceb46f3d9034650bc6f0ee2717a6c6c490 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartServiceb46f3d9034650bc6f0ee2717a6c6c490.url(args, options),
    method: 'post',
})

restartServiceb46f3d9034650bc6f0ee2717a6c6c490.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restartService
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/environments/{environment}/services/{service}/restart'
*/
restartServiceb46f3d9034650bc6f0ee2717a6c6c490.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return restartServiceb46f3d9034650bc6f0ee2717a6c6c490.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restartService
* @see app/Http/Controllers/EnvironmentController.php:578
* @route '/environments/{environment}/services/{service}/restart'
*/
restartServiceb46f3d9034650bc6f0ee2717a6c6c490.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartServiceb46f3d9034650bc6f0ee2717a6c6c490.url(args, options),
    method: 'post',
})

export const restartService = {
    '/api/environments/{environment}/services/{service}/restart': restartService4838eb9f5edebbdf1bdb3328d83862a2,
    '/environments/{environment}/services/{service}/restart': restartServiceb46f3d9034650bc6f0ee2717a6c6c490,
}

/**
* @see \App\Http\Controllers\EnvironmentController::startHostService
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/api/environments/{environment}/host-services/{service}/start'
*/
const startHostServicee0be8bbb1a9007e459226801f9c2f864 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startHostServicee0be8bbb1a9007e459226801f9c2f864.url(args, options),
    method: 'post',
})

startHostServicee0be8bbb1a9007e459226801f9c2f864.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/host-services/{service}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::startHostService
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/api/environments/{environment}/host-services/{service}/start'
*/
startHostServicee0be8bbb1a9007e459226801f9c2f864.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return startHostServicee0be8bbb1a9007e459226801f9c2f864.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::startHostService
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/api/environments/{environment}/host-services/{service}/start'
*/
startHostServicee0be8bbb1a9007e459226801f9c2f864.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startHostServicee0be8bbb1a9007e459226801f9c2f864.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::startHostService
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/environments/{environment}/host-services/{service}/start'
*/
const startHostServicebaf7f5e766d365c20a027dec0945dd63 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startHostServicebaf7f5e766d365c20a027dec0945dd63.url(args, options),
    method: 'post',
})

startHostServicebaf7f5e766d365c20a027dec0945dd63.definition = {
    methods: ["post"],
    url: '/environments/{environment}/host-services/{service}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::startHostService
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/environments/{environment}/host-services/{service}/start'
*/
startHostServicebaf7f5e766d365c20a027dec0945dd63.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return startHostServicebaf7f5e766d365c20a027dec0945dd63.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::startHostService
* @see app/Http/Controllers/EnvironmentController.php:548
* @route '/environments/{environment}/host-services/{service}/start'
*/
startHostServicebaf7f5e766d365c20a027dec0945dd63.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startHostServicebaf7f5e766d365c20a027dec0945dd63.url(args, options),
    method: 'post',
})

export const startHostService = {
    '/api/environments/{environment}/host-services/{service}/start': startHostServicee0be8bbb1a9007e459226801f9c2f864,
    '/environments/{environment}/host-services/{service}/start': startHostServicebaf7f5e766d365c20a027dec0945dd63,
}

/**
* @see \App\Http\Controllers\EnvironmentController::stopHostService
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/api/environments/{environment}/host-services/{service}/stop'
*/
const stopHostService917f1f4fbb1cab3d853a5796ac1b8a46 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopHostService917f1f4fbb1cab3d853a5796ac1b8a46.url(args, options),
    method: 'post',
})

stopHostService917f1f4fbb1cab3d853a5796ac1b8a46.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/host-services/{service}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stopHostService
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/api/environments/{environment}/host-services/{service}/stop'
*/
stopHostService917f1f4fbb1cab3d853a5796ac1b8a46.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return stopHostService917f1f4fbb1cab3d853a5796ac1b8a46.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stopHostService
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/api/environments/{environment}/host-services/{service}/stop'
*/
stopHostService917f1f4fbb1cab3d853a5796ac1b8a46.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopHostService917f1f4fbb1cab3d853a5796ac1b8a46.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::stopHostService
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/environments/{environment}/host-services/{service}/stop'
*/
const stopHostService866986b402225de9b480a7d2471f5199 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopHostService866986b402225de9b480a7d2471f5199.url(args, options),
    method: 'post',
})

stopHostService866986b402225de9b480a7d2471f5199.definition = {
    methods: ["post"],
    url: '/environments/{environment}/host-services/{service}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stopHostService
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/environments/{environment}/host-services/{service}/stop'
*/
stopHostService866986b402225de9b480a7d2471f5199.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return stopHostService866986b402225de9b480a7d2471f5199.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stopHostService
* @see app/Http/Controllers/EnvironmentController.php:568
* @route '/environments/{environment}/host-services/{service}/stop'
*/
stopHostService866986b402225de9b480a7d2471f5199.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopHostService866986b402225de9b480a7d2471f5199.url(args, options),
    method: 'post',
})

export const stopHostService = {
    '/api/environments/{environment}/host-services/{service}/stop': stopHostService917f1f4fbb1cab3d853a5796ac1b8a46,
    '/environments/{environment}/host-services/{service}/stop': stopHostService866986b402225de9b480a7d2471f5199,
}

/**
* @see \App\Http\Controllers\EnvironmentController::restartHostService
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/api/environments/{environment}/host-services/{service}/restart'
*/
const restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0.url(args, options),
    method: 'post',
})

restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/host-services/{service}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restartHostService
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/api/environments/{environment}/host-services/{service}/restart'
*/
restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restartHostService
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/api/environments/{environment}/host-services/{service}/restart'
*/
restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::restartHostService
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/environments/{environment}/host-services/{service}/restart'
*/
const restartHostService934df996fed0075a45d1ec60aee9696a = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartHostService934df996fed0075a45d1ec60aee9696a.url(args, options),
    method: 'post',
})

restartHostService934df996fed0075a45d1ec60aee9696a.definition = {
    methods: ["post"],
    url: '/environments/{environment}/host-services/{service}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restartHostService
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/environments/{environment}/host-services/{service}/restart'
*/
restartHostService934df996fed0075a45d1ec60aee9696a.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return restartHostService934df996fed0075a45d1ec60aee9696a.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restartHostService
* @see app/Http/Controllers/EnvironmentController.php:588
* @route '/environments/{environment}/host-services/{service}/restart'
*/
restartHostService934df996fed0075a45d1ec60aee9696a.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restartHostService934df996fed0075a45d1ec60aee9696a.url(args, options),
    method: 'post',
})

export const restartHostService = {
    '/api/environments/{environment}/host-services/{service}/restart': restartHostService5eb8d328a46df9f2c2fbf0ff6ad556f0,
    '/environments/{environment}/host-services/{service}/restart': restartHostService934df996fed0075a45d1ec60aee9696a,
}

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/api/environments/{environment}/services/{service}/logs'
*/
const serviceLogs5dcf916db207ce79af35c2e6cbabbbf2 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.url(args, options),
    method: 'get',
})

serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/services/{service}/logs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/api/environments/{environment}/services/{service}/logs'
*/
serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/api/environments/{environment}/services/{service}/logs'
*/
serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.get = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/api/environments/{environment}/services/{service}/logs'
*/
serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.head = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: serviceLogs5dcf916db207ce79af35c2e6cbabbbf2.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
const serviceLogs7cfc83cde278b475db4fef01bb32ffa3 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceLogs7cfc83cde278b475db4fef01bb32ffa3.url(args, options),
    method: 'get',
})

serviceLogs7cfc83cde278b475db4fef01bb32ffa3.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services/{service}/logs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
serviceLogs7cfc83cde278b475db4fef01bb32ffa3.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return serviceLogs7cfc83cde278b475db4fef01bb32ffa3.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
serviceLogs7cfc83cde278b475db4fef01bb32ffa3.get = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceLogs7cfc83cde278b475db4fef01bb32ffa3.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::serviceLogs
* @see app/Http/Controllers/EnvironmentController.php:598
* @route '/environments/{environment}/services/{service}/logs'
*/
serviceLogs7cfc83cde278b475db4fef01bb32ffa3.head = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: serviceLogs7cfc83cde278b475db4fef01bb32ffa3.url(args, options),
    method: 'head',
})

export const serviceLogs = {
    '/api/environments/{environment}/services/{service}/logs': serviceLogs5dcf916db207ce79af35c2e6cbabbbf2,
    '/environments/{environment}/services/{service}/logs': serviceLogs7cfc83cde278b475db4fef01bb32ffa3,
}

/**
* @see \App\Http\Controllers\EnvironmentController::hostServiceLogs
* @see app/Http/Controllers/EnvironmentController.php:608
* @route '/api/environments/{environment}/host-services/{service}/logs'
*/
export const hostServiceLogs = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: hostServiceLogs.url(args, options),
    method: 'get',
})

hostServiceLogs.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/host-services/{service}/logs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::hostServiceLogs
* @see app/Http/Controllers/EnvironmentController.php:608
* @route '/api/environments/{environment}/host-services/{service}/logs'
*/
hostServiceLogs.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return hostServiceLogs.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::hostServiceLogs
* @see app/Http/Controllers/EnvironmentController.php:608
* @route '/api/environments/{environment}/host-services/{service}/logs'
*/
hostServiceLogs.get = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: hostServiceLogs.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::hostServiceLogs
* @see app/Http/Controllers/EnvironmentController.php:608
* @route '/api/environments/{environment}/host-services/{service}/logs'
*/
hostServiceLogs.head = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: hostServiceLogs.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::enableService
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/api/environments/{environment}/services/{service}/enable'
*/
const enableServicec3de7b8152f3861bf40ede8018fe5c45 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableServicec3de7b8152f3861bf40ede8018fe5c45.url(args, options),
    method: 'post',
})

enableServicec3de7b8152f3861bf40ede8018fe5c45.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/services/{service}/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::enableService
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/api/environments/{environment}/services/{service}/enable'
*/
enableServicec3de7b8152f3861bf40ede8018fe5c45.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return enableServicec3de7b8152f3861bf40ede8018fe5c45.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::enableService
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/api/environments/{environment}/services/{service}/enable'
*/
enableServicec3de7b8152f3861bf40ede8018fe5c45.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableServicec3de7b8152f3861bf40ede8018fe5c45.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::enableService
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/environments/{environment}/services/{service}/enable'
*/
const enableService999b4b0813c7c4f5898142fe7eb10abc = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableService999b4b0813c7c4f5898142fe7eb10abc.url(args, options),
    method: 'post',
})

enableService999b4b0813c7c4f5898142fe7eb10abc.definition = {
    methods: ["post"],
    url: '/environments/{environment}/services/{service}/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::enableService
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/environments/{environment}/services/{service}/enable'
*/
enableService999b4b0813c7c4f5898142fe7eb10abc.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return enableService999b4b0813c7c4f5898142fe7eb10abc.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::enableService
* @see app/Http/Controllers/EnvironmentController.php:628
* @route '/environments/{environment}/services/{service}/enable'
*/
enableService999b4b0813c7c4f5898142fe7eb10abc.post = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableService999b4b0813c7c4f5898142fe7eb10abc.url(args, options),
    method: 'post',
})

export const enableService = {
    '/api/environments/{environment}/services/{service}/enable': enableServicec3de7b8152f3861bf40ede8018fe5c45,
    '/environments/{environment}/services/{service}/enable': enableService999b4b0813c7c4f5898142fe7eb10abc,
}

/**
* @see \App\Http\Controllers\EnvironmentController::disableService
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/api/environments/{environment}/services/{service}'
*/
const disableService78afa45194a63eff9ce72817d1b965de = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disableService78afa45194a63eff9ce72817d1b965de.url(args, options),
    method: 'delete',
})

disableService78afa45194a63eff9ce72817d1b965de.definition = {
    methods: ["delete"],
    url: '/api/environments/{environment}/services/{service}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::disableService
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/api/environments/{environment}/services/{service}'
*/
disableService78afa45194a63eff9ce72817d1b965de.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return disableService78afa45194a63eff9ce72817d1b965de.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::disableService
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/api/environments/{environment}/services/{service}'
*/
disableService78afa45194a63eff9ce72817d1b965de.delete = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disableService78afa45194a63eff9ce72817d1b965de.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\EnvironmentController::disableService
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/environments/{environment}/services/{service}'
*/
const disableService87be0ff86da324b28455a16c57512b91 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disableService87be0ff86da324b28455a16c57512b91.url(args, options),
    method: 'delete',
})

disableService87be0ff86da324b28455a16c57512b91.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/services/{service}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::disableService
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/environments/{environment}/services/{service}'
*/
disableService87be0ff86da324b28455a16c57512b91.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return disableService87be0ff86da324b28455a16c57512b91.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::disableService
* @see app/Http/Controllers/EnvironmentController.php:639
* @route '/environments/{environment}/services/{service}'
*/
disableService87be0ff86da324b28455a16c57512b91.delete = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disableService87be0ff86da324b28455a16c57512b91.url(args, options),
    method: 'delete',
})

export const disableService = {
    '/api/environments/{environment}/services/{service}': disableService78afa45194a63eff9ce72817d1b965de,
    '/environments/{environment}/services/{service}': disableService87be0ff86da324b28455a16c57512b91,
}

/**
* @see \App\Http\Controllers\EnvironmentController::configureService
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/api/environments/{environment}/services/{service}/config'
*/
const configureService1a30773445c19092ec4236afd15f92fa = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: configureService1a30773445c19092ec4236afd15f92fa.url(args, options),
    method: 'put',
})

configureService1a30773445c19092ec4236afd15f92fa.definition = {
    methods: ["put"],
    url: '/api/environments/{environment}/services/{service}/config',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\EnvironmentController::configureService
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/api/environments/{environment}/services/{service}/config'
*/
configureService1a30773445c19092ec4236afd15f92fa.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return configureService1a30773445c19092ec4236afd15f92fa.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::configureService
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/api/environments/{environment}/services/{service}/config'
*/
configureService1a30773445c19092ec4236afd15f92fa.put = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: configureService1a30773445c19092ec4236afd15f92fa.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\EnvironmentController::configureService
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/environments/{environment}/services/{service}/config'
*/
const configureServiced19d94f23b1d601bb0d3634566c64251 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: configureServiced19d94f23b1d601bb0d3634566c64251.url(args, options),
    method: 'put',
})

configureServiced19d94f23b1d601bb0d3634566c64251.definition = {
    methods: ["put"],
    url: '/environments/{environment}/services/{service}/config',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\EnvironmentController::configureService
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/environments/{environment}/services/{service}/config'
*/
configureServiced19d94f23b1d601bb0d3634566c64251.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return configureServiced19d94f23b1d601bb0d3634566c64251.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::configureService
* @see app/Http/Controllers/EnvironmentController.php:649
* @route '/environments/{environment}/services/{service}/config'
*/
configureServiced19d94f23b1d601bb0d3634566c64251.put = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: configureServiced19d94f23b1d601bb0d3634566c64251.url(args, options),
    method: 'put',
})

export const configureService = {
    '/api/environments/{environment}/services/{service}/config': configureService1a30773445c19092ec4236afd15f92fa,
    '/environments/{environment}/services/{service}/config': configureServiced19d94f23b1d601bb0d3634566c64251,
}

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/api/environments/{environment}/services/{service}/info'
*/
const serviceInfo8cb7fc8bec450f9555be873d8ca91ba7 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.url(args, options),
    method: 'get',
})

serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/services/{service}/info',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/api/environments/{environment}/services/{service}/info'
*/
serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/api/environments/{environment}/services/{service}/info'
*/
serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.get = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/api/environments/{environment}/services/{service}/info'
*/
serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.head = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: serviceInfo8cb7fc8bec450f9555be873d8ca91ba7.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
const serviceInfo25019a124074ebb6439c33fccb1f3045 = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceInfo25019a124074ebb6439c33fccb1f3045.url(args, options),
    method: 'get',
})

serviceInfo25019a124074ebb6439c33fccb1f3045.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services/{service}/info',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
serviceInfo25019a124074ebb6439c33fccb1f3045.url = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions) => {
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

    return serviceInfo25019a124074ebb6439c33fccb1f3045.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{service}', parsedArgs.service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
serviceInfo25019a124074ebb6439c33fccb1f3045.get = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serviceInfo25019a124074ebb6439c33fccb1f3045.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::serviceInfo
* @see app/Http/Controllers/EnvironmentController.php:660
* @route '/environments/{environment}/services/{service}/info'
*/
serviceInfo25019a124074ebb6439c33fccb1f3045.head = (args: { environment: number | { id: number }, service: string | number } | [environment: number | { id: number }, service: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: serviceInfo25019a124074ebb6439c33fccb1f3045.url(args, options),
    method: 'head',
})

export const serviceInfo = {
    '/api/environments/{environment}/services/{service}/info': serviceInfo8cb7fc8bec450f9555be873d8ca91ba7,
    '/environments/{environment}/services/{service}/info': serviceInfo25019a124074ebb6439c33fccb1f3045,
}

/**
* @see \App\Http\Controllers\EnvironmentController::getPhpConfig
* @see app/Http/Controllers/EnvironmentController.php:1583
* @route '/api/environments/{environment}/php/config/{version?}'
*/
export const getPhpConfig = (args: { environment: number | { id: number }, version?: string | number } | [environment: number | { id: number }, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getPhpConfig.url(args, options),
    method: 'get',
})

getPhpConfig.definition = {
    methods: ["get","head"],
    url: '/api/environments/{environment}/php/config/{version?}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::getPhpConfig
* @see app/Http/Controllers/EnvironmentController.php:1583
* @route '/api/environments/{environment}/php/config/{version?}'
*/
getPhpConfig.url = (args: { environment: number | { id: number }, version?: string | number } | [environment: number | { id: number }, version: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            version: args[1],
        }
    }

    args = applyUrlDefaults(args)

    validateParameters(args, [
        "version",
    ])

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        version: args.version,
    }

    return getPhpConfig.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{version?}', parsedArgs.version?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::getPhpConfig
* @see app/Http/Controllers/EnvironmentController.php:1583
* @route '/api/environments/{environment}/php/config/{version?}'
*/
getPhpConfig.get = (args: { environment: number | { id: number }, version?: string | number } | [environment: number | { id: number }, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getPhpConfig.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::getPhpConfig
* @see app/Http/Controllers/EnvironmentController.php:1583
* @route '/api/environments/{environment}/php/config/{version?}'
*/
getPhpConfig.head = (args: { environment: number | { id: number }, version?: string | number } | [environment: number | { id: number }, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getPhpConfig.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::setPhpConfig
* @see app/Http/Controllers/EnvironmentController.php:1596
* @route '/api/environments/{environment}/php/config/{version?}'
*/
export const setPhpConfig = (args: { environment: number | { id: number }, version?: string | number } | [environment: number | { id: number }, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setPhpConfig.url(args, options),
    method: 'post',
})

setPhpConfig.definition = {
    methods: ["post"],
    url: '/api/environments/{environment}/php/config/{version?}',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::setPhpConfig
* @see app/Http/Controllers/EnvironmentController.php:1596
* @route '/api/environments/{environment}/php/config/{version?}'
*/
setPhpConfig.url = (args: { environment: number | { id: number }, version?: string | number } | [environment: number | { id: number }, version: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            version: args[1],
        }
    }

    args = applyUrlDefaults(args)

    validateParameters(args, [
        "version",
    ])

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        version: args.version,
    }

    return setPhpConfig.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{version?}', parsedArgs.version?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::setPhpConfig
* @see app/Http/Controllers/EnvironmentController.php:1596
* @route '/api/environments/{environment}/php/config/{version?}'
*/
setPhpConfig.post = (args: { environment: number | { id: number }, version?: string | number } | [environment: number | { id: number }, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setPhpConfig.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::index
* @see app/Http/Controllers/EnvironmentController.php:53
* @route '/environments'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/environments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::index
* @see app/Http/Controllers/EnvironmentController.php:53
* @route '/environments'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::index
* @see app/Http/Controllers/EnvironmentController.php:53
* @route '/environments'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::index
* @see app/Http/Controllers/EnvironmentController.php:53
* @route '/environments'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:64
* @route '/environments/create'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/environments/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:64
* @route '/environments/create'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:64
* @route '/environments/create'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:64
* @route '/environments/create'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:78
* @route '/environments'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/environments',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:78
* @route '/environments'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:78
* @route '/environments'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:100
* @route '/environments/{environment}'
*/
export const show = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:100
* @route '/environments/{environment}'
*/
show.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:100
* @route '/environments/{environment}'
*/
show.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:100
* @route '/environments/{environment}'
*/
show.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::edit
* @see app/Http/Controllers/EnvironmentController.php:125
* @route '/environments/{environment}/edit'
*/
export const edit = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/edit',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::edit
* @see app/Http/Controllers/EnvironmentController.php:125
* @route '/environments/{environment}/edit'
*/
edit.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return edit.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::edit
* @see app/Http/Controllers/EnvironmentController.php:125
* @route '/environments/{environment}/edit'
*/
edit.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::edit
* @see app/Http/Controllers/EnvironmentController.php:125
* @route '/environments/{environment}/edit'
*/
edit.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::update
* @see app/Http/Controllers/EnvironmentController.php:132
* @route '/environments/{environment}'
*/
export const update = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/environments/{environment}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\EnvironmentController::update
* @see app/Http/Controllers/EnvironmentController.php:132
* @route '/environments/{environment}'
*/
update.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::update
* @see app/Http/Controllers/EnvironmentController.php:132
* @route '/environments/{environment}'
*/
update.put = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\EnvironmentController::update
* @see app/Http/Controllers/EnvironmentController.php:132
* @route '/environments/{environment}'
*/
update.patch = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:154
* @route '/environments/{environment}'
*/
export const destroy = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/environments/{environment}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:154
* @route '/environments/{environment}'
*/
destroy.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:154
* @route '/environments/{environment}'
*/
destroy.delete = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\EnvironmentController::getAllTlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
export const getAllTlds = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getAllTlds.url(options),
    method: 'get',
})

getAllTlds.definition = {
    methods: ["get","head"],
    url: '/api/environments/tlds',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::getAllTlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
getAllTlds.url = (options?: RouteQueryOptions) => {
    return getAllTlds.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::getAllTlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
getAllTlds.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getAllTlds.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::getAllTlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
getAllTlds.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getAllTlds.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::setDefault
* @see app/Http/Controllers/EnvironmentController.php:189
* @route '/environments/{environment}/set-default'
*/
export const setDefault = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setDefault.url(args, options),
    method: 'post',
})

setDefault.definition = {
    methods: ["post"],
    url: '/environments/{environment}/set-default',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::setDefault
* @see app/Http/Controllers/EnvironmentController.php:189
* @route '/environments/{environment}/set-default'
*/
setDefault.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return setDefault.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::setDefault
* @see app/Http/Controllers/EnvironmentController.php:189
* @route '/environments/{environment}/set-default'
*/
setDefault.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setDefault.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::runDoctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
export const runDoctor = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: runDoctor.url(args, options),
    method: 'get',
})

runDoctor.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/doctor',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::runDoctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
runDoctor.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return runDoctor.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::runDoctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
runDoctor.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: runDoctor.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::runDoctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
runDoctor.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: runDoctor.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::quickCheck
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
export const quickCheck = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: quickCheck.url(args, options),
    method: 'get',
})

quickCheck.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/doctor/quick',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::quickCheck
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
quickCheck.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return quickCheck.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::quickCheck
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
quickCheck.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: quickCheck.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::quickCheck
* @see app/Http/Controllers/EnvironmentController.php:1563
* @route '/environments/{environment}/doctor/quick'
*/
quickCheck.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: quickCheck.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::fixDoctorIssue
* @see app/Http/Controllers/EnvironmentController.php:1573
* @route '/environments/{environment}/doctor/fix/{check}'
*/
export const fixDoctorIssue = (args: { environment: number | { id: number }, check: string | number } | [environment: number | { id: number }, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fixDoctorIssue.url(args, options),
    method: 'post',
})

fixDoctorIssue.definition = {
    methods: ["post"],
    url: '/environments/{environment}/doctor/fix/{check}',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::fixDoctorIssue
* @see app/Http/Controllers/EnvironmentController.php:1573
* @route '/environments/{environment}/doctor/fix/{check}'
*/
fixDoctorIssue.url = (args: { environment: number | { id: number }, check: string | number } | [environment: number | { id: number }, check: string | number ], options?: RouteQueryOptions) => {
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

    return fixDoctorIssue.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::fixDoctorIssue
* @see app/Http/Controllers/EnvironmentController.php:1573
* @route '/environments/{environment}/doctor/fix/{check}'
*/
fixDoctorIssue.post = (args: { environment: number | { id: number }, check: string | number } | [environment: number | { id: number }, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fixDoctorIssue.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projectsPage
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
*/
export const projectsPage = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projectsPage.url(args, options),
    method: 'get',
})

projectsPage.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/projects',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::projectsPage
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
*/
projectsPage.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return projectsPage.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::projectsPage
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
*/
projectsPage.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projectsPage.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projectsPage
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
*/
projectsPage.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: projectsPage.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::servicesPage
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
*/
export const servicesPage = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: servicesPage.url(args, options),
    method: 'get',
})

servicesPage.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::servicesPage
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
*/
servicesPage.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return servicesPage.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::servicesPage
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
*/
servicesPage.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: servicesPage.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::servicesPage
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
*/
servicesPage.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: servicesPage.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::orchestrator
* @see app/Http/Controllers/EnvironmentController.php:318
* @route '/environments/{environment}/orchestrator'
*/
export const orchestrator = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: orchestrator.url(args, options),
    method: 'get',
})

orchestrator.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/orchestrator',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::orchestrator
* @see app/Http/Controllers/EnvironmentController.php:318
* @route '/environments/{environment}/orchestrator'
*/
orchestrator.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return orchestrator.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::orchestrator
* @see app/Http/Controllers/EnvironmentController.php:318
* @route '/environments/{environment}/orchestrator'
*/
orchestrator.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: orchestrator.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::orchestrator
* @see app/Http/Controllers/EnvironmentController.php:318
* @route '/environments/{environment}/orchestrator'
*/
orchestrator.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: orchestrator.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::enableOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:328
* @route '/environments/{environment}/orchestrator/enable'
*/
export const enableOrchestrator = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableOrchestrator.url(args, options),
    method: 'post',
})

enableOrchestrator.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::enableOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:328
* @route '/environments/{environment}/orchestrator/enable'
*/
enableOrchestrator.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return enableOrchestrator.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::enableOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:328
* @route '/environments/{environment}/orchestrator/enable'
*/
enableOrchestrator.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableOrchestrator.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::disableOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:348
* @route '/environments/{environment}/orchestrator/disable'
*/
export const disableOrchestrator = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: disableOrchestrator.url(args, options),
    method: 'post',
})

disableOrchestrator.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/disable',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::disableOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:348
* @route '/environments/{environment}/orchestrator/disable'
*/
disableOrchestrator.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return disableOrchestrator.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::disableOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:348
* @route '/environments/{environment}/orchestrator/disable'
*/
disableOrchestrator.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: disableOrchestrator.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::installOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:364
* @route '/environments/{environment}/orchestrator/install'
*/
export const installOrchestrator = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: installOrchestrator.url(args, options),
    method: 'post',
})

installOrchestrator.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/install',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::installOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:364
* @route '/environments/{environment}/orchestrator/install'
*/
installOrchestrator.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return installOrchestrator.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::installOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:364
* @route '/environments/{environment}/orchestrator/install'
*/
installOrchestrator.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: installOrchestrator.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::detectOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
export const detectOrchestrator = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: detectOrchestrator.url(args, options),
    method: 'get',
})

detectOrchestrator.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/orchestrator/detect',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::detectOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
detectOrchestrator.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return detectOrchestrator.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::detectOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
detectOrchestrator.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: detectOrchestrator.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::detectOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:374
* @route '/environments/{environment}/orchestrator/detect'
*/
detectOrchestrator.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: detectOrchestrator.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::reconcileOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:384
* @route '/environments/{environment}/orchestrator/reconcile'
*/
export const reconcileOrchestrator = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reconcileOrchestrator.url(args, options),
    method: 'post',
})

reconcileOrchestrator.definition = {
    methods: ["post"],
    url: '/environments/{environment}/orchestrator/reconcile',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::reconcileOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:384
* @route '/environments/{environment}/orchestrator/reconcile'
*/
reconcileOrchestrator.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return reconcileOrchestrator.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::reconcileOrchestrator
* @see app/Http/Controllers/EnvironmentController.php:384
* @route '/environments/{environment}/orchestrator/reconcile'
*/
reconcileOrchestrator.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reconcileOrchestrator.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorServices
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
export const orchestratorServices = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: orchestratorServices.url(args, options),
    method: 'get',
})

orchestratorServices.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/orchestrator/services',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorServices
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
orchestratorServices.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return orchestratorServices.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorServices
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
orchestratorServices.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: orchestratorServices.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorServices
* @see app/Http/Controllers/EnvironmentController.php:402
* @route '/environments/{environment}/orchestrator/services'
*/
orchestratorServices.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: orchestratorServices.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorProjects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
export const orchestratorProjects = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: orchestratorProjects.url(args, options),
    method: 'get',
})

orchestratorProjects.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/orchestrator/projects',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorProjects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
orchestratorProjects.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return orchestratorProjects.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorProjects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
orchestratorProjects.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: orchestratorProjects.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::orchestratorProjects
* @see app/Http/Controllers/EnvironmentController.php:467
* @route '/environments/{environment}/orchestrator/projects'
*/
orchestratorProjects.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: orchestratorProjects.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::settings
* @see app/Http/Controllers/EnvironmentController.php:291
* @route '/environments/{environment}/settings'
*/
export const settings = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(args, options),
    method: 'get',
})

settings.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::settings
* @see app/Http/Controllers/EnvironmentController.php:291
* @route '/environments/{environment}/settings'
*/
settings.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return settings.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::settings
* @see app/Http/Controllers/EnvironmentController.php:291
* @route '/environments/{environment}/settings'
*/
settings.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::settings
* @see app/Http/Controllers/EnvironmentController.php:291
* @route '/environments/{environment}/settings'
*/
settings.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: settings.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::updateSettings
* @see app/Http/Controllers/EnvironmentController.php:301
* @route '/environments/{environment}/settings'
*/
export const updateSettings = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: updateSettings.url(args, options),
    method: 'post',
})

updateSettings.definition = {
    methods: ["post"],
    url: '/environments/{environment}/settings',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::updateSettings
* @see app/Http/Controllers/EnvironmentController.php:301
* @route '/environments/{environment}/settings'
*/
updateSettings.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return updateSettings.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::updateSettings
* @see app/Http/Controllers/EnvironmentController.php:301
* @route '/environments/{environment}/settings'
*/
updateSettings.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: updateSettings.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::changePhp
* @see app/Http/Controllers/EnvironmentController.php:667
* @route '/environments/{environment}/php'
*/
export const changePhp = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: changePhp.url(args, options),
    method: 'post',
})

changePhp.definition = {
    methods: ["post"],
    url: '/environments/{environment}/php',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::changePhp
* @see app/Http/Controllers/EnvironmentController.php:667
* @route '/environments/{environment}/php'
*/
changePhp.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return changePhp.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::changePhp
* @see app/Http/Controllers/EnvironmentController.php:667
* @route '/environments/{environment}/php'
*/
changePhp.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: changePhp.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::resetPhp
* @see app/Http/Controllers/EnvironmentController.php:679
* @route '/environments/{environment}/php/reset'
*/
export const resetPhp = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resetPhp.url(args, options),
    method: 'post',
})

resetPhp.definition = {
    methods: ["post"],
    url: '/environments/{environment}/php/reset',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::resetPhp
* @see app/Http/Controllers/EnvironmentController.php:679
* @route '/environments/{environment}/php/reset'
*/
resetPhp.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return resetPhp.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::resetPhp
* @see app/Http/Controllers/EnvironmentController.php:679
* @route '/environments/{environment}/php/reset'
*/
resetPhp.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resetPhp.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::saveConfig
* @see app/Http/Controllers/EnvironmentController.php:707
* @route '/environments/{environment}/config'
*/
export const saveConfig = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: saveConfig.url(args, options),
    method: 'post',
})

saveConfig.definition = {
    methods: ["post"],
    url: '/environments/{environment}/config',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::saveConfig
* @see app/Http/Controllers/EnvironmentController.php:707
* @route '/environments/{environment}/config'
*/
saveConfig.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return saveConfig.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::saveConfig
* @see app/Http/Controllers/EnvironmentController.php:707
* @route '/environments/{environment}/config'
*/
saveConfig.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: saveConfig.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::getReverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
export const getReverbConfig = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getReverbConfig.url(args, options),
    method: 'get',
})

getReverbConfig.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/reverb-config',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::getReverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
getReverbConfig.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return getReverbConfig.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::getReverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
getReverbConfig.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getReverbConfig.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::getReverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
getReverbConfig.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getReverbConfig.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::unlinkWorktree
* @see app/Http/Controllers/EnvironmentController.php:829
* @route '/environments/{environment}/worktrees/unlink'
*/
export const unlinkWorktree = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unlinkWorktree.url(args, options),
    method: 'post',
})

unlinkWorktree.definition = {
    methods: ["post"],
    url: '/environments/{environment}/worktrees/unlink',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::unlinkWorktree
* @see app/Http/Controllers/EnvironmentController.php:829
* @route '/environments/{environment}/worktrees/unlink'
*/
unlinkWorktree.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return unlinkWorktree.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::unlinkWorktree
* @see app/Http/Controllers/EnvironmentController.php:829
* @route '/environments/{environment}/worktrees/unlink'
*/
unlinkWorktree.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unlinkWorktree.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::refreshWorktrees
* @see app/Http/Controllers/EnvironmentController.php:848
* @route '/environments/{environment}/worktrees/refresh'
*/
export const refreshWorktrees = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refreshWorktrees.url(args, options),
    method: 'post',
})

refreshWorktrees.definition = {
    methods: ["post"],
    url: '/environments/{environment}/worktrees/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::refreshWorktrees
* @see app/Http/Controllers/EnvironmentController.php:848
* @route '/environments/{environment}/worktrees/refresh'
*/
refreshWorktrees.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return refreshWorktrees.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::refreshWorktrees
* @see app/Http/Controllers/EnvironmentController.php:848
* @route '/environments/{environment}/worktrees/refresh'
*/
refreshWorktrees.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refreshWorktrees.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::createProject
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
*/
export const createProject = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: createProject.url(args, options),
    method: 'get',
})

createProject.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/projects/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::createProject
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
*/
createProject.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return createProject.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::createProject
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
*/
createProject.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: createProject.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::createProject
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
*/
createProject.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: createProject.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::storeProject
* @see app/Http/Controllers/EnvironmentController.php:873
* @route '/environments/{environment}/projects'
*/
export const storeProject = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeProject.url(args, options),
    method: 'post',
})

storeProject.definition = {
    methods: ["post"],
    url: '/environments/{environment}/projects',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::storeProject
* @see app/Http/Controllers/EnvironmentController.php:873
* @route '/environments/{environment}/projects'
*/
storeProject.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return storeProject.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::storeProject
* @see app/Http/Controllers/EnvironmentController.php:873
* @route '/environments/{environment}/projects'
*/
storeProject.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeProject.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::destroyProject
* @see app/Http/Controllers/EnvironmentController.php:959
* @route '/environments/{environment}/projects/{projectName}'
*/
export const destroyProject = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyProject.url(args, options),
    method: 'delete',
})

destroyProject.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/projects/{projectName}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::destroyProject
* @see app/Http/Controllers/EnvironmentController.php:959
* @route '/environments/{environment}/projects/{projectName}'
*/
destroyProject.url = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            projectName: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        projectName: args.projectName,
    }

    return destroyProject.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{projectName}', parsedArgs.projectName.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::destroyProject
* @see app/Http/Controllers/EnvironmentController.php:959
* @route '/environments/{environment}/projects/{projectName}'
*/
destroyProject.delete = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyProject.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\EnvironmentController::rebuildProject
* @see app/Http/Controllers/EnvironmentController.php:1008
* @route '/environments/{environment}/projects/{projectName}/rebuild'
*/
export const rebuildProject = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuildProject.url(args, options),
    method: 'post',
})

rebuildProject.definition = {
    methods: ["post"],
    url: '/environments/{environment}/projects/{projectName}/rebuild',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::rebuildProject
* @see app/Http/Controllers/EnvironmentController.php:1008
* @route '/environments/{environment}/projects/{projectName}/rebuild'
*/
rebuildProject.url = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            projectName: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        projectName: args.projectName,
    }

    return rebuildProject.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{projectName}', parsedArgs.projectName.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::rebuildProject
* @see app/Http/Controllers/EnvironmentController.php:1008
* @route '/environments/{environment}/projects/{projectName}/rebuild'
*/
rebuildProject.post = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuildProject.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::provisionStatus
* @see app/Http/Controllers/EnvironmentController.php:1018
* @route '/environments/{environment}/projects/{projectSlug}/provision-status'
*/
export const provisionStatus = (args: { environment: number | { id: number }, projectSlug: string | number } | [environment: number | { id: number }, projectSlug: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: provisionStatus.url(args, options),
    method: 'get',
})

provisionStatus.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/projects/{projectSlug}/provision-status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::provisionStatus
* @see app/Http/Controllers/EnvironmentController.php:1018
* @route '/environments/{environment}/projects/{projectSlug}/provision-status'
*/
provisionStatus.url = (args: { environment: number | { id: number }, projectSlug: string | number } | [environment: number | { id: number }, projectSlug: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            projectSlug: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        projectSlug: args.projectSlug,
    }

    return provisionStatus.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{projectSlug}', parsedArgs.projectSlug.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::provisionStatus
* @see app/Http/Controllers/EnvironmentController.php:1018
* @route '/environments/{environment}/projects/{projectSlug}/provision-status'
*/
provisionStatus.get = (args: { environment: number | { id: number }, projectSlug: string | number } | [environment: number | { id: number }, projectSlug: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: provisionStatus.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::provisionStatus
* @see app/Http/Controllers/EnvironmentController.php:1018
* @route '/environments/{environment}/projects/{projectSlug}/provision-status'
*/
provisionStatus.head = (args: { environment: number | { id: number }, projectSlug: string | number } | [environment: number | { id: number }, projectSlug: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: provisionStatus.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::templateDefaults
* @see app/Http/Controllers/EnvironmentController.php:1106
* @route '/environments/{environment}/template-defaults'
*/
export const templateDefaults = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: templateDefaults.url(args, options),
    method: 'post',
})

templateDefaults.definition = {
    methods: ["post"],
    url: '/environments/{environment}/template-defaults',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::templateDefaults
* @see app/Http/Controllers/EnvironmentController.php:1106
* @route '/environments/{environment}/template-defaults'
*/
templateDefaults.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return templateDefaults.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::templateDefaults
* @see app/Http/Controllers/EnvironmentController.php:1106
* @route '/environments/{environment}/template-defaults'
*/
templateDefaults.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: templateDefaults.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::githubUser
* @see app/Http/Controllers/EnvironmentController.php:1028
* @route '/environments/{environment}/github-user'
*/
export const githubUser = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubUser.url(args, options),
    method: 'get',
})

githubUser.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/github-user',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::githubUser
* @see app/Http/Controllers/EnvironmentController.php:1028
* @route '/environments/{environment}/github-user'
*/
githubUser.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return githubUser.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::githubUser
* @see app/Http/Controllers/EnvironmentController.php:1028
* @route '/environments/{environment}/github-user'
*/
githubUser.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubUser.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::githubUser
* @see app/Http/Controllers/EnvironmentController.php:1028
* @route '/environments/{environment}/github-user'
*/
githubUser.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: githubUser.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::githubOrgs
* @see app/Http/Controllers/EnvironmentController.php:1041
* @route '/environments/{environment}/github-orgs'
*/
export const githubOrgs = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubOrgs.url(args, options),
    method: 'get',
})

githubOrgs.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/github-orgs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::githubOrgs
* @see app/Http/Controllers/EnvironmentController.php:1041
* @route '/environments/{environment}/github-orgs'
*/
githubOrgs.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return githubOrgs.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::githubOrgs
* @see app/Http/Controllers/EnvironmentController.php:1041
* @route '/environments/{environment}/github-orgs'
*/
githubOrgs.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubOrgs.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::githubOrgs
* @see app/Http/Controllers/EnvironmentController.php:1041
* @route '/environments/{environment}/github-orgs'
*/
githubOrgs.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: githubOrgs.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::githubRepoExists
* @see app/Http/Controllers/EnvironmentController.php:1052
* @route '/environments/{environment}/github-repo-exists'
*/
export const githubRepoExists = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: githubRepoExists.url(args, options),
    method: 'post',
})

githubRepoExists.definition = {
    methods: ["post"],
    url: '/environments/{environment}/github-repo-exists',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::githubRepoExists
* @see app/Http/Controllers/EnvironmentController.php:1052
* @route '/environments/{environment}/github-repo-exists'
*/
githubRepoExists.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return githubRepoExists.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::githubRepoExists
* @see app/Http/Controllers/EnvironmentController.php:1052
* @route '/environments/{environment}/github-repo-exists'
*/
githubRepoExists.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: githubRepoExists.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::linearTeams
* @see app/Http/Controllers/EnvironmentController.php:1071
* @route '/environments/{environment}/linear-teams'
*/
export const linearTeams = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linearTeams.url(args, options),
    method: 'get',
})

linearTeams.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/linear-teams',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::linearTeams
* @see app/Http/Controllers/EnvironmentController.php:1071
* @route '/environments/{environment}/linear-teams'
*/
linearTeams.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return linearTeams.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::linearTeams
* @see app/Http/Controllers/EnvironmentController.php:1071
* @route '/environments/{environment}/linear-teams'
*/
linearTeams.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linearTeams.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::linearTeams
* @see app/Http/Controllers/EnvironmentController.php:1071
* @route '/environments/{environment}/linear-teams'
*/
linearTeams.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: linearTeams.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::workspaces
* @see app/Http/Controllers/EnvironmentController.php:1332
* @route '/environments/{environment}/workspaces'
*/
export const workspaces = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspaces.url(args, options),
    method: 'get',
})

workspaces.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/workspaces',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::workspaces
* @see app/Http/Controllers/EnvironmentController.php:1332
* @route '/environments/{environment}/workspaces'
*/
workspaces.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return workspaces.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::workspaces
* @see app/Http/Controllers/EnvironmentController.php:1332
* @route '/environments/{environment}/workspaces'
*/
workspaces.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspaces.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::workspaces
* @see app/Http/Controllers/EnvironmentController.php:1332
* @route '/environments/{environment}/workspaces'
*/
workspaces.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: workspaces.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::createWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
export const createWorkspace = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: createWorkspace.url(args, options),
    method: 'get',
})

createWorkspace.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/workspaces/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::createWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
createWorkspace.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return createWorkspace.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::createWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
createWorkspace.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: createWorkspace.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::createWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
createWorkspace.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: createWorkspace.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::storeWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1366
* @route '/environments/{environment}/workspaces'
*/
export const storeWorkspace = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeWorkspace.url(args, options),
    method: 'post',
})

storeWorkspace.definition = {
    methods: ["post"],
    url: '/environments/{environment}/workspaces',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::storeWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1366
* @route '/environments/{environment}/workspaces'
*/
storeWorkspace.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return storeWorkspace.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::storeWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1366
* @route '/environments/{environment}/workspaces'
*/
storeWorkspace.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeWorkspace.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::showWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
export const showWorkspace = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showWorkspace.url(args, options),
    method: 'get',
})

showWorkspace.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/workspaces/{workspace}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::showWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
showWorkspace.url = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            workspace: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        workspace: args.workspace,
    }

    return showWorkspace.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::showWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
showWorkspace.get = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showWorkspace.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::showWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
showWorkspace.head = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showWorkspace.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::destroyWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1425
* @route '/environments/{environment}/workspaces/{workspace}'
*/
export const destroyWorkspace = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyWorkspace.url(args, options),
    method: 'delete',
})

destroyWorkspace.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/workspaces/{workspace}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::destroyWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1425
* @route '/environments/{environment}/workspaces/{workspace}'
*/
destroyWorkspace.url = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            workspace: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        workspace: args.workspace,
    }

    return destroyWorkspace.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::destroyWorkspace
* @see app/Http/Controllers/EnvironmentController.php:1425
* @route '/environments/{environment}/workspaces/{workspace}'
*/
destroyWorkspace.delete = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyWorkspace.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\EnvironmentController::addWorkspaceProject
* @see app/Http/Controllers/EnvironmentController.php:1440
* @route '/environments/{environment}/workspaces/{workspace}/projects'
*/
export const addWorkspaceProject = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: addWorkspaceProject.url(args, options),
    method: 'post',
})

addWorkspaceProject.definition = {
    methods: ["post"],
    url: '/environments/{environment}/workspaces/{workspace}/projects',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::addWorkspaceProject
* @see app/Http/Controllers/EnvironmentController.php:1440
* @route '/environments/{environment}/workspaces/{workspace}/projects'
*/
addWorkspaceProject.url = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            workspace: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        workspace: args.workspace,
    }

    return addWorkspaceProject.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::addWorkspaceProject
* @see app/Http/Controllers/EnvironmentController.php:1440
* @route '/environments/{environment}/workspaces/{workspace}/projects'
*/
addWorkspaceProject.post = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: addWorkspaceProject.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::removeWorkspaceProject
* @see app/Http/Controllers/EnvironmentController.php:1464
* @route '/environments/{environment}/workspaces/{workspace}/projects/{project}'
*/
export const removeWorkspaceProject = (args: { environment: number | { id: number }, workspace: string | number, project: string | number } | [environment: number | { id: number }, workspace: string | number, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: removeWorkspaceProject.url(args, options),
    method: 'delete',
})

removeWorkspaceProject.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/workspaces/{workspace}/projects/{project}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::removeWorkspaceProject
* @see app/Http/Controllers/EnvironmentController.php:1464
* @route '/environments/{environment}/workspaces/{workspace}/projects/{project}'
*/
removeWorkspaceProject.url = (args: { environment: number | { id: number }, workspace: string | number, project: string | number } | [environment: number | { id: number }, workspace: string | number, project: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            workspace: args[1],
            project: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        workspace: args.workspace,
        project: args.project,
    }

    return removeWorkspaceProject.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace('{project}', parsedArgs.project.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::removeWorkspaceProject
* @see app/Http/Controllers/EnvironmentController.php:1464
* @route '/environments/{environment}/workspaces/{workspace}/projects/{project}'
*/
removeWorkspaceProject.delete = (args: { environment: number | { id: number }, workspace: string | number, project: string | number } | [environment: number | { id: number }, workspace: string | number, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: removeWorkspaceProject.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\EnvironmentController::linkedPackages
* @see app/Http/Controllers/EnvironmentController.php:1484
* @route '/environments/{environment}/projects/{project}/linked-packages'
*/
export const linkedPackages = (args: { environment: number | { id: number }, project: string | number } | [environment: number | { id: number }, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linkedPackages.url(args, options),
    method: 'get',
})

linkedPackages.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/projects/{project}/linked-packages',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::linkedPackages
* @see app/Http/Controllers/EnvironmentController.php:1484
* @route '/environments/{environment}/projects/{project}/linked-packages'
*/
linkedPackages.url = (args: { environment: number | { id: number }, project: string | number } | [environment: number | { id: number }, project: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            project: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        project: args.project,
    }

    return linkedPackages.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{project}', parsedArgs.project.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::linkedPackages
* @see app/Http/Controllers/EnvironmentController.php:1484
* @route '/environments/{environment}/projects/{project}/linked-packages'
*/
linkedPackages.get = (args: { environment: number | { id: number }, project: string | number } | [environment: number | { id: number }, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linkedPackages.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::linkedPackages
* @see app/Http/Controllers/EnvironmentController.php:1484
* @route '/environments/{environment}/projects/{project}/linked-packages'
*/
linkedPackages.head = (args: { environment: number | { id: number }, project: string | number } | [environment: number | { id: number }, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: linkedPackages.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::linkPackage
* @see app/Http/Controllers/EnvironmentController.php:1504
* @route '/environments/{environment}/projects/{project}/link-package'
*/
export const linkPackage = (args: { environment: number | { id: number }, project: string | number } | [environment: number | { id: number }, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: linkPackage.url(args, options),
    method: 'post',
})

linkPackage.definition = {
    methods: ["post"],
    url: '/environments/{environment}/projects/{project}/link-package',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::linkPackage
* @see app/Http/Controllers/EnvironmentController.php:1504
* @route '/environments/{environment}/projects/{project}/link-package'
*/
linkPackage.url = (args: { environment: number | { id: number }, project: string | number } | [environment: number | { id: number }, project: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            project: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        project: args.project,
    }

    return linkPackage.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{project}', parsedArgs.project.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::linkPackage
* @see app/Http/Controllers/EnvironmentController.php:1504
* @route '/environments/{environment}/projects/{project}/link-package'
*/
linkPackage.post = (args: { environment: number | { id: number }, project: string | number } | [environment: number | { id: number }, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: linkPackage.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::unlinkPackage
* @see app/Http/Controllers/EnvironmentController.php:1533
* @route '/environments/{environment}/projects/{project}/unlink-package/{package}'
*/
export const unlinkPackage = (args: { environment: number | { id: number }, project: string | number, package: string | number } | [environment: number | { id: number }, project: string | number, packageParam: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: unlinkPackage.url(args, options),
    method: 'delete',
})

unlinkPackage.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/projects/{project}/unlink-package/{package}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::unlinkPackage
* @see app/Http/Controllers/EnvironmentController.php:1533
* @route '/environments/{environment}/projects/{project}/unlink-package/{package}'
*/
unlinkPackage.url = (args: { environment: number | { id: number }, project: string | number, package: string | number } | [environment: number | { id: number }, project: string | number, packageParam: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            environment: args[0],
            project: args[1],
            package: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        environment: typeof args.environment === 'object'
        ? args.environment.id
        : args.environment,
        project: args.project,
        package: args.package,
    }

    return unlinkPackage.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{project}', parsedArgs.project.toString())
            .replace('{package}', parsedArgs.package.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::unlinkPackage
* @see app/Http/Controllers/EnvironmentController.php:1533
* @route '/environments/{environment}/projects/{project}/unlink-package/{package}'
*/
unlinkPackage.delete = (args: { environment: number | { id: number }, project: string | number, package: string | number } | [environment: number | { id: number }, project: string | number, packageParam: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: unlinkPackage.url(args, options),
    method: 'delete',
})

const EnvironmentController = { testConnection, status, sites, getConfig, worktrees, projectsApi, workspacesApi, workspaceApi, start, stop, restart, availableServices, startService, stopService, restartService, startHostService, stopHostService, restartHostService, serviceLogs, hostServiceLogs, enableService, disableService, configureService, serviceInfo, getPhpConfig, setPhpConfig, index, create, store, show, edit, update, destroy, getAllTlds, setDefault, runDoctor, quickCheck, fixDoctorIssue, projectsPage, servicesPage, orchestrator, enableOrchestrator, disableOrchestrator, installOrchestrator, detectOrchestrator, reconcileOrchestrator, orchestratorServices, orchestratorProjects, settings, updateSettings, changePhp, resetPhp, saveConfig, getReverbConfig, unlinkWorktree, refreshWorktrees, createProject, storeProject, destroyProject, rebuildProject, provisionStatus, templateDefaults, githubUser, githubOrgs, githubRepoExists, linearTeams, workspaces, createWorkspace, storeWorkspace, showWorkspace, destroyWorkspace, addWorkspaceProject, removeWorkspaceProject, linkedPackages, linkPackage, unlinkPackage }

export default EnvironmentController