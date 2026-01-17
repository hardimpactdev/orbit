import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::add
* @see app/Http/Controllers/EnvironmentController.php:1440
* @route '/environments/{environment}/workspaces/{workspace}/projects'
*/
export const add = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: add.url(args, options),
    method: 'post',
})

add.definition = {
    methods: ["post"],
    url: '/environments/{environment}/workspaces/{workspace}/projects',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::add
* @see app/Http/Controllers/EnvironmentController.php:1440
* @route '/environments/{environment}/workspaces/{workspace}/projects'
*/
add.url = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions) => {
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

    return add.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::add
* @see app/Http/Controllers/EnvironmentController.php:1440
* @route '/environments/{environment}/workspaces/{workspace}/projects'
*/
add.post = (args: { environment: number | { id: number }, workspace: string | number } | [environment: number | { id: number }, workspace: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: add.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::remove
* @see app/Http/Controllers/EnvironmentController.php:1464
* @route '/environments/{environment}/workspaces/{workspace}/projects/{project}'
*/
export const remove = (args: { environment: number | { id: number }, workspace: string | number, project: string | number } | [environment: number | { id: number }, workspace: string | number, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: remove.url(args, options),
    method: 'delete',
})

remove.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/workspaces/{workspace}/projects/{project}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::remove
* @see app/Http/Controllers/EnvironmentController.php:1464
* @route '/environments/{environment}/workspaces/{workspace}/projects/{project}'
*/
remove.url = (args: { environment: number | { id: number }, workspace: string | number, project: string | number } | [environment: number | { id: number }, workspace: string | number, project: string | number ], options?: RouteQueryOptions) => {
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

    return remove.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace('{project}', parsedArgs.project.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::remove
* @see app/Http/Controllers/EnvironmentController.php:1464
* @route '/environments/{environment}/workspaces/{workspace}/projects/{project}'
*/
remove.delete = (args: { environment: number | { id: number }, workspace: string | number, project: string | number } | [environment: number | { id: number }, workspace: string | number, project: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: remove.url(args, options),
    method: 'delete',
})

const projects = {
    add: Object.assign(add, add),
    remove: Object.assign(remove, remove),
}

export default projects