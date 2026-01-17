import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
*/
export const create = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(args, options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/projects/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
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
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
*/
create.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::create
* @see app/Http/Controllers/EnvironmentController.php:858
* @route '/environments/{environment}/projects/create'
*/
create.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:873
* @route '/environments/{environment}/projects'
*/
export const store = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/environments/{environment}/projects',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::store
* @see app/Http/Controllers/EnvironmentController.php:873
* @route '/environments/{environment}/projects'
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
* @see app/Http/Controllers/EnvironmentController.php:873
* @route '/environments/{environment}/projects'
*/
store.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:959
* @route '/environments/{environment}/projects/{projectName}'
*/
export const destroy = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/environments/{environment}/projects/{projectName}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:959
* @route '/environments/{environment}/projects/{projectName}'
*/
destroy.url = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{projectName}', parsedArgs.projectName.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::destroy
* @see app/Http/Controllers/EnvironmentController.php:959
* @route '/environments/{environment}/projects/{projectName}'
*/
destroy.delete = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\EnvironmentController::rebuild
* @see app/Http/Controllers/EnvironmentController.php:1008
* @route '/environments/{environment}/projects/{projectName}/rebuild'
*/
export const rebuild = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuild.url(args, options),
    method: 'post',
})

rebuild.definition = {
    methods: ["post"],
    url: '/environments/{environment}/projects/{projectName}/rebuild',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::rebuild
* @see app/Http/Controllers/EnvironmentController.php:1008
* @route '/environments/{environment}/projects/{projectName}/rebuild'
*/
rebuild.url = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions) => {
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

    return rebuild.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace('{projectName}', parsedArgs.projectName.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::rebuild
* @see app/Http/Controllers/EnvironmentController.php:1008
* @route '/environments/{environment}/projects/{projectName}/rebuild'
*/
rebuild.post = (args: { environment: number | { id: number }, projectName: string | number } | [environment: number | { id: number }, projectName: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuild.url(args, options),
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

const projects = {
    create: Object.assign(create, create),
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
    rebuild: Object.assign(rebuild, rebuild),
    provisionStatus: Object.assign(provisionStatus, provisionStatus),
    linkedPackages: Object.assign(linkedPackages, linkedPackages),
    linkPackage: Object.assign(linkPackage, linkPackage),
    unlinkPackage: Object.assign(unlinkPackage, unlinkPackage),
}

export default projects