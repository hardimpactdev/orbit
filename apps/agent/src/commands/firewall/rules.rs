//! Role rules and Router LAN changes share one locked, native ownership implementation.
use super::*;
use std::collections::{BTreeMap, BTreeSet};

#[derive(Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Managed {
    shape: Shape,
    replace_port: bool,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RulesInput {
    desired: Vec<Managed>,
    remove: Vec<Shape>,
    enable: bool,
}
#[derive(Serialize)]
pub struct Reconciled {
    matches: bool,
}

fn owned_comment(comment: &str) -> bool {
    comment.starts_with("orbit:") || comment == "orbit-ci-runners"
}
fn validate_shapes<'a>(shapes: impl Iterator<Item = &'a Shape>) -> Result<(), Error> {
    let mut comments = BTreeSet::new();
    for shape in shapes {
        if !shape.valid()
            || !owned_comment(&shape.comment)
            || !comments.insert(&shape.comment)
            || comments.len() > 4096
        {
            return Err(Error::Invalid {});
        }
    }
    Ok(())
}

/// Build UFW argv from the exact shape. Concrete wildcard families must not expand to both.
fn arguments(shape: &Shape) -> Vec<String> {
    let mut args = if shape.direction == "fwd" {
        vec!["route".into(), shape.action.clone()]
    } else {
        vec![shape.action.clone(), shape.direction.clone()]
    };
    for (direction, interface) in [("in", &shape.in_interface), ("out", &shape.out_interface)] {
        if let Some(interface) = interface {
            if shape.direction == "fwd" {
                args.push(direction.into());
            }
            args.extend(["on".into(), interface.clone()]);
        }
    }
    if shape.protocol != "any" {
        args.extend(["proto".into(), shape.protocol.clone()]);
    }
    let wildcard = match shape.family.as_deref() {
        Some("v4") => "0.0.0.0/0",
        Some("v6") => "::/0",
        _ => "any",
    };
    args.extend(
        [
            "from",
            if shape.source == "any" {
                wildcard
            } else {
                &shape.source
            },
            "to",
            if shape.destination == "any" {
                wildcard
            } else {
                &shape.destination
            },
        ]
        .map(str::to_owned),
    );
    if shape.port != "any" {
        args.extend(["port".into(), shape.port.clone()]);
    }
    args.extend(["comment".into(), shape.comment.clone()]);
    args
}
fn active(host: &mut impl Host) -> Result<Vec<Row>, Error> {
    let (backend, rows) = status(host)?;
    if backend != Backend::Active {
        return Err(Error::Convergence {});
    }
    Ok(rows)
}
fn delete_comments(host: &mut impl Host, comments: &BTreeSet<String>) -> Result<(), Error> {
    if comments.is_empty() {
        return Ok(());
    }
    let rows = active(host)?;
    let numbers: BTreeSet<_> = rows
        .iter()
        .filter(|r| comments.contains(&r.comment))
        .map(|r| r.number.ok_or(Error::Convergence {}))
        .collect::<Result<_, _>>()?;
    for number in numbers.into_iter().rev() {
        change(
            host,
            vec!["--force".into(), "delete".into(), number.to_string()],
            Error::Convergence {},
        )?;
    }
    Ok(())
}
fn replaceable_port(rows: &[Row], rule: &Managed) -> bool {
    if !rule.replace_port
        || !rule.shape.comment.starts_with("orbit:")
        || rule
            .shape
            .port
            .parse::<u16>()
            .ok()
            .filter(|p| *p > 0)
            .is_none()
    {
        return false;
    }
    let Some(previous) = rows
        .iter()
        .find(|r| r.comment == rule.shape.comment)
        .and_then(|r| r.shape.as_ref())
    else {
        return false;
    };
    if previous
        .port
        .parse::<u16>()
        .ok()
        .filter(|p| *p > 0)
        .is_none()
    {
        return false;
    }
    let mut expected = rule.shape.clone();
    expected.port = previous.port.clone();
    inventory::ownership(rows, &expected) == Ownership::Exact
}

