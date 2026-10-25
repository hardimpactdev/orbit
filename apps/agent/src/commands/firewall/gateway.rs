//! Gateway VPN bootstrap keeps recovery SSH until every desired rule has been verified.
use super::*;
use std::collections::BTreeSet;

command_errors! {
    pub enum GatewayError {
        Invalid {} => "firewall.input_invalid", "The Gateway firewall intent is invalid.";
        Status {} => "vpn.firewall_status_failed", "The Gateway firewall could not be inspected.";
        StatusInvalid {} => "vpn.firewall_status_invalid", "The Gateway firewall status is unrecognized or remained inactive.";
        Recovery {} => "vpn.firewall_recovery_probe_failed", "The stored public SSH recovery rule could not be proved.";
        Enable {} => "vpn.firewall_enable_failed", "The Gateway firewall could not be enabled.";
        Rule {} => "vpn.firewall_rule_failed", "The Gateway firewall rule could not be applied.";
        Probe { comment: String } => "vpn.firewall_probe_failed", "A Gateway firewall rule did not match its managed shape.";
        Remove {} => "vpn.firewall_recovery_remove_failed", "The public SSH recovery rule could not be removed.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    desired: Vec<Shape>,
    recovery: Shape,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    matches: bool,
}
fn probe(shape: &Shape) -> GatewayError {
    GatewayError::Probe {
        comment: shape.comment.clone(),
    }
}
fn inspect(host: &mut impl Host) -> Result<(Backend, Vec<Row>), GatewayError> {
    let result = status(host).map_err(|e| match e {
        Error::StatusUnrecognized {} => GatewayError::StatusInvalid {},
        _ => GatewayError::Status {},
    })?;
    if result.0 == Backend::Absent {
        return Err(GatewayError::Status {});
    }
    Ok(result)
}
fn active(host: &mut impl Host) -> Result<Vec<Row>, GatewayError> {
    let (backend, rows) = inspect(host)?;
    if backend != Backend::Active {
        return Err(GatewayError::StatusInvalid {});
    }
    Ok(rows)
}
fn run(input: &Input, host: &mut impl Host) -> Result<Output, GatewayError> {
    rules::validate_shapes(input.desired.iter().chain([&input.recovery]))
        .map_err(|_| GatewayError::Invalid {})?;
    if input.desired.is_empty() || !rules::is_recovery(&input.recovery) {
        return Err(GatewayError::Invalid {});
    }
    let (backend, mut rows) = inspect(host)?;
    if backend == Backend::Inactive {
        rows = host.stored().map_err(|_| GatewayError::Recovery {})?;
    }
    for shape in input.desired.iter().chain([&input.recovery]) {
        if inventory::ownership(&rows, shape) == Ownership::Drift {
            return Err(probe(shape));
        }
    }
    if backend == Backend::Inactive {
        if inventory::ownership(&rows, &input.recovery) == Ownership::Missing {
            change(host, rules::arguments(&input.recovery), Error::Apply {})
                .map_err(|_| GatewayError::Rule {})?;
        }
        let stored = host.stored().map_err(|_| GatewayError::Recovery {})?;
        if inventory::ownership(&stored, &input.recovery) != Ownership::Exact {
            return Err(GatewayError::Recovery {});
        }
        change(
            host,
            vec!["--force".into(), "enable".into()],
            Error::Apply {},
        )
        .map_err(|_| GatewayError::Enable {})?;
        rows = active(host)?;
    }
    // The live view after enabling must also prove ownership before adding anything.
    for shape in &input.desired {
        if inventory::ownership(&rows, shape) == Ownership::Drift {
            return Err(probe(shape));
        }
    }
    for shape in &input.desired {
        if inventory::ownership(&rows, shape) == Ownership::Missing {
            change(host, rules::arguments(shape), Error::Apply {})
                .map_err(|_| GatewayError::Rule {})?;
        }
    }
    let (backend, rows) = inspect(host)?;
    if backend != Backend::Active {
        return Err(probe(&input.desired[0]));
    }
    for shape in &input.desired {
        if inventory::ownership(&rows, shape) != Ownership::Exact {
            return Err(probe(shape));
        }
    }
    match inventory::ownership(&rows, &input.recovery) {
        Ownership::Missing => return Ok(Output { matches: true }),
        Ownership::Drift => return Err(probe(&input.recovery)),
        Ownership::Exact => (),
    }
    let numbers: BTreeSet<_> = rows
        .iter()
        .filter(|r| r.comment == input.recovery.comment)
        .map(|r| r.number.ok_or_else(|| probe(&input.recovery)))
        .collect::<Result<_, _>>()?;
    for number in numbers.into_iter().rev() {
        change(
            host,
            vec!["--force".into(), "delete".into(), number.to_string()],
            Error::Remove {},
        )
        .map_err(|_| GatewayError::Remove {})?;
    }
    let (backend, rows) = inspect(host)?;
    if backend != Backend::Active
        || inventory::ownership(&rows, &input.recovery) != Ownership::Missing
    {
        return Err(probe(&input.recovery));
    }
    Ok(Output { matches: true })
}

pub struct GatewayVpn;
impl Command for GatewayVpn {
    const NAME: &'static str = "firewall.gateway-vpn";
    const SUMMARY: &'static str =
        "Converge Gateway VPN rules and retire public SSH only after verified private access.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["firewall"];
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = Input;
    type Output = Output;
    type Error = GatewayError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("firewall:gateway-vpn".into())
    }
    fn run(input: Input, _: &Context) -> Result<Output, GatewayError> {
        run(&input, &mut Native)
    }
}

#[cfg(test)]
#[path = "gateway_tests.rs"]
mod tests;
