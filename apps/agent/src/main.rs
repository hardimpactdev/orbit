fn main() {
    orbit_agent::install_launchd_safe_path();
    orbit_agent::run_agent_service_blocking();
}