fn rules(input: &RulesInput, host: &mut impl Host) -> Result<Reconciled, Error> {
    validate_shapes(
        input
            .desired
            .iter()
            .map(|r| &r.shape)
            .chain(input.remove.iter()),
    )?;
    if input.enable
        && (input.desired.len() != 1 || !input.remove.is_empty() || {
            let s = &input.desired[0].shape;
            s.comment != "orbit:public-ssh-recovery"
                || s.action != "allow"
                || s.direction != "in"
                || s.source != "any"
                || s.destination != "any"
                || s.protocol != "tcp"
                || s.port.parse::<u16>().ok().filter(|p| *p > 0).is_none()
                || s.in_interface.is_some()
                || s.out_interface.is_some()
                || s.family.is_some()
        })
    {
        return Err(Error::Invalid {});
    }
    let (backend, mut rows) = status(host)?;
    if backend != Backend::Active {
        if backend != Backend::Inactive || !input.enable {
            return Err(Error::Convergence {});
        }
        let stored = host.stored().map_err(|_| Error::Convergence {})?;
        let recovery = &input.desired[0].shape;
        match inventory::ownership(&stored, recovery) {
            Ownership::Drift => return Err(Error::Convergence {}),
            Ownership::Missing => change(host, arguments(recovery), Error::Convergence {})?,
            Ownership::Exact => (),
        }
        if inventory::ownership(&host.stored().map_err(|_| Error::Convergence {})?, recovery)
            != Ownership::Exact
        {
            return Err(Error::Convergence {});
        }
        change(
            host,
            vec!["--force".into(), "enable".into()],
            Error::Convergence {},
        )?;
        rows = active(host)?;
    }
    let mut replacing = BTreeSet::new();
    let mut missing = vec![];
    for rule in &input.desired {
        match inventory::ownership(&rows, &rule.shape) {
            Ownership::Exact => (),
            Ownership::Missing => missing.push(&rule.shape),
            Ownership::Drift if replaceable_port(&rows, rule) => {
                replacing.insert(rule.shape.comment.clone());
                missing.push(&rule.shape);
            }
            Ownership::Drift => return Err(Error::Convergence {}),
        }
    }
    // Prove all ownership before changing any of the requested rules.
    for shape in &input.remove {
        if inventory::ownership(&rows, shape) == Ownership::Drift {
            return Err(Error::Convergence {});
        }
    }
    delete_comments(host, &replacing)?;
    for shape in missing {
        change(host, arguments(shape), Error::Convergence {})?;
    }
    let rows = active(host)?;
    if input
        .desired
        .iter()
        .any(|r| inventory::ownership(&rows, &r.shape) != Ownership::Exact)
    {
        return Err(Error::Convergence {});
    }
    let removing = input.remove.iter().map(|s| s.comment.clone()).collect();
    delete_comments(host, &removing)?;
    if !input.remove.is_empty() {
        let rows = active(host)?;
        if input
            .remove
            .iter()
            .any(|s| inventory::ownership(&rows, s) != Ownership::Missing)
        {
            return Err(Error::Convergence {});
        }
    }
    Ok(Reconciled { matches: true })
}

