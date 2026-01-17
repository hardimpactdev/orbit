import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\SettingsController::status
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
export const status = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/cli/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SettingsController::status
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
status.url = (options?: RouteQueryOptions) => {
    return status.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::status
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
status.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SettingsController::status
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
status.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\SettingsController::install
* @see app/Http/Controllers/SettingsController.php:71
* @route '/cli/install'
*/
export const install = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: install.url(options),
    method: 'post',
})

install.definition = {
    methods: ["post"],
    url: '/cli/install',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::install
* @see app/Http/Controllers/SettingsController.php:71
* @route '/cli/install'
*/
install.url = (options?: RouteQueryOptions) => {
    return install.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::install
* @see app/Http/Controllers/SettingsController.php:71
* @route '/cli/install'
*/
install.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: install.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::update
* @see app/Http/Controllers/SettingsController.php:78
* @route '/cli/update'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: update.url(options),
    method: 'post',
})

update.definition = {
    methods: ["post"],
    url: '/cli/update',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::update
* @see app/Http/Controllers/SettingsController.php:78
* @route '/cli/update'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::update
* @see app/Http/Controllers/SettingsController.php:78
* @route '/cli/update'
*/
update.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: update.url(options),
    method: 'post',
})

const cli = {
    status: Object.assign(status, status),
    install: Object.assign(install, install),
    update: Object.assign(update, update),
}

export default cli