import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import doctorB93ea9 from './doctor'
import projects46b84a from './projects'
import services11ad26 from './services'
import orchestrator73e4f1 from './orchestrator'
import settings69f00b from './settings'
import php55d7df from './php'
import hostServices from './host-services'
import config from './config'
import worktrees from './worktrees'
import workspaces8282b9 from './workspaces'
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
* @see \App\Http\Controllers\EnvironmentController::doctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
export const doctor = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: doctor.url(args, options),
    method: 'get',
})

doctor.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/doctor',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::doctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
doctor.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return doctor.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::doctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
doctor.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: doctor.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::doctor
* @see app/Http/Controllers/EnvironmentController.php:1553
* @route '/environments/{environment}/doctor'
*/
doctor.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: doctor.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projects
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
*/
export const projects = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projects.url(args, options),
    method: 'get',
})

projects.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/projects',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::projects
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
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
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
*/
projects.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: projects.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::projects
* @see app/Http/Controllers/EnvironmentController.php:251
* @route '/environments/{environment}/projects'
*/
projects.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: projects.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\EnvironmentController::services
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
*/
export const services = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: services.url(args, options),
    method: 'get',
})

services.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/services',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::services
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
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
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
*/
services.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: services.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::services
* @see app/Http/Controllers/EnvironmentController.php:266
* @route '/environments/{environment}/services'
*/
services.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: services.url(args, options),
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
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/environments/{environment}/start'
*/
export const start = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

start.definition = {
    methods: ["post"],
    url: '/environments/{environment}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/environments/{environment}/start'
*/
start.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return start.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::start
* @see app/Http/Controllers/EnvironmentController.php:511
* @route '/environments/{environment}/start'
*/
start.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/environments/{environment}/stop'
*/
export const stop = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/environments/{environment}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/environments/{environment}/stop'
*/
stop.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return stop.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::stop
* @see app/Http/Controllers/EnvironmentController.php:519
* @route '/environments/{environment}/stop'
*/
stop.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/environments/{environment}/restart'
*/
export const restart = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restart.url(args, options),
    method: 'post',
})

restart.definition = {
    methods: ["post"],
    url: '/environments/{environment}/restart',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/environments/{environment}/restart'
*/
restart.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return restart.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::restart
* @see app/Http/Controllers/EnvironmentController.php:527
* @route '/environments/{environment}/restart'
*/
restart.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restart.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::php
* @see app/Http/Controllers/EnvironmentController.php:667
* @route '/environments/{environment}/php'
*/
export const php = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: php.url(args, options),
    method: 'post',
})

php.definition = {
    methods: ["post"],
    url: '/environments/{environment}/php',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EnvironmentController::php
* @see app/Http/Controllers/EnvironmentController.php:667
* @route '/environments/{environment}/php'
*/
php.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return php.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::php
* @see app/Http/Controllers/EnvironmentController.php:667
* @route '/environments/{environment}/php'
*/
php.post = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: php.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\EnvironmentController::reverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
export const reverbConfig = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: reverbConfig.url(args, options),
    method: 'get',
})

reverbConfig.definition = {
    methods: ["get","head"],
    url: '/environments/{environment}/reverb-config',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::reverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
reverbConfig.url = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return reverbConfig.definition.url
            .replace('{environment}', parsedArgs.environment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::reverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
reverbConfig.get = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: reverbConfig.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::reverbConfig
* @see app/Http/Controllers/EnvironmentController.php:700
* @route '/environments/{environment}/reverb-config'
*/
reverbConfig.head = (args: { environment: number | { id: number } } | [environment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: reverbConfig.url(args, options),
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

const environments = {
    index: Object.assign(index, index),
    create: Object.assign(create, create),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    edit: Object.assign(edit, edit),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    setDefault: Object.assign(setDefault, setDefault),
    doctor: Object.assign(doctor, doctorB93ea9),
    projects: Object.assign(projects, projects46b84a),
    services: Object.assign(services, services11ad26),
    orchestrator: Object.assign(orchestrator, orchestrator73e4f1),
    settings: Object.assign(settings, settings69f00b),
    start: Object.assign(start, start),
    stop: Object.assign(stop, stop),
    restart: Object.assign(restart, restart),
    php: Object.assign(php, php55d7df),
    hostServices: Object.assign(hostServices, hostServices),
    config: Object.assign(config, config),
    reverbConfig: Object.assign(reverbConfig, reverbConfig),
    worktrees: Object.assign(worktrees, worktrees),
    templateDefaults: Object.assign(templateDefaults, templateDefaults),
    githubUser: Object.assign(githubUser, githubUser),
    githubOrgs: Object.assign(githubOrgs, githubOrgs),
    githubRepoExists: Object.assign(githubRepoExists, githubRepoExists),
    linearTeams: Object.assign(linearTeams, linearTeams),
    workspaces: Object.assign(workspaces, workspaces8282b9),
}

export default environments