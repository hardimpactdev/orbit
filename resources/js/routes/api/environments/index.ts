import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\EnvironmentController::tlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
export const tlds = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tlds.url(options),
    method: 'get',
})

tlds.definition = {
    methods: ["get","head"],
    url: '/api/environments/tlds',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\EnvironmentController::tlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
tlds.url = (options?: RouteQueryOptions) => {
    return tlds.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\EnvironmentController::tlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
tlds.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tlds.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\EnvironmentController::tlds
* @see app/Http/Controllers/EnvironmentController.php:793
* @route '/api/environments/tlds'
*/
tlds.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: tlds.url(options),
    method: 'head',
})

const environments = {
    tlds: Object.assign(tlds, tlds),
}

export default environments