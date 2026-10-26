//! AI router Node behavior, composed into the pinned agent binary.
mod collector;
mod grpc;
mod quota;
mod service;
mod snapshot;
mod source;
mod valkey;

pub fn register(registrar: &mut impl orbit_agent_contract::Registrar) {
    registrar.command::<source::RemoveSource>();
    registrar.service::<service::CollectorService>();
}

#[cfg(test)]
mod tests {
    use orbit_agent_contract::{Command, Platform, Registrar, Service, StartHook};
    #[test]
    fn composes_the_fixed_collector_service_and_source_removal_command() {
        #[derive(Default)]
        struct Contributions {
            commands: Vec<&'static str>,
            services: Vec<&'static str>,
        }
        impl Registrar for Contributions {
            fn command<C: Command>(&mut self) {
                self.commands.push(C::NAME);
            }
            fn start_hook<H: StartHook>(&mut self) {
                panic!("No startup hook is contributed");
            }
            fn service<S: Service>(&mut self) {
                assert!(S::supported(Platform::Linux));
                assert!(!S::supported(Platform::Macos));
                assert_eq!(S::run(&["unexpected".into()]), 1);
                self.services.push(S::NAME);
            }
        }
        let mut contributions = Contributions::default();
        super::register(&mut contributions);
        assert_eq!(contributions.commands, ["ai-router.remove-source"]);
        assert_eq!(contributions.services, ["ai-router-collector"]);
    }
}
