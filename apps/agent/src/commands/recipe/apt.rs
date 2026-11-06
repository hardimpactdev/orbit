//! Authenticated APT observations and bounded fetches.
use super::{run, Failure, Host, Invocation, Output};
use regex::Regex;
use std::{collections::BTreeMap, time::Duration};

fn expression(pattern: &str) -> Regex {
    Regex::new(pattern).expect("fixed apt expression")
}
/// Signature, digest, authentication, and mixed failures never qualify for transport retries.
pub fn retryable(stdout: &str, stderr: &str) -> bool {
    let output = format!("{stdout}\n{stderr}");
    if expression(r"(?i)hash sum mismatch|unexpected size|signature|NO_PUBKEY").is_match(&output) {
        return false;
    }
    let failure_pattern = expression(r"(?m)^[EW]: Failed to fetch (\S+)[ \t]+([^\n]+)");
    let failures: Vec<_> = failure_pattern.captures_iter(&output).collect();
    if failures.is_empty() {
        return false;
    }
    let unsigned_pattern =
        expression(r"(?m)^E: The repository '(\S+) (\S+) (?:InRelease|Release)' is not signed\.$");
    let unsigned: Vec<_> = unsigned_pattern.captures_iter(&output).collect();
    let derived = !unsigned.is_empty()
        && unsigned.iter().all(|entry| {
            failures.iter().any(|failure| {
                ["InRelease", "Release"].iter().any(|kind| {
                    failure[1]
                        == format!(
                            "{}/dists/{}/{kind}",
                            entry[1].trim_end_matches('/'),
                            &entry[2]
                        )
                })
            })
        });
    if output.contains("not signed") && !derived {
        return false;
    }
    failures.iter().all(|failure| {
        expression(r"\A5\d\d\b").is_match(&failure[2])
            || ["Connection reset by peer", "Connection failed"]
                .iter()
                .any(|reason| failure[2].contains(reason))
    })
}
pub fn fetch(host: &mut impl Host, invocation: &Invocation) -> Result<Output, Failure> {
    let mut invocation = invocation.clone();
    invocation.environment.retain(|(key, _)| key != "LC_ALL");
    invocation.environment.push(("LC_ALL".into(), "C".into()));
    for delay in [Some(10), Some(20), Some(30), None] {
        let output = run(host, &invocation, false)?;
        if output.code == 0 {
            return Ok(output);
        }
        let Some(delay) = delay.filter(|_| retryable(&output.stdout, &output.stderr)) else {
            return Err(Failure::command(&invocation, &output));
        };
        host.wait(Duration::from_secs(delay))?;
    }
    unreachable!("the fourth failed attempt returns")
}
pub fn package_command(
    host: &mut impl Host,
    invocation: &Invocation,
    packages: &[String],
) -> Result<Output, Failure> {
    let retry = invocation.argv.first().is_some_and(|v| v == "apt-get")
        && invocation
            .argv
            .get(1)
            .is_some_and(|v| v == "update" || v == "install");
    let result = if retry {
        fetch(host, invocation)
    } else {
        run(host, invocation, true)
    };
    result.map_err(|mut error| {
        error.packages = packages.to_vec();
        error
    })
}

pub fn architecture(host: &mut impl Host) -> Result<String, Failure> {
    let arch = run(
        host,
        &Invocation::new(&["dpkg", "--print-architecture"]),
        true,
    )?
    .stdout
    .trim()
    .to_string();
    if arch != "amd64" {
        return Err(Failure::new(
            "architecture",
            "Unsupported package architecture",
        ));
    }
    Ok(arch)
}
pub fn packages(host: &mut impl Host) -> Result<BTreeMap<String, String>, Failure> {
    let arch = architecture(host)?;
    let output = run(
        host,
        &Invocation::new(&[
            "dpkg-query",
            "-W",
            "-f=${Package}\t${Architecture}\t${Version}\t${Status}\n",
        ]),
        true,
    )?;
    let mut installed = BTreeMap::new();
    for line in output.stdout.lines() {
        let parts: Vec<_> = line.splitn(4, '\t').collect();
        if parts.len() != 4 {
            return Err(Failure::new(
                "inventory",
                "Malformed dpkg package inventory",
            ));
        }
        if parts[3] == "install ok installed" && [arch.as_str(), "all"].contains(&parts[1]) {
            installed.insert(parts[0].into(), parts[2].into());
        }
    }
    Ok(installed)
}
#[cfg(test)]
mod tests;
