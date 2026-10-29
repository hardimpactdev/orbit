//! The compiled set of commands and the contract the Gateway reads from it.

use super::{
    identity::{Platform, Privilege},
    Command, CommandError, Context, Outcome, COMMON_ERRORS,
};
use orbit_agent_contract::{Registrar, Service, StartHook};
use serde_json::{json, Value};
use std::{sync::OnceLock, time::Duration};

/// One command with its types erased, so the registry can hold them together.
pub struct Entry {
    pub name: &'static str,
    pub summary: &'static str,
    pub mutates: bool,
    pub timeout: Duration,
    pub output_max_bytes: u64,
    pub input_max_bytes: u64,
    pub privilege: fn(Platform) -> Option<Privilege>,
    pub errors: &'static [&'static str],
    pub run: fn(Value, &Context) -> Outcome,
    /// The JSON Schemas of the input and the output.
    pub schemas: fn() -> Value,
    /// The shared resources a mutation locks after its target.
    pub resources: &'static [&'static str],
    /// Decodes an input without running the command.
    pub parse: fn(Value) -> Result<(), String>,
}

fn parse<C: Command>(input: Value) -> Result<(), String> {
    let (input, _) = super::effects::split::<C>(input)
        .map_err(|_| "A valid effect_key is required.".to_string())?;
    serde_json::from_value::<C::Input>(input)
        .map(|_| ())
        .map_err(|error| error.to_string())
}

fn schemas<C: Command>() -> Value {
    let mut input =
        serde_json::to_value(schemars::schema_for!(C::Input)).expect("schema serializes");
    if C::KEYED_EFFECT {
        fn add(schema: &mut Value) {
            if let Some(properties) = schema.get_mut("properties").and_then(Value::as_object_mut) {
                properties.insert("effect_key".into(), json!({"type": "string", "pattern": "^op-[1-9][0-9]*\\.[a-z0-9_.:-]+$", "maxLength": 192}));
            }
            for key in ["oneOf", "anyOf", "allOf"] {
                if let Some(branches) = schema.get_mut(key).and_then(Value::as_array_mut) {
                    for branch in branches {
                        add(branch);
                    }
                }
            }
        }
        add(&mut input);
    }

    json!({"input": input, "output": schemars::schema_for!(C::Output)})
}

pub fn entry<C: Command>() -> Entry {
    assert!(
        !C::KEYED_EFFECT || C::MUTATES,
        "keyed effects require a mutation"
    );
    assert!(
        !C::MUTATES || C::OUTPUT_MAX_BYTES == 0,
        "binary output is read-only"
    );
    assert!(
        C::MUTATES || C::RESOURCES.is_empty(),
        "{}: only a mutation locks shared resources",
        C::NAME
    );
    Entry {
        name: C::NAME,
        summary: C::SUMMARY,
        mutates: C::MUTATES,
        timeout: C::TIMEOUT,
        output_max_bytes: C::OUTPUT_MAX_BYTES,
        input_max_bytes: C::INPUT_MAX_BYTES,
        privilege: C::privilege,
        errors: <C::Error as CommandError>::CODES,
        run: run::<C>,
        schemas: schemas::<C>,
        resources: C::RESOURCES,
        parse: parse::<C>,
    }
}

fn run<C: Command>(input: Value, context: &Context) -> Outcome {
    let (input, key) = match super::effects::split::<C>(input) {
        Ok(input) => input,
        Err(error) => return error,
    };
    let raw = input.clone();
    let input: C::Input = match serde_json::from_value(input) {
        Ok(input) => input,
        Err(error) => {
            return Outcome::refused(
                "agent.invalid_input",
                "The input does not match the command.",
                json!({"reason": error.to_string()}),
            )
        }
    };
    with_locks::<C>(input, context, super::lock::lock_directory, |input| {
        super::effects::execute::<C>(key, raw, context, || invoke::<C>(input, context))
    })
}

