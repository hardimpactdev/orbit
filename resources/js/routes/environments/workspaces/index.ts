import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import projects from './projects'
/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
export const create = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(args, options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/workspaces/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
create.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return create.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
create.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:1356
* @route '/environments/{environment}/workspaces/create'
*/
create.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:1366
* @route '/environments/{environment}/workspaces'
*/
export const store = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/environments/{environment}/workspaces',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:1366
* @route '/environments/{environment}/workspaces'
*/
store.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:1366
* @route '/environments/{environment}/workspaces'
*/
store.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
export const show = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/workspaces/{workspace}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
show.url = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
show.get = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::show
* @see app/Http/Controllers/EnvironmentController.php:1385
* @route '/environments/{environment}/workspaces/{workspace}'
*/
show.head = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:1425
* @route '/environments/{environment}/workspaces/{workspace}'
*/
export const destroy = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/workspaces/{workspace}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:1425
* @route '/environments/{environment}/workspaces/{workspace}'
*/
destroy.url = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:1425
* @route '/environments/{environment}/workspaces/{workspace}'
*/
destroy.delete = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const workspaces = {
    create: Object.assign(create, create),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    destroy: Object.assign(destroy, destroy),
    projects: Object.assign(projects, projects),
}

export default workspaces