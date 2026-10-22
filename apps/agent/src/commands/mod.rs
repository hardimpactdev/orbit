//! The named commands this binary implements, by area.

pub mod agent;

use crate::command::registry::{entry, Entry};

/// Declares a command's error enum: each variant has its code, its message, and the fields that become
/// its `details`.
#[macro_export]
macro_rules! command_errors {
    ($vis:vis enum $name:ident {
        $( $variant:ident { $( $field:ident : $type:ty ),* $(,)? } => $code:literal, $message:literal; )+
    }) => {
        #[derive(Debug, PartialEq)]
        $vis enum $name { $( $variant { $( $field: $type ),* } ),+ }
        impl $crate::command::CommandError for $name {
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

pub fn all() -> Vec<Entry> {
    vec![entry::<agent::Status>(), entry::<agent::Install>()]
}