/// Takes the target and resource locks of a mutation in `directory`, then runs it.
fn with_locks<C: Command>(
    input: C::Input,
    context: &Context,
    directory: fn() -> Result<std::path::PathBuf, String>,
    effect: impl FnOnce(C::Input) -> Outcome,
) -> Outcome {
    let target = C::target(&input);
    let _locks = if C::MUTATES && (target.is_some() || !C::RESOURCES.is_empty()) {
        match directory()
            .map_err(|reason| {
                super::lock::LockError::Io(super::lock::LOCK_ROOT_LINUX.into(), reason)
            })
            .and_then(|directory| {
                super::lock::acquire_all(
                    &directory,
                    target.as_deref(),
                    C::RESOURCES,
                    super::lock::answer_by(context.deadline()),
                )
            }) {
            Ok(held) => held,
            Err(super::lock::LockError::Busy(path)) => {
                return Outcome::refused(
                    "agent.target_busy",
                    "Another command holds the lock of this target.",
                    json!({"lock": path}),
                )
            }
            Err(super::lock::LockError::Io(path, reason)) => {
                return Outcome::refused(
                    "agent.lock_unavailable",
                    "The lock of this target cannot be opened.",
                    json!({"lock": path, "reason": reason}),
                )
            }
        }
    } else {
        Vec::new()
    };
    effect(input)
}

fn invoke<C: Command>(input: C::Input, context: &Context) -> Outcome {
    match C::run(input, context) {
        Ok(output) => Outcome::Ok(serde_json::to_value(output).expect("an output serializes")),
        Err(error) => Outcome::refused(error.code(), error.message(), error.details()),
    }
}

/// Startup behavior and its filenames belong to the compiled contributor.
pub struct HookEntry {
    pub command: &'static str,
    pub name: &'static str,
    pub drop_in: &'static str,
    pub description: &'static str,
    pub validate: fn(Value) -> Result<(), Outcome>,
    pub run: fn(Value, &Context) -> Outcome,
}
fn validate_hook<H: StartHook>(input: Value) -> Result<(), Outcome> {
    let input = serde_json::from_value(input).map_err(|_| {
        Outcome::refused(
            "agent.invalid_input",
            "The startup input does not match the command.",
            json!({}),
        )
    })?;
    if H::target(&input).as_deref() != Some(orbit_agent_contract::START_HOOK_TARGET) {
        return Err(Outcome::refused(
            "agent.invalid_input",
            "Startup hooks must use the shared startup target.",
            json!({}),
        ));
    }
    H::validate(&input).map_err(|e| Outcome::refused(e.code(), e.message(), e.details()))
}
fn run_hook<H: StartHook>(input: Value, context: &Context) -> Outcome {
    let context = Context::new(
        context.platform,
        context
            .deadline()
            .min(std::time::Instant::now() + H::TIMEOUT),
    );
    match serde_json::from_value(input) {
        Ok(input) => invoke::<H>(input, &context),
        Err(_) => Outcome::refused(
            "agent.invalid_input",
            "The startup input does not match the command.",
            json!({}),
        ),
    }
}
struct Registry {
    commands: Vec<Entry>,
    hooks: Vec<HookEntry>,
    services: Vec<ServiceEntry>,
}
pub struct ServiceEntry {
    pub name: &'static str,
    pub supported: fn(Platform) -> bool,
    pub run: fn(&[String]) -> i32,
}
impl Registrar for Registry {
    fn service<S: Service>(&mut self) {
        self.services.push(ServiceEntry {
            name: S::NAME,
            supported: S::supported,
            run: S::run,
        });
    }
    fn command<C: Command>(&mut self) {
        self.commands.push(entry::<C>());
    }
    fn start_hook<H: StartHook>(&mut self) {
        assert!(
            H::MUTATES
                && H::RESOURCES.is_empty()
                && H::privilege(Platform::Linux) == Some(Privilege::Root)
                && H::privilege(Platform::Macos).is_none(),
            "startup hooks must be Linux root mutations"
        );
        self.command::<H>();
        self.hooks.push(HookEntry {
            command: H::NAME,
            name: H::FILE_NAME,
            drop_in: H::DROP_IN,
            description: H::DESCRIPTION,
            validate: validate_hook::<H>,
            run: run_hook::<H>,
        });
    }
}
/// One composition call per extension. Core knows only the shared contracts.
fn registry() -> &'static Registry {
    static REGISTRY: OnceLock<Registry> = OnceLock::new();
    REGISTRY.get_or_init(|| {
        let mut registry = Registry {
            commands: crate::commands::all(),
            hooks: Vec::new(),
            services: Vec::new(),
        };
        registry.service::<crate::commands::task_topology::Worker>();
        registry.service::<crate::commands::plausible::Plausible>();
        orbit_documents_agent::register(&mut registry);
        registry.service::<crate::commands::node_worker::renewal::Service>();
        orbit_ai_router_agent::register(&mut registry);
        registry
    })
}
pub fn all() -> &'static [Entry] {
    &registry().commands
}
pub fn hooks() -> &'static [HookEntry] {
    &registry().hooks
}
pub fn services() -> &'static [ServiceEntry] {
    &registry().services
}

