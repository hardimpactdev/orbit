//! Stable Rust contracts shared by the command runner and compiled extension crates.
pub mod identity;

use serde::de::DeserializeOwned;
use serde_json::{Map, Value};
use std::time::{Duration, Instant};

use serde::Serialize;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, schemars::JsonSchema)]
#[serde(rename_all = "lowercase")]
pub enum Platform {
    Linux,
    Macos,
}
impl Platform {
    pub fn current() -> Self {
        if cfg!(target_os = "macos") {
            Self::Macos
        } else {
            Self::Linux
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "lowercase")]
pub enum Privilege {
    /// The managed account on Linux, the enrolled account on macOS.
    Account,
    /// Starts as root on Linux (`sudo -n`) and drops to the recorded owner before project code.
    Owner,
    /// Root through `sudo -n` on Linux.
    Root,
}
impl Privilege {
    /// Whether an effective user ID fits this privilege on this platform.
    pub fn accepts(self, platform: Platform, euid: u32) -> bool {
        match (platform, self) {
            (Platform::Linux, Self::Owner | Self::Root) => euid == 0,
            (_, Self::Account | Self::Owner) => euid != 0,
            (Platform::Macos, Self::Root) => false,
        }
    }
}

/// One named Node action with a typed contract.
pub trait Command {
    const NAME: &'static str;
    const SUMMARY: &'static str;
    /// A mutation runs under its own supervisor and takes the target and resource locks it declares; a read takes no
    /// lock.
    const MUTATES: bool;
    /// Persist one receipt per effect key; an unfinished intent must never execute again.
    const KEYED_EFFECT: bool = Self::MUTATES;
    /// The longest the command may run. The envelope's remaining deadline can shorten it.
    const TIMEOUT: Duration;
    /// A read may emit framed binary bytes before its result. Zero means JSON output only.
    const OUTPUT_MAX_BYTES: u64 = 0;
    /// Maximum raw bytes after the envelope line. Zero means JSON input only.
    const INPUT_MAX_BYTES: u64 = 0;
    /// The input and output shapes are exported as JSON Schemas in the contract, so Gateway tests send and expect
    /// only what the agent accepts and returns.
    type Input: DeserializeOwned + schemars::JsonSchema;
    type Output: Serialize + schemars::JsonSchema;
    type Error: CommandError;

    /// The identity the command runs as on each platform, or `None` where it is unsupported.
    fn privilege(platform: Platform) -> Option<Privilege>;

    /// The canonical target a mutation locks exclusively, such as a checkout path. Reads take no lock.
    fn target(_input: &Self::Input) -> Option<String> {
        None
    }

    /// The shared resources a mutation changes, locked after its target in sorted order.
    const RESOURCES: &'static [&'static str] = &[];
    fn run(input: Self::Input, context: &Context) -> Result<Self::Output, Self::Error>;
}

/// A declared, typed refusal or failure of one command.
pub trait CommandError {
    /// Every code this error type can return.
    const CODES: &'static [&'static str];
    fn code(&self) -> &'static str;
    fn message(&self) -> String;
    fn details(&self) -> Value {
        Value::Object(Map::new())
    }
}

