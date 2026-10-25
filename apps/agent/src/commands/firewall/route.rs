//! Route LAN allowances and exact-comment cleanup, under the shared firewall lock.
use super::*;
use std::{collections::BTreeSet, net::Ipv4Addr};

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum Input {
    Converge {
        route_id: u64,
        router: Ipv4Addr,
        workload: Ipv4Addr,
    },
    Remove {
        route_id: u64,
    },
}
impl Input {
    fn route_id(&self) -> u64 {
        match self {
            Self::Converge { route_id, .. } | Self::Remove { route_id } => *route_id,
        }
    }
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    matches: bool,
}

fn route(input: &Input, host: &mut impl Host) -> Result<Output, Error> {
    if input.route_id() == 0 {
        return Err(Error::Invalid {});
    }
    let comment = format!("orbit:route-{}-lan", input.route_id());
    let (backend, mut rows) = status(host)?;
    if backend == Backend::Absent {
        return Err(Error::Status {});
    }
    match input {
        Input::Converge {
            router, workload, ..
        } => {
            let expected = Shape {
                comment,
                action: "allow".into(),
                direction: "in".into(),
                source: router.to_string(),
                destination: workload.to_string(),
                port: "443".into(),
                protocol: "tcp".into(),
                family: Some("v4".into()),
                in_interface: None,
                out_interface: None,
            };
            if backend == Backend::Inactive {
                rows = host.stored().map_err(|_| Error::Status {})?;
            }
            let matches = |rows: &[Row]| rows.iter().any(|r| r.shape.as_ref() == Some(&expected));
            if !matches(&rows) {
                change(host, rules::arguments(&expected), Error::Apply {})?;
                let (observed, mut rows) = status(host)?;
                if observed == Backend::Inactive {
                    rows = host.stored().map_err(|_| Error::Status {})?;
                }
                if observed != backend || !matches(&rows) {
                    return Err(Error::Verify {});
                }
            }
        }
        Input::Remove { .. } => {
            let numbers: BTreeSet<_> = rows
                .iter()
                .filter(|r| r.comment == comment)
                .map(|r| r.number.ok_or(Error::Collision {}))
                .collect::<Result<_, _>>()?;
            for number in numbers.into_iter().rev() {
                change(
                    host,
                    vec!["--force".into(), "delete".into(), number.to_string()],
                    Error::Remove {},
                )?;
            }
            let (_, rows) = status(host)?;
            if rows.iter().any(|r| r.comment == comment) {
                return Err(Error::RemoveVerify {});
            }
        }
    }
    Ok(Output { matches: true })
}

pub struct RouteLan;
impl Command for RouteLan {
    const NAME: &'static str = "firewall.route-lan";
    const SUMMARY: &'static str =
        "Converge a Route LAN allowance or remove all rules owned by its exact comment.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["firewall"];
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("firewall:route:{}", input.route_id()))
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        route(&input, &mut Native)
    }
}

#[cfg(test)]
#[path = "route_tests.rs"]
mod tests;
