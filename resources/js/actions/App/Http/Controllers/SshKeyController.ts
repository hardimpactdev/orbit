import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
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
* @see \App\Http\Controllers\SshKeyController::setDefault
* @see app/Http/Controllers/SshKeyController.php:70
* @route '/ssh-keys/{sshKey}/default'
*/
export const setDefault = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setDefault.url(args, options),
    method: 'post',
})

setDefault.definition = {
    methods: ["post"],
    url: '/ssh-keys/{sshKey}/default',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SshKeyController::setDefault
* @see app/Http/Controllers/SshKeyController.php:70
* @route '/ssh-keys/{sshKey}/default'
*/
setDefault.url = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return setDefault.definition.url
            .replace('{sshKey}', parsedArgs.sshKey.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SshKeyController::setDefault
* @see app/Http/Controllers/SshKeyController.php:70
* @route '/ssh-keys/{sshKey}/default'
*/
setDefault.post = (args: { sshKey: number | { id: number } } | [sshKey: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setDefault.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SshKeyController::getAvailableKeys
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
export const getAvailableKeys = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getAvailableKeys.url(options),
    method: 'get',
})

getAvailableKeys.definition = {
    methods: ["get","head"],
    url: '/ssh-keys/available',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SshKeyController::getAvailableKeys
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
getAvailableKeys.url = (options?: RouteQueryOptions) => {
    return getAvailableKeys.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SshKeyController::getAvailableKeys
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
getAvailableKeys.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getAvailableKeys.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SshKeyController::getAvailableKeys
* @see app/Http/Controllers/SshKeyController.php:78
* @route '/ssh-keys/available'
*/
getAvailableKeys.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getAvailableKeys.url(options),
    method: 'head',
})

const SshKeyController = { store, update, destroy, setDefault, getAvailableKeys }

export default SshKeyController