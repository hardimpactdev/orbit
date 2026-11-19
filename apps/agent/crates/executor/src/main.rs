//! The separate `orbit-executor` binary, kept until the Gateway pins an agent delivery whose `orbit-agent` has the
//! `execute` mode (ADR 0271 N7). Both run the same code: [`orbit_executor::main`].

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    std::process::exit(orbit_executor::main(&args));
}