/// What a running command knows about its call.
pub struct Context {
    pub platform: Platform,
    deadline: Instant,
    binary_input: std::sync::Mutex<Option<std::fs::File>>,
    effect_masks: Option<Vec<String>>,
    effect_boolean_masks: Vec<bool>,
    effect_identity: Option<(String, i64)>,
}
impl Context {
    pub fn new(platform: Platform, deadline: Instant) -> Self {
        Self {
            platform,
            deadline,
            binary_input: std::sync::Mutex::new(None),
            effect_masks: None,
            effect_boolean_masks: Vec::new(),
            effect_identity: None,
        }
    }
    /// Protected invocation metadata. Never included in command input, fingerprints, or receipts.
    pub fn with_effect_masks(mut self, masks: Vec<String>) -> Self {
        self.effect_masks = Some(masks);
        self
    }
    pub fn effect_masks(&self) -> Option<&[String]> {
        self.effect_masks.as_deref()
    }
    /// Typed boolean masks cannot be inferred from string or numeric masks.
    pub fn with_effect_boolean_masks(mut self, masks: Vec<bool>) -> Self {
        self.effect_boolean_masks = masks;
        self
    }
    pub fn effect_boolean_masks(&self) -> &[bool] {
        &self.effect_boolean_masks
    }
    /// Original Gateway Operation identity, independent of reused numeric database IDs.
    pub fn with_effect_identity(mut self, nonce: String, created_at: i64) -> Self {
        self.effect_identity = Some((nonce, created_at));
        self
    }
    pub fn effect_identity(&self) -> Option<(&str, i64)> {
        self.effect_identity
            .as_ref()
            .map(|(nonce, created_at)| (nonce.as_str(), *created_at))
    }
    /// The dispatcher supplies a complete, size-checked private input file.
    pub fn with_binary_input(self, file: std::fs::File) -> Self {
        *self.binary_input.lock().expect("binary input lock") = Some(file);
        self
    }
    /// A binary consumer takes its input once. No pathname crosses the command boundary.
    pub fn take_binary_input(&self) -> Option<std::fs::File> {
        self.binary_input.lock().ok()?.take()
    }
    /// The receipt layer hashes the bounded input through positional reads before execution.
    pub fn clone_binary_input(&self) -> std::io::Result<Option<std::fs::File>> {
        self.binary_input
            .lock()
            .map_err(|_| std::io::Error::other("binary input lock"))?
            .as_ref()
            .map(std::fs::File::try_clone)
            .transpose()
    }
    pub fn deadline(&self) -> Instant {
        self.deadline
    }
    /// The time left, never less than zero.
    pub fn remaining(&self) -> Duration {
        self.deadline.saturating_duration_since(Instant::now())
    }
}

/// The shared target held by publication and each registered startup command.
pub const START_HOOK_TARGET: &str = "gateway-start-hooks";

/// A compiled PHP-FPM startup action. Validation is read-only and precedes publication.
pub trait StartHook: Command {
    const FILE_NAME: &'static str;
    const DROP_IN: &'static str;
    const DESCRIPTION: &'static str;
    fn validate(input: &Self::Input) -> Result<(), Self::Error>;
}

/// A compiled service supervised by an existing Process or fixed systemd unit.
pub trait Service {
    const NAME: &'static str;
    fn supported(platform: Platform) -> bool;
    fn run(arguments: &[String]) -> i32;
}

/// The composition boundary; extensions cannot select their own dispatcher or runner.
pub trait Registrar {
    fn command<C: Command>(&mut self);
    fn start_hook<H: StartHook>(&mut self);
    fn service<S: Service>(&mut self);
}

/// Declares a command's error enum: each variant has its code, its message, and the fields that become
/// its `details`.
#[macro_export]
macro_rules! command_errors {
    ($vis:vis enum $name:ident {
        $( $variant:ident { $( $field:ident : $type:ty ),* $(,)? } => $code:literal, $message:literal; )+
    }) => {
        #[derive(Debug, PartialEq)]
        $vis enum $name { $( $variant { $( $field: $type ),* } ),+ }
        impl $crate::CommandError for $name {
            const CODES: &'static [&'static str] = &[ $( $code ),+ ];
            fn code(&self) -> &'static str {
                match self { $( Self::$variant { .. } => $code ),+ }
            }
            fn message(&self) -> String {
                match self { $( Self::$variant { .. } => $message.to_string() ),+ }
            }
            #[allow(unused_variables)]
            fn details(&self) -> serde_json::Value {
                match self { $( Self::$variant { $( $field ),* } => serde_json::json!({ $( stringify!($field): $field ),* }) ),+ }
            }
        }
    };
}