pub fn service(arguments: &[String], platform: Platform) -> Option<i32> {
    dispatch_service(services(), arguments, platform)
}
fn dispatch_service(
    entries: &[ServiceEntry],
    arguments: &[String],
    platform: Platform,
) -> Option<i32> {
    let name = arguments.first()?;
    let entry = entries.iter().find(|entry| entry.name == name)?;
    Some(if (entry.supported)(platform) {
        (entry.run)(&arguments[1..])
    } else {
        eprintln!("This service is not supported on this platform.");
        1
    })
}

pub fn find(name: &str) -> Option<&'static Entry> {
    all().iter().find(|entry| entry.name == name)
}

/// The contract the Gateway and its tests read: each command's privilege, bound, and error codes.
pub fn contract() -> Value {
    let commands: Vec<Value> = all()
        .iter()
        .map(|entry| {
            let mut definition = json!({
                "name": entry.name,
                "summary": entry.summary,
                "mutates": entry.mutates,
                "timeout_seconds": entry.timeout.as_secs(),
                "privilege": {
                    "linux": (entry.privilege)(Platform::Linux),
                    "macos": (entry.privilege)(Platform::Macos),
                },
                "errors": entry.errors,
            });
            if entry.output_max_bytes > 0 {
                definition["output_max_bytes"] = entry.output_max_bytes.into();
            }
            if entry.input_max_bytes > 0 {
                definition["input_max_bytes"] = entry.input_max_bytes.into();
            }
            definition
        })
        .collect();
    json!({"common_errors": COMMON_ERRORS, "not_started_errors": super::NOT_STARTED_ERRORS, "preflight_errors": super::PREFLIGHT_ERRORS, "commands": commands, "output_chunk_bytes": super::binary_output::CHUNK_BYTES})
}

