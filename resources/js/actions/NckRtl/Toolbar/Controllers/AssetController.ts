import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
const AssetController = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AssetController.url(args, options),
    method: 'get',
})

AssetController.definition = {
    methods: ["get","head"],
    url: '/_toolbar/{asset}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
AssetController.url = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { asset: args }
    }

    if (Array.isArray(args)) {
        args = {
            asset: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        asset: args.asset,
    }

    return AssetController.definition.url
            .replace('{asset}', parsedArgs.asset.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
AssetController.get = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AssetController.url(args, options),
    method: 'get',
})

/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
AssetController.head = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AssetController.url(args, options),
    method: 'head',
})

export default AssetController