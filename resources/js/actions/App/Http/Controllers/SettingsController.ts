import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
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
* @see \App\Http\Controllers\SettingsController::toggleNotifications
* @see app/Http/Controllers/SettingsController.php:129
* @route '/settings/notifications'
*/
export const toggleNotifications = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggleNotifications.url(options),
    method: 'post',
})

toggleNotifications.definition = {
    methods: ["post"],
    url: '/settings/notifications',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::toggleNotifications
* @see app/Http/Controllers/SettingsController.php:129
* @route '/settings/notifications'
*/
toggleNotifications.url = (options?: RouteQueryOptions) => {
    return toggleNotifications.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::toggleNotifications
* @see app/Http/Controllers/SettingsController.php:129
* @route '/settings/notifications'
*/
toggleNotifications.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggleNotifications.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::toggleMenuBar
* @see app/Http/Controllers/SettingsController.php:145
* @route '/settings/menu-bar'
*/
export const toggleMenuBar = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggleMenuBar.url(options),
    method: 'post',
})

toggleMenuBar.definition = {
    methods: ["post"],
    url: '/settings/menu-bar',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::toggleMenuBar
* @see app/Http/Controllers/SettingsController.php:145
* @route '/settings/menu-bar'
*/
toggleMenuBar.url = (options?: RouteQueryOptions) => {
    return toggleMenuBar.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::toggleMenuBar
* @see app/Http/Controllers/SettingsController.php:145
* @route '/settings/menu-bar'
*/
toggleMenuBar.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggleMenuBar.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::cliStatus
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
export const cliStatus = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: cliStatus.url(options),
    method: 'get',
})

cliStatus.definition = {
    methods: ["get","head"],
    url: '/cli/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SettingsController::cliStatus
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
cliStatus.url = (options?: RouteQueryOptions) => {
    return cliStatus.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::cliStatus
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
cliStatus.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: cliStatus.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SettingsController::cliStatus
* @see app/Http/Controllers/SettingsController.php:66
* @route '/cli/status'
*/
cliStatus.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: cliStatus.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\SettingsController::cliInstall
* @see app/Http/Controllers/SettingsController.php:71
* @route '/cli/install'
*/
export const cliInstall = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cliInstall.url(options),
    method: 'post',
})

cliInstall.definition = {
    methods: ["post"],
    url: '/cli/install',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::cliInstall
* @see app/Http/Controllers/SettingsController.php:71
* @route '/cli/install'
*/
cliInstall.url = (options?: RouteQueryOptions) => {
    return cliInstall.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::cliInstall
* @see app/Http/Controllers/SettingsController.php:71
* @route '/cli/install'
*/
cliInstall.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cliInstall.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::cliUpdate
* @see app/Http/Controllers/SettingsController.php:78
* @route '/cli/update'
*/
export const cliUpdate = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cliUpdate.url(options),
    method: 'post',
})

cliUpdate.definition = {
    methods: ["post"],
    url: '/cli/update',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::cliUpdate
* @see app/Http/Controllers/SettingsController.php:78
* @route '/cli/update'
*/
cliUpdate.url = (options?: RouteQueryOptions) => {
    return cliUpdate.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::cliUpdate
* @see app/Http/Controllers/SettingsController.php:78
* @route '/cli/update'
*/
cliUpdate.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cliUpdate.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::storeTemplate
* @see app/Http/Controllers/SettingsController.php:85
* @route '/template-favorites'
*/
export const storeTemplate = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeTemplate.url(options),
    method: 'post',
})

storeTemplate.definition = {
    methods: ["post"],
    url: '/template-favorites',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SettingsController::storeTemplate
* @see app/Http/Controllers/SettingsController.php:85
* @route '/template-favorites'
*/
storeTemplate.url = (options?: RouteQueryOptions) => {
    return storeTemplate.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::storeTemplate
* @see app/Http/Controllers/SettingsController.php:85
* @route '/template-favorites'
*/
storeTemplate.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeTemplate.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SettingsController::updateTemplate
* @see app/Http/Controllers/SettingsController.php:107
* @route '/template-favorites/{template}'
*/
export const updateTemplate = (args: { template: number | { id: number } } | [template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateTemplate.url(args, options),
    method: 'put',
})

updateTemplate.definition = {
    methods: ["put"],
    url: '/template-favorites/{template}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\SettingsController::updateTemplate
* @see app/Http/Controllers/SettingsController.php:107
* @route '/template-favorites/{template}'
*/
updateTemplate.url = (args: { template: number | { id: number } } | [template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { template: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { template: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            template: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        template: typeof args.template === 'object'
        ? args.template.id
        : args.template,
    }

    return updateTemplate.definition.url
            .replace('{template}', parsedArgs.template.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::updateTemplate
* @see app/Http/Controllers/SettingsController.php:107
* @route '/template-favorites/{template}'
*/
updateTemplate.put = (args: { template: number | { id: number } } | [template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateTemplate.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\SettingsController::destroyTemplate
* @see app/Http/Controllers/SettingsController.php:121
* @route '/template-favorites/{template}'
*/
export const destroyTemplate = (args: { template: number | { id: number } } | [template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyTemplate.url(args, options),
    method: 'delete',
})

destroyTemplate.definition = {
    methods: ["delete"],
    url: '/template-favorites/{template}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\SettingsController::destroyTemplate
* @see app/Http/Controllers/SettingsController.php:121
* @route '/template-favorites/{template}'
*/
destroyTemplate.url = (args: { template: number | { id: number } } | [template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { template: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { template: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            template: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        template: typeof args.template === 'object'
        ? args.template.id
        : args.template,
    }

    return destroyTemplate.definition.url
            .replace('{template}', parsedArgs.template.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SettingsController::destroyTemplate
* @see app/Http/Controllers/SettingsController.php:121
* @route '/template-favorites/{template}'
*/
destroyTemplate.delete = (args: { template: number | { id: number } } | [template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyTemplate.url(args, options),
    method: 'delete',
})

const SettingsController = { index, update, toggleNotifications, toggleMenuBar, cliStatus, cliInstall, cliUpdate, storeTemplate, updateTemplate, destroyTemplate }

export default SettingsController