//! The core of the Orbit executor (ADR 0266, ADR 0267 P10): the order format, the caller and order checks,
//! the done records, the journal and crash recovery, the socket and the relay. Action families live in their
//! own crates; the `orbit-executor` binary joins them to this core.

pub mod action;
pub mod caller;
pub mod executor;
pub mod ids;
pub mod journal;
pub mod order;
pub mod relay;
pub mod seal;
pub mod server;
