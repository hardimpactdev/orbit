import EnvironmentController from './EnvironmentController'
import DashboardController from './DashboardController'
import SettingsController from './SettingsController'
import SshKeyController from './SshKeyController'
import ProvisioningController from './ProvisioningController'

const Controllers = {
    EnvironmentController: Object.assign(EnvironmentController, EnvironmentController),
    DashboardController: Object.assign(DashboardController, DashboardController),
    SettingsController: Object.assign(SettingsController, SettingsController),
    SshKeyController: Object.assign(SshKeyController, SshKeyController),
    ProvisioningController: Object.assign(ProvisioningController, ProvisioningController),
}

export default Controllers