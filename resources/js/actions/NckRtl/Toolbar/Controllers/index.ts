import AssetController from './AssetController'
import HorizonController from './HorizonController'

const Controllers = {
    AssetController: Object.assign(AssetController, AssetController),
    HorizonController: Object.assign(HorizonController, HorizonController),
}

export default Controllers