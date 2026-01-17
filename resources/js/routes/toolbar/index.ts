import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
export const assets = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: assets.url(args, options),
    method: 'get',
})

assets.definition = {
    methods: ["get","head"],
    url: '/_toolbar/{asset}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
assets.url = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return assets.definition.url
            .replace('{asset}', parsedArgs.asset.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
assets.get = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: assets.url(args, options),
    method: 'get',
})

/**
* @see \NckRtl\Toolbar\Controllers\AssetController::__invoke
* @see vendor/nckrtl/laravel-toolbar/src/Controllers/AssetController.php:13
* @route '/_toolbar/{asset}'
*/
assets.head = (args: { asset: string | number } | [asset: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: assets.url(args, options),
    method: 'head',
})

const toolbar = {
    assets: Object.assign(assets, assets),
}

export default toolbar