/// The input and output JSON Schema of every command. Gateway test fakes validate against them.
pub fn schemas_document() -> Value {
    let commands: serde_json::Map<String, Value> = all()
        .iter()
        .map(|entry| (entry.name.to_owned(), (entry.schemas)()))
        .collect();
    json!({"commands": commands})
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::BTreeSet;

    #[test]
    fn compiled_services_dispatch_only_on_their_declared_platform() {
        struct Example;
        impl Service for Example {
            const NAME: &'static str = "example-service";
            fn supported(platform: Platform) -> bool {
                platform == Platform::Linux
            }
            fn run(arguments: &[String]) -> i32 {
                assert_eq!(arguments, ["argument"]);
                42
            }
        }
        let mut registry = Registry {
            commands: Vec::new(),
            hooks: Vec::new(),
            services: Vec::new(),
        };
        registry.service::<Example>();
        let arguments = ["example-service".into(), "argument".into()];
        assert_eq!(
            dispatch_service(&registry.services, &arguments, Platform::Linux),
            Some(42)
        );
        assert_eq!(
            dispatch_service(&registry.services, &arguments, Platform::Macos),
            Some(1)
        );
        assert_eq!(
            dispatch_service(&registry.services, &["unknown".into()], Platform::Linux),
            None
        );
        assert_eq!(
            dispatch_service(&registry.services, &[], Platform::Linux),
            None
        );
        let mut names = BTreeSet::new();
        for service in services() {
            assert!(names.insert(service.name));
            assert!(service
                .name
                .bytes()
                .all(|b| b.is_ascii_lowercase() || b == b'-'));
        }
    }

    /// A mutation that declares only shared resources still holds them.
    struct ResourceOnly;
    impl Command for ResourceOnly {
        const NAME: &'static str = "test.resource-only";
        const SUMMARY: &'static str = "Test";
        const MUTATES: bool = true;
        const TIMEOUT: Duration = Duration::from_secs(5);
        const RESOURCES: &'static [&'static str] = &["packages"];
        type Input = Value;
        type Output = Value;
        type Error = crate::commands::agent::StatusError;
        fn privilege(_: Platform) -> Option<Privilege> {
            Some(Privilege::Account)
        }
        fn run(_: Value, _: &Context) -> Result<Value, Self::Error> {
            Ok(json!({"ran": true}))
        }
    }
    fn resource_locks() -> Result<std::path::PathBuf, String> {
        static DIRECTORY: OnceLock<std::path::PathBuf> = OnceLock::new();
        Ok(DIRECTORY
            .get_or_init(|| {
                let directory = std::env::temp_dir().join(format!(
                    "orbit-agent-registry-{}-{}",
                    std::process::id(),
                    rand::random::<u64>()
                ));
                std::fs::create_dir_all(&directory).unwrap();
                directory
            })
            .clone())
    }

    #[test]
    fn a_resource_only_mutation_holds_its_resources_and_reports_busy_before_its_deadline() {
        let directory = resource_locks().unwrap();
        let held = super::super::lock::acquire(
            &super::super::lock::path_for(&directory, "packages"),
            std::time::Instant::now(),
        )
        .unwrap();
        let deadline = std::time::Instant::now() + Duration::from_secs(2);
        let context = Context::new(Platform::Linux, deadline);
        match with_locks::<ResourceOnly>(json!({}), &context, resource_locks, |input| {
            invoke::<ResourceOnly>(input, &context)
        }) {
            Outcome::Refused { code, .. } => assert_eq!(code, "agent.target_busy"),
            other => panic!("expected a busy refusal, got {other:?}"),
        }
        assert!(std::time::Instant::now() < deadline);
        drop(held);
        let context = Context::new(
            Platform::Linux,
            std::time::Instant::now() + Duration::from_secs(2),
        );
        match with_locks::<ResourceOnly>(json!({}), &context, resource_locks, |input| {
            invoke::<ResourceOnly>(input, &context)
        }) {
            Outcome::Ok(_) => {}
            other => panic!("expected the released resource to be taken, got {other:?}"),
        }
    }

    #[test]
    fn a_prestart_lock_refusal_leaves_no_intent_and_the_same_key_runs_once_after_release() {
        struct Keyed;
        impl Command for Keyed {
            const NAME: &'static str = "test.keyed";
            const SUMMARY: &'static str = "Test";
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(5);
            const RESOURCES: &'static [&'static str] = &["keyed-resource"];
            type Input = Value;
            type Output = Value;
            type Error = crate::commands::agent::StatusError;
            fn privilege(_: Platform) -> Option<Privilege> {
                Some(Privilege::Account)
            }
            fn target(_: &Value) -> Option<String> {
                Some("keyed-target".into())
            }
            fn run(_: Value, _: &Context) -> Result<Value, Self::Error> {
                unreachable!()
            }
        }
        let directory = resource_locks().unwrap();
        let receipt_directory = directory.join("receipts");
        let store = super::super::effects::Store::at(receipt_directory.clone());
        let calls = std::sync::atomic::AtomicUsize::new(0);
        for (index, lock) in ["keyed-target", "keyed-resource"].iter().enumerate() {
            let key = format!("op-42.lock-{index}");
            let ctx = || {
                Context::new(
                    Platform::Linux,
                    std::time::Instant::now() + Duration::from_millis(350),
                )
                .with_effect_masks(vec![])
                .with_effect_identity(
                    "11111111-1111-4111-8111-111111111111".into(),
                    time::OffsetDateTime::now_utc().unix_timestamp(),
                )
            };
            let attempt = |context: &Context| {
                with_locks::<Keyed>(json!({}), context, resource_locks, |_| {
                    store.execute("test.keyed", &key, "input", context, || {
                        calls.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
                        Outcome::Ok(json!({"ran":true}))
                    })
                })
            };
            let held = super::super::lock::acquire(
                &super::super::lock::path_for(&directory, lock),
                std::time::Instant::now(),
            )
            .unwrap();
            assert!(
                matches!(attempt(&ctx()), Outcome::Refused { code, .. } if code == "agent.target_busy")
            );
            assert_eq!(calls.load(std::sync::atomic::Ordering::SeqCst), index);
            assert_eq!(
                store
                    .receipt("test.keyed", &key)
                    .unwrap_or_else(|_| panic!("receipt"))
                    .state,
                "unknown"
            );
            drop(held);
            assert!(matches!(attempt(&ctx()), Outcome::Ok(_)));
            assert!(matches!(attempt(&ctx()), Outcome::Ok(_)));
            assert_eq!(calls.load(std::sync::atomic::Ordering::SeqCst), index + 1);
        }
        std::fs::remove_dir_all(receipt_directory).unwrap();
    }

    /// Namespaces whose last word ends in one `s` but is not a plural: the mass nouns `analytics` and `metrics`, the
    /// product `incus`, and the extension slug `documents`, because an extension's commands use its slug.
    const NOT_PLURAL: &[&str] = &["analytics", "documents", "incus", "metrics"];

    /// A namespace names one thing, as the CLI does: `instance.write-environment`, `instance.release_invalid`.
    fn assert_singular(namespace: &str, name: &str) {
        let word = namespace.rsplit(['-', '_']).next().unwrap_or(namespace);
        assert!(
            !word.ends_with('s') || word.ends_with("ss") || NOT_PLURAL.contains(&namespace),
            "{name}: the namespace {namespace} is plural"
        );
    }

    #[test]
    fn names_are_unique_and_in_singular_namespaces() {
        let mut seen = BTreeSet::new();
        for entry in all() {
            assert!(seen.insert(entry.name), "duplicate command {}", entry.name);
            let (area, action) = entry.name.split_once('.').expect("area.action");
            assert!(!area.is_empty() && !action.is_empty(), "{}", entry.name);
            assert_singular(area, entry.name);
            assert!(
                entry
                    .name
                    .bytes()
                    .all(|byte| byte.is_ascii_lowercase() || b".-".contains(&byte)),
                "{}",
                entry.name
            );
        }
    }

    #[test]
    fn command_errors_have_singular_namespaces_and_never_repeat_a_common_error() {
        for entry in all() {
            for code in entry.errors {
                let (namespace, _) = code
                    .split_once('.')
                    .unwrap_or_else(|| panic!("{}: {code}", entry.name));
                assert_singular(namespace, code);
                assert!(!COMMON_ERRORS.contains(code), "{}: {code}", entry.name);
            }
        }
    }

    #[test]
    fn every_command_is_bounded_and_runs_on_at_least_one_platform() {
        for entry in all() {
            assert!(entry.timeout > Duration::ZERO, "{}", entry.name);
            assert!(
                [Platform::Linux, Platform::Macos]
                    .into_iter()
                    .any(|platform| (entry.privilege)(platform).is_some()),
                "{}",
                entry.name
            );
        }
    }

    /// Commands that change one shared host resource hold its lock, so they serialize with each other.
    #[test]
    fn shared_host_mutations_hold_one_lock_per_resource() {
        let packages = [
            "caddy.install-package",
            "docker.prepare-migration",
            "docker.restore-migration-policies",
            "gateway.converge-release-units",
            "image.clean",
            "metrics.cadvisor",
            "metrics.node-exporter",
            "node.bootstrap",
            "php.configure-runtime",
            "php.install-build",
            "recipe.apply",
            "recipe.install-javascript",
            "role.install-prerequisites",
            "tool.apt-install",
            "tool.apt-remove",
            "tool.apt-update",
            "tool.materialize-composer",
            "tool.materialize-homebrew",
        ];
        let firewall = [
            "firewall.gateway-vpn",
            "firewall.route-lan",
            "firewall.router-lan",
            "firewall.rule",
            "firewall.rules",
            "firewall.task-vm-hub",
            "image.firewall",
            "incus.prepare-host",
            "metrics.cadvisor",
            "metrics.node-exporter",
            "metrics.publication-firewall",
            "metrics.service-runtime",
        ];
        for (resource, names) in [("packages", &packages[..]), ("firewall", &firewall[..])] {
            for name in names {
                let entry = find(name).unwrap_or_else(|| panic!("{name} is not registered"));
                assert!(
                    entry.mutates && entry.resources.contains(&resource),
                    "{name} must lock {resource}"
                );
            }
        }
        for entry in all() {
            assert!(
                !entry.resources.contains(&"apt"),
                "{} uses the old apt lock",
                entry.name
            );
        }
    }

    /// Both `.env` writers run as root and lock the same key, so they serialize in one lock directory.
    #[test]
    fn both_environment_writers_share_a_privilege_and_a_lock() {
        use crate::commands::{environment::WriteEnvironment, laravel::WriteUrl};
        let directory = "/home/orbit/apps/shop";
        let url = serde_json::from_value(json!({
            "directory": directory, "owner": "orbit", "url": "https://shop.test", "app_key": "", "app_key_stored": false,
            "app_name": "Shop", "app_name_stored": false, "require_artisan": true,
        }))
        .unwrap();
        let environment = serde_json::from_value(json!({
            "mode": "write", "base": directory, "user": "orbit", "maximum": 65536, "require_home": false,
        }))
        .unwrap();
        assert_eq!(
            WriteUrl::privilege(Platform::Linux),
            WriteEnvironment::privilege(Platform::Linux)
        );
        assert_eq!(
            WriteUrl::target(&url),
            WriteEnvironment::target(&environment)
        );
        assert!(WriteUrl::target(&url).is_some());
    }

    /// Each Valkey volume has its own ACL lock.
    #[test]
    fn each_valkey_volume_has_its_own_acl_lock() {
        use crate::commands::valkey_acl::WriteValkeyAcl;
        let acl = |volume: &str| {
            WriteValkeyAcl::target(
                &serde_json::from_value(
                    json!({"volume": volume, "image": "valkey/valkey:8", "acl": ""}),
                )
                .unwrap(),
            )
        };
        assert_eq!(acl("one").as_deref(), Some("valkey-acl:one"));
        assert_ne!(acl("one"), acl("two"));
    }

    /// Every input object refuses unknown fields, so a misspelled field fails instead of being ignored.
    #[test]
    fn every_input_object_refuses_unknown_fields() {
        fn walk(
            schema: &Value,
            root: &Value,
            path: &str,
            seen: &mut BTreeSet<String>,
            loose: &mut Vec<String>,
        ) {
            let Some(object) = schema.as_object() else {
                return;
            };
            if let Some(reference) = object.get("$ref").and_then(Value::as_str) {
                let name = reference.trim_start_matches("#/$defs/");
                if seen.insert(name.to_owned()) {
                    walk(
                        &root["$defs"][name],
                        root,
                        &format!("{path}/{name}"),
                        seen,
                        loose,
                    );
                }
            }
            if object.contains_key("properties")
                && object.get("additionalProperties") != Some(&Value::Bool(false))
            {
                loose.push(path.to_owned());
            }
            for (name, child) in object
                .get("properties")
                .and_then(Value::as_object)
                .into_iter()
                .flatten()
            {
                walk(child, root, &format!("{path}.{name}"), seen, loose);
            }
            for key in ["oneOf", "anyOf", "allOf"] {
                for child in object
                    .get(key)
                    .and_then(Value::as_array)
                    .into_iter()
                    .flatten()
                {
                    walk(child, root, path, seen, loose);
                }
            }
            for key in ["items", "additionalProperties"] {
                if let Some(child) = object.get(key) {
                    walk(child, root, &format!("{path}[]"), seen, loose);
                }
            }
        }
        let mut loose = Vec::new();
        for entry in all() {
            let input = &(entry.schemas)()["input"];
            walk(input, input, entry.name, &mut BTreeSet::new(), &mut loose);
        }
        assert!(
            loose.is_empty(),
            "inputs that accept unknown fields: {loose:?}"
        );
    }

    /// Values a schema accepts, each with variants that add one unknown field to one struct object inside it. The
    /// first pair has only the required fields; the others set one field, required or optional, to each of its own
    /// values, so every `oneOf`/`anyOf` branch at every depth gets a probe. Maps take any key, so they get none.
    fn samples(schema: &Value, root: &Value, depth: usize) -> Vec<(Value, Vec<Value>)> {
        let leaf = |value: Value| vec![(value, Vec::new())];
        let Some(object) = schema.as_object() else {
            return leaf(Value::Null);
        };
        if depth > 12 {
            return Vec::new();
        }
        if let Some(reference) = object.get("$ref").and_then(Value::as_str) {
            return samples(
                &root["$defs"][reference.trim_start_matches("#/$defs/")],
                root,
                depth + 1,
            );
        }
        for key in ["oneOf", "anyOf"] {
            if let Some(branches) = object.get(key).and_then(Value::as_array) {
                return branches
                    .iter()
                    .flat_map(|branch| samples(branch, root, depth + 1))
                    .collect();
            }
        }
        if let Some(constant) = object.get("const") {
            return leaf(constant.clone());
        }
        if let Some(first) = object
            .get("enum")
            .and_then(Value::as_array)
            .and_then(|e| e.first())
        {
            return leaf(first.clone());
        }
        let types: Vec<&str> = match object.get("type") {
            Some(Value::String(kind)) => vec![kind.as_str()],
            Some(Value::Array(kinds)) => kinds.iter().filter_map(Value::as_str).collect(),
            _ => vec![],
        };
        match types
            .iter()
            .copied()
            .find(|kind| *kind != "null")
            .unwrap_or("null")
        {
            "object" => {
                let properties = object
                    .get("properties")
                    .and_then(Value::as_object)
                    .cloned()
                    .unwrap_or_default();
                let mut base = serde_json::Map::new();
                for name in object
                    .get("required")
                    .and_then(Value::as_array)
                    .into_iter()
                    .flatten()
                    .filter_map(Value::as_str)
                {
                    let Some((sample, _)) = samples(&properties[name], root, depth + 1)
                        .into_iter()
                        .next()
                    else {
                        return Vec::new();
                    };
                    base.insert(name.to_owned(), sample);
                }
                let closed = !matches!(
                    object.get("additionalProperties"),
                    Some(Value::Object(_) | Value::Bool(true))
                );
                let mut extended = base.clone();
                extended.insert("orbit_unknown_field".into(), json!(1));
                let mut pairs = vec![(
                    Value::Object(base.clone()),
                    if closed {
                        vec![Value::Object(extended)]
                    } else {
                        Vec::new()
                    },
                )];
                for (name, child) in &properties {
                    for (sample, variants) in samples(child, root, depth + 1) {
                        let with = |value: Value| {
                            let mut copy = base.clone();
                            copy.insert(name.clone(), value);
                            Value::Object(copy)
                        };
                        pairs.push((with(sample), variants.into_iter().map(with).collect()));
                    }
                }
                pairs
            }
            "array" => {
                let mut pairs = vec![(json!([]), Vec::new())];
                if let Some(items) = object.get("items") {
                    for (sample, variants) in samples(items, root, depth + 1) {
                        pairs.push((
                            json!([sample]),
                            variants.into_iter().map(|v| json!([v])).collect(),
                        ));
                    }
                }
                pairs
            }
            "string" => leaf(json!(match object.get("format").and_then(Value::as_str) {
                Some("ipv4" | "ip") => "10.0.0.1",
                Some("socket-address") => "10.0.0.1:1",
                _ => "x",
            })),
            "integer" | "number" => leaf(json!(0)),
            "boolean" => leaf(json!(false)),
            _ => leaf(Value::Null),
        }
    }

    /// serde ignores `deny_unknown_fields` in some shapes, such as a unit variant of an internally tagged enum, while
    /// the schema still says closed. So every command's real decoder must refuse an unknown field at every level.
    #[test]
    fn every_command_decoder_refuses_unknown_fields() {
        let mut probes = 0;
        let mut accepted = Vec::new();
        let mut unprobed = Vec::new();
        for entry in all() {
            let input = &(entry.schemas)()["input"];
            let mut probed = 0;
            let mut struct_inputs = false;
            for (sample, variants) in samples(input, input, 0) {
                struct_inputs |= !variants.is_empty();
                if (entry.parse)(sample).is_err() {
                    continue;
                }
                for changed in variants {
                    probed += 1;
                    if (entry.parse)(changed.clone()).is_ok() {
                        accepted.push(format!("{}: {changed}", entry.name));
                    }
                }
            }
            if struct_inputs && probed == 0 {
                unprobed.push(entry.name);
            }
            probes += probed;
        }
        assert!(
            accepted.is_empty(),
            "decoders that accept an unknown field: {accepted:#?}"
        );
        assert_eq!(
            unprobed,
            Vec::<&str>::new(),
            "commands that no generated input reaches"
        );
        assert!(probes > 400, "only {probes} probes ran");
    }

    /// Gateway tests read `contract/schemas.json`. The same variable rewrites it.
    #[test]
    fn schemas_file_matches_the_compiled_commands() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/contract/schemas.json");
        let expected = serde_json::to_string(&schemas_document()).unwrap() + "\n";
        if std::env::var_os("ORBIT_AGENT_WRITE_CONTRACT").is_some() {
            std::fs::write(path, &expected).unwrap();
        }
        let actual = std::fs::read_to_string(path).unwrap_or_default();
        assert!(
            actual == expected,
            "contract/schemas.json is stale; run ORBIT_AGENT_WRITE_CONTRACT=1 cargo test --lib -- contract_file schemas_file"
        );
    }

    /// The Gateway and its tests read `contract/commands.json`. Rewrite it with
    /// `ORBIT_AGENT_WRITE_CONTRACT=1 cargo test --lib -- contract_file`.
    #[test]
    fn contract_file_matches_the_compiled_commands() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/contract/commands.json");
        let expected = serde_json::to_string_pretty(&contract()).unwrap() + "\n";
        if std::env::var_os("ORBIT_AGENT_WRITE_CONTRACT").is_some() {
            std::fs::write(path, &expected).unwrap();
        }
        let actual = std::fs::read_to_string(path).unwrap_or_default();
        assert_eq!(
            actual, expected,
            "contract/commands.json is stale; run ORBIT_AGENT_WRITE_CONTRACT=1 cargo test --lib -- contract_file"
        );
    }
}
