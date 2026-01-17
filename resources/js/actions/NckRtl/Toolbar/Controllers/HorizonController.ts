import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::status
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:12
* @route '/_toolbar/horizon/status'
*/
export const status = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/_toolbar/horizon/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::status
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:12
* @route '/_toolbar/horizon/status'
*/
status.url = (options?: RouteQueryOptions) => {
    return status.definition.url + queryParams(options)
}

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::status
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:12
* @route '/_toolbar/horizon/status'
*/
status.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::status
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:12
* @route '/_toolbar/horizon/status'
*/
status.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(options),
    method: 'head',
})

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::start
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:28
* @route '/_toolbar/horizon/start'
*/
export const start = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(options),
    method: 'post',
})

start.definition = {
    methods: ["post"],
    url: '/_toolbar/horizon/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::start
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:28
* @route '/_toolbar/horizon/start'
*/
start.url = (options?: RouteQueryOptions) => {
    return start.definition.url + queryParams(options)
}

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::start
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:28
* @route '/_toolbar/horizon/start'
*/
start.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(options),
    method: 'post',
})

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::stop
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:68
* @route '/_toolbar/horizon/stop'
*/
export const stop = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/_toolbar/horizon/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::stop
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:68
* @route '/_toolbar/horizon/stop'
*/
stop.url = (options?: RouteQueryOptions) => {
    return stop.definition.url + queryParams(options)
}

/**
* @see \NckRtl\Toolbar\Controllers\HorizonController::stop
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/HorizonController.php:68
* @route '/_toolbar/horizon/stop'
*/
stop.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(options),
    method: 'post',
})

const HorizonController = { status, start, stop }

export default HorizonController