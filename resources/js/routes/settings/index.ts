import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\SettingsController::index
* @see app/Http/Controllers/SettingsController.php:22
* @route '/settings'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SettingsController::index
* @see app/Http/Controllers/SettingsController.php:22
* @route '/settings'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::index
* @see app/Http/Controllers/SettingsController.php:22
* @route '/settings'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SettingsController::index
* @see app/Http/Controllers/SettingsController.php:22
* @route '/settings'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\SettingsController::update
* @see app/Http/Controllers/SettingsController.php:49
* @route '/settings'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: update.url(options),
    method: 'post',
})

update.definition = {
    methods: ["post"],
    url: '/settings',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::update
* @see app/Http/Controllers/SettingsController.php:49
* @route '/settings'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::update
* @see app/Http/Controllers/SettingsController.php:49
* @route '/settings'
*/
update.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: update.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::notifications
* @see app/Http/Controllers/SettingsController.php:129
* @route '/settings/notifications'
*/
export const notifications = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: notifications.url(options),
    method: 'post',
})

notifications.definition = {
    methods: ["post"],
    url: '/settings/notifications',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::notifications
* @see app/Http/Controllers/SettingsController.php:129
* @route '/settings/notifications'
*/
notifications.url = (options?: RouteQueryOptions) => {
    return notifications.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::notifications
* @see app/Http/Controllers/SettingsController.php:129
* @route '/settings/notifications'
*/
notifications.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: notifications.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::menuBar
* @see app/Http/Controllers/SettingsController.php:145
* @route '/settings/menu-bar'
*/
export const menuBar = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: menuBar.url(options),
    method: 'post',
})

menuBar.definition = {
    methods: ["post"],
    url: '/settings/menu-bar',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::menuBar
* @see app/Http/Controllers/SettingsController.php:145
* @route '/settings/menu-bar'
*/
menuBar.url = (options?: RouteQueryOptions) => {
    return menuBar.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::menuBar
* @see app/Http/Controllers/SettingsController.php:145
* @route '/settings/menu-bar'
*/
menuBar.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: menuBar.url(options),
    method: 'post',
})

const settings = {
    index: Object.assign(index, index),
    update: Object.assign(update, update),
    notifications: Object.assign(notifications, notifications),
    menuBar: Object.assign(menuBar, menuBar),
}

export default settings