const FAMILIES: [&str; 3] = [
    "orbit:router-lan-https",
    "orbit:router-lan-dns-udp",
    "orbit:router-lan-dns-tcp",
];
fn router_comment(comment: &str) -> bool {
    FAMILIES
        .iter()
        .any(|prefix| inventory::family_comment(comment, prefix))
}
fn router_inventory(rows: &[Row]) -> Result<Vec<Shape>, Error> {
    let mut shapes = vec![];
    for row in rows.iter().filter(|r| {
        FAMILIES
            .iter()
            .any(|p| inventory::family_comment(&r.comment, p))
    }) {
        let shape = row.shape.as_ref().ok_or(Error::Convergence {})?;
        if router_comment(&row.comment) {
            shapes.push(shape.clone());
        }
    }
    Ok(shapes)
}
fn same_inventory(a: &[Shape], b: &[Shape]) -> bool {
    let mut remaining = b.to_vec();
    for shape in a {
        let Some(i) = remaining.iter().position(|s| shape == s) else {
            return false;
        };
        remaining.remove(i);
    }
    remaining.is_empty()
}
fn restore(host: &mut impl Host, snapshot: &[Shape]) -> Result<(), Error> {
    let observed = router_inventory(&active(host)?)?;
    let comments: BTreeSet<_> = snapshot
        .iter()
        .chain(observed.iter())
        .map(|s| s.comment.clone())
        .collect();
    let changed: BTreeSet<_> = comments
        .into_iter()
        .filter(|comment| {
            let a: Vec<_> = snapshot
                .iter()
                .filter(|s| &s.comment == comment)
                .cloned()
                .collect();
            let b: Vec<_> = observed
                .iter()
                .filter(|s| &s.comment == comment)
                .cloned()
                .collect();
            !same_inventory(&a, &b)
        })
        .collect();
    delete_comments(host, &changed)?;
    for shape in snapshot.iter().filter(|s| changed.contains(&s.comment)) {
        change(host, arguments(shape), Error::Convergence {})?;
    }
    if !same_inventory(snapshot, &router_inventory(&active(host)?)?) {
        return Err(Error::Convergence {});
    }
    Ok(())
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RouterInput {
    desired: Vec<Shape>,
    expand: bool,
    prune: bool,
}
fn router(input: &RouterInput, host: &mut impl Host) -> Result<Reconciled, Error> {
    validate_shapes(input.desired.iter())?;
    if input.desired.iter().any(|s| !router_comment(&s.comment)) {
        return Err(Error::Invalid {});
    }
    let rows = active(host)?;
    let snapshot = router_inventory(&rows)?;
    let desired: BTreeMap<_, _> = input
        .desired
        .iter()
        .map(|s| (s.comment.as_str(), s))
        .collect();
    let add: Vec<_> = input
        .desired
        .iter()
        .filter(|s| inventory::ownership(&rows, s) == Ownership::Missing)
        .collect();
    let replace: Vec<_> = input
        .desired
        .iter()
        .filter(|s| inventory::ownership(&rows, s) == Ownership::Drift)
        .collect();
    let remove: BTreeSet<_> = snapshot
        .iter()
        .filter(|s| !desired.contains_key(s.comment.as_str()))
        .map(|s| s.comment.clone())
        .collect();
    if !(input.expand && (!add.is_empty() || !replace.is_empty())
        || input.prune && !remove.is_empty())
    {
        return Ok(Reconciled { matches: true });
    }
    if snapshot
        .iter()
        .enumerate()
        .any(|(i, s)| snapshot[i + 1..].contains(s))
    {
        return Err(Error::Convergence {});
    }
    let result = (|| {
        if input.expand {
            for shape in add {
                change(host, arguments(shape), Error::Convergence {})?;
            }
            delete_comments(host, &replace.iter().map(|s| s.comment.clone()).collect())?;
            for shape in replace {
                change(host, arguments(shape), Error::Convergence {})?;
            }
        }
        if input.prune {
            delete_comments(host, &remove)?;
        }
        let rows = active(host)?;
        if input.expand
            && input
                .desired
                .iter()
                .any(|s| inventory::ownership(&rows, s) != Ownership::Exact)
        {
            return Err(Error::Convergence {});
        }
        if input.prune
            && router_inventory(&rows)?
                .iter()
                .any(|s| !desired.contains_key(s.comment.as_str()))
        {
            return Err(Error::Convergence {});
        }
        Ok(Reconciled { matches: true })
    })();
    if result.is_err() && restore(host, &snapshot).is_err() {
        return Err(Error::Recovery {});
    }
    result
}

pub struct Rules;
impl Command for Rules {
    const NAME: &'static str = "firewall.rules";
    const SUMMARY: &'static str =
        "Converge exact managed role rules and retire owned rules under one lock.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["firewall"];
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = RulesInput;
    type Output = Reconciled;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(_: &RulesInput) -> Option<String> {
        Some("firewall:roles".into())
    }
    fn run(input: RulesInput, _: &Context) -> Result<Reconciled, Error> {
        rules(&input, &mut Native)
    }
}
pub struct Router;
impl Command for Router {
    const NAME: &'static str = "firewall.router-lan";
    const SUMMARY: &'static str =
        "Expand or prune owned Router LAN rules and restore the preceding inventory on failure.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["firewall"];
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = RouterInput;
    type Output = Reconciled;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(_: &RouterInput) -> Option<String> {
        Some("firewall:router-lan".into())
    }
    fn run(input: RouterInput, _: &Context) -> Result<Reconciled, Error> {
        router(&input, &mut Native)
    }
}

#[cfg(test)]
#[path = "rules_tests.rs"]
mod tests;
