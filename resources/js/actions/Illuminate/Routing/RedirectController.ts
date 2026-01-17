import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
const RedirectControllere6b3d9022f50a220178502a4e9232a20 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'get',
})

RedirectControllere6b3d9022f50a220178502a4e9232a20.definition = {
    methods: ["get","head","post","put","patch","delete","options"],
    url: '/servers',
} satisfies RouteDefinition<["get","head","post","put","patch","delete","options"]>

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.url = (options?: RouteQueryOptions) => {
    return RedirectControllere6b3d9022f50a220178502a4e9232a20.definition.url + queryParams(options)
}

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'head',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'post',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'put',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'patch',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'delete',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers'
*/
RedirectControllere6b3d9022f50a220178502a4e9232a20.options = (options?: RouteQueryOptions): RouteDefinition<'options'> => ({
    url: RedirectControllere6b3d9022f50a220178502a4e9232a20.url(options),
    method: 'options',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
const RedirectController3648c7fe8713278512884912ece167ff = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'get',
})

RedirectController3648c7fe8713278512884912ece167ff.definition = {
    methods: ["get","head","post","put","patch","delete","options"],
    url: '/servers/{id}',
} satisfies RouteDefinition<["get","head","post","put","patch","delete","options"]>

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { id: args }
    }

    if (Array.isArray(args)) {
        args = {
            id: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        id: args.id,
    }

    return RedirectController3648c7fe8713278512884912ece167ff.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'head',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.post = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'post',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.put = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'put',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.patch = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'patch',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.delete = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'delete',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/servers/{id}'
*/
RedirectController3648c7fe8713278512884912ece167ff.options = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'options'> => ({
    url: RedirectController3648c7fe8713278512884912ece167ff.url(args, options),
    method: 'options',
})

const RedirectController = {
    '/servers': RedirectControllere6b3d9022f50a220178502a4e9232a20,
    '/servers/{id}': RedirectController3648c7fe8713278512884912ece167ff,
}

export default RedirectController