import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\SshKeyController::store
* @see app/Http/Controllers/SshKeyController.php:11
* @route '/ssh-keys'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/ssh-keys',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SshKeyController::store
* @see app/Http/Controllers/SshKeyController.php:11
* @route '/ssh-keys'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SshKeyController::store
* @see app/Http/Controllers/SshKeyController.php:11
* @route '/ssh-keys'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SshKeyController::update
* @see app/Http/Controllers/SshKeyController.php:33
* @route '/ssh-keys/{sshKey}'
*/
export const update = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/ssh-keys/{sshKey}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\SshKeyController::update
* @see app/Http/Controllers/SshKeyController.php:33
* @route '/ssh-keys/{sshKey}'
*/
update.url = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { sshKey: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { sshKey: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            sshKey: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        sshKey: typeof args.sshKey === 'object'
        ? args.sshKey.id
        : args.sshKey,
    }

    return update.definition.url
            .replace('{sshKey}', parsedArgs.sshKey.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SshKeyController::update
* @see app/Http/Controllers/SshKeyController.php:33
* @route '/ssh-keys/{sshKey}'
*/
update.put = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\SshKeyController::destroy
* @see app/Http/Controllers/SshKeyController.php:53
* @route '/ssh-keys/{sshKey}'
*/
export const destroy = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/ssh-keys/{sshKey}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\SshKeyController::destroy
* @see app/Http/Controllers/SshKeyController.php:53
* @route '/ssh-keys/{sshKey}'
*/
destroy.url = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { sshKey: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { sshKey: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            sshKey: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        sshKey: typeof args.sshKey === 'object'
        ? args.sshKey.id
        : args.sshKey,
    }

    return destroy.definition.url
            .replace('{sshKey}', parsedArgs.sshKey.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SshKeyController::destroy
* @see app/Http/Controllers/SshKeyController.php:53
* @route '/ssh-keys/{sshKey}'
*/
destroy.delete = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\SshKeyController::defaultMethod
* @see app/Http/Controllers/SshKeyController.php:70
* @route '/ssh-keys/{sshKey}/default'
*/
export const defaultMethod = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: defaultMethod.url(args, options),
    method: 'post',
})

defaultMethod.definition = {
    methods: ["post"],
    url: '/ssh-keys/{sshKey}/default',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SshKeyController::defaultMethod
* @see app/Http/Controllers/SshKeyController.php:70
* @route '/ssh-keys/{sshKey}/default'
*/
defaultMethod.url = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { sshKey: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { sshKey: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            sshKey: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        sshKey: typeof args.sshKey === 'object'
        ? args.sshKey.id
        : args.sshKey,
    }

    return defaultMethod.definition.url
            .replace('{sshKey}', parsedArgs.sshKey.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SshKeyController::defaultMethod
* @see app/Http/Controllers/SshKeyController.php:70
* @route '/ssh-keys/{sshKey}/default'
*/
defaultMethod.post = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: defaultMethod.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SshKeyController::available
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
export const available = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: available.url(options),
    method: 'get',
})

available.definition = {
    methods: ["get","head"],
    url: '/ssh-keys/available',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SshKeyController::available
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
available.url = (options?: RouteQueryOptions) => {
    return available.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SshKeyController::available
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
available.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: available.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SshKeyController::available
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
available.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: available.url(options),
    method: 'head',
})

const sshKeys = {
    store: Object.assign(store, store),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    default: Object.assign(defaultMethod, defaultMethod),
    available: Object.assign(available, available),
}

export default sshKeys