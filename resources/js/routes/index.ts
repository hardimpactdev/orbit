import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../wayfinder'
/**
* @see \App\Http\Controllers\DashboardController::dashboard
* @see app/Http/Controllers/DashboardController.php:10
* @route '/'
*/
export const dashboard = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dashboard.url(options),
    method: 'get',
})

dashboard.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\DashboardController::dashboard
* @see app/Http/Controllers/DashboardController.php:10
* @route '/'
*/
dashboard.url = (options?: RouteQueryOptions) => {
    return dashboard.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\DashboardController::dashboard
* @see app/Http/Controllers/DashboardController.php:10
* @route '/'
*/
dashboard.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dashboard.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\DashboardController::dashboard
* @see app/Http/Controllers/DashboardController.php:10
* @route '/'
*/
dashboard.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: dashboard.url(options),
    method: 'head',
})

/**
* @see routes/web.php:119
* @route '/open-external'
*/
export const openExternal = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: openExternal.url(options),
    method: 'post',
})

openExternal.definition = {
    methods: ["post"],
    url: '/open-external',
} satisfies RouteDefinition<["post"]>

/**
* @see routes/web.php:119
* @route '/open-external'
*/
openExternal.url = (options?: RouteQueryOptions) => {
    return openExternal.definition.url + queryParams(options)
}

/**
* @see routes/web.php:119
* @route '/open-external'
*/
openExternal.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: openExternal.url(options),
    method: 'post',
})

/**
* @see routes/web.php:131
* @route '/open-terminal'
*/
export const openTerminal = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: openTerminal.url(options),
    method: 'post',
})

openTerminal.definition = {
    methods: ["post"],
    url: '/open-terminal',
} satisfies RouteDefinition<["post"]>

/**
* @see routes/web.php:131
* @route '/open-terminal'
*/
openTerminal.url = (options?: RouteQueryOptions) => {
    return openTerminal.definition.url + queryParams(options)
}

/**
* @see routes/web.php:131
* @route '/open-terminal'
*/
openTerminal.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: openTerminal.url(options),
    method: 'post',
})

