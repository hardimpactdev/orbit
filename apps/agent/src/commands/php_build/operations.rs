use super::*;
use crate::command::{Command, Context, Platform, Privilege};
use std::time::Duration;

#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Planned {
    pub pending: Vec<String>,
    pub repair: Vec<String>,
    pub extras: Vec<String>,
    pub unowned: Vec<String>,
}
pub struct Preparation {
    pub versions: Builds,
    pub packages: Installed,
    pub sources: Vec<PathBuf>,
    pub pins: BTreeMap<String, Pin>,
    pub unowned: BTreeMap<String, Vec<inventory::Unowned>>,
    pub plan: Planned,
}
pub fn prepare(host: &mut impl RecipeHost, files: &Files, input: &Input) -> Result<Preparation> {
    input.validate()?;
    if let Some(required) = &input.release {
        let observed = fs::read_to_string(files.path("/etc/os-release"))
            .ok()
            .and_then(|s| super::super::os_release::parse(&s).ok());
        let (id, codename) = observed
            .map(|v| (v.id, v.codename))
            .unwrap_or_else(|| ("unknown".into(), "unknown".into()));
        if id != required.id || !required.codenames.contains(&codename) {
            return Err(Failure {
                kind: Kind::PlatformUnsupported,
                data: Box::new(Details {
                    id: Some(id),
                    codename: Some(codename),
                    ..Details::default()
                }),
            });
        }
    }
    let architecture = recipe::apt::architecture(host)?;
    let versions = inventory::selected(input, &architecture)?;
    let sources = inventory::sources(files)?;
    if !sources.is_empty() && input.extras != Extras::Purge {
        return Err(Failure {
            kind: Kind::ConflictingSource,
            data: Box::new(Details {
                sources: Some(sources.iter().map(|p| p.display().to_string()).collect()),
                ..Details::default()
            }),
        });
    }
    let packages = inventory::installed(host)?;
    let extras = inventory::extras(&versions, &packages);
    if !extras.is_empty() && input.extras == Extras::Refuse {
        return Err(Failure {
            kind: Kind::ExtraPackages,
            data: Box::new(Details {
                packages: Some(extras),
                ..Details::default()
            }),
        });
    }
    let mut unowned = BTreeMap::new();
    let mut names = BTreeSet::new();
    for (version, build) in &versions {
        let found = inventory::unowned(host, files, version, &build.extensions)?;
        names.extend(found.iter().map(|u| format!("php{version}:{}", u.module)));
        unowned.insert(version.clone(), found);
    }
    let names: Vec<_> = names.into_iter().collect();
    if !names.is_empty() && input.extras == Extras::Refuse {
        return Err(Failure {
            kind: Kind::UnownedExtensions,
            data: Box::new(Details {
                extensions: Some(names),
                ..Details::default()
            }),
        });
    }
    let pins: BTreeMap<_, _> = versions
        .values()
        .flat_map(|v| v.pins.iter().map(|(n, p)| (n.clone(), p.clone())))
        .collect();
    let pending: Vec<_> = pins
        .iter()
        .filter(|(name, pin)| packages.get(*name) != Some(&pin.version))
        .map(|(name, _)| name.clone())
        .collect();
    let unchanged: Vec<_> = pins
        .keys()
        .filter(|name| packages.contains_key(*name) && !pending.contains(*name))
        .cloned()
        .collect();
    let repair = inventory::damaged(host, &unchanged)?;
    Ok(Preparation {
        versions,
        packages,
        sources,
        pins,
        unowned,
        plan: Planned {
            pending,
            repair,
            extras,
            unowned: names,
        },
    })
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Applied {
    pub changed: Vec<String>,
    pub repaired: Vec<String>,
    pub purged: Vec<String>,
    pub disabled: Vec<String>,
    pub restarted: Vec<String>,
    pub reloaded: Vec<String>,
    pub deferred: Vec<String>,
    pub stale_masters: Vec<String>,
    pub fpm_disabled: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
#[serde(rename_all = "lowercase")]
pub enum Outcome {
    Current,
    Applied,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Report {
    pub outcome: Outcome,
    pub data: Applied,
}
struct Transaction<'a> {
    files: &'a Files,
    old: Installed,
    replaced: Vec<String>,
    added: Vec<String>,
    previous: BTreeMap<String, PathBuf>,
    pools: Vec<PathBuf>,
    before: BTreeMap<String, runtime::Links>,
    moved: Vec<(PathBuf, PathBuf)>,
    ini: BTreeMap<PathBuf, Option<Vec<u8>>>,
    restarted: Vec<String>,
    reloaded: Vec<String>,
    reinstall: bool,
}
impl Transaction<'_> {
    fn rollback(&self, host: &mut impl Host) -> bool {
        let mut ok = true;
        for pass in 0..2 {
            match inventory::installed(host) {
                Ok(installed) => {
                    let present: Vec<_> = self
                        .added
                        .iter()
                        .filter(|n| installed.contains_key(*n))
                        .cloned()
                        .collect();
                    if !present.is_empty() {
                        let mut args = vec!["dpkg".into(), "--remove".into(), "--".into()];
                        args.extend(present);
                        ok &= invoke(
                            host,
                            &args.iter().map(String::as_str).collect::<Vec<_>>(),
                            false,
                        )
                        .is_ok_and(|r| r.code == 0);
                    }
                }
                Err(_) => ok = false,
            }
            if pass == 0 && !self.previous.is_empty() {
                ok &= packages::install(
                    host,
                    self.files,
                    &self.previous.values().cloned().collect::<Vec<_>>(),
                    self.reinstall,
                )
                .unwrap_or(false);
                ok &= runtime::keep_disabled(&self.pools).is_ok();
            }
        }
        match inventory::installed(host) {
            Ok(installed) => {
                ok &= self
                    .replaced
                    .iter()
                    .all(|n| installed.get(n) == self.old.get(n))
                    && self.added.iter().all(|n| !installed.contains_key(n));
            }
            Err(_) => ok = false,
        }
        for (original, kept) in self.moved.iter().rev() {
            ok &= fs::rename(kept, original).is_ok();
        }
        for (version, links) in &self.before {
            ok &= runtime::restore_links(self.files, version, links).is_ok();
        }
        for (path, content) in &self.ini {
            ok &= match content {
                Some(bytes) => runtime::write_ini(self.files, path, bytes),
                None => remove(path),
            }
            .is_ok();
        }
        for (action, units) in [("restart", &self.restarted), ("reload", &self.reloaded)] {
            for unit in units {
                ok &= invoke(host, &["systemctl", action, unit], false).is_ok_and(|r| r.code == 0);
                ok &= invoke(host, &["systemctl", "is-active", "--quiet", unit], false)
                    .is_ok_and(|r| r.code == 0);
            }
        }
        ok
    }
    fn failure(&self, host: &mut impl Host, mut error: Failure) -> Failure {
        if self.rollback(host) {
            error.data.restored = Some(true);
            error
        } else {
            error.kind = Kind::RollbackFailed;
            error.data.reason = Some(format!(
                "{}; the rollback failed too",
                error.data.reason.as_deref().unwrap_or("PHP build failed")
            ));
            error
        }
    }
}
pub fn apply(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    deadline: Instant,
) -> Result<Report> {
    let mut prepared = prepare(host, files, input)?;
    for path in [files.path(RETIRED_SOURCE), files.path(RETIRED_KEY)]
        .iter()
        .chain(prepared.sources.iter())
    {
        remove(path)?;
    }
    directories(files, &files.path(CACHE))?;
    let mut before = BTreeMap::new();
    for version in prepared.versions.keys() {
        before.insert(version.clone(), runtime::links(files, version)?);
    }
    let pools = runtime::disabled_pools(files, &prepared.versions, &prepared.packages);
    let purge = if input.extras == Extras::Purge {
        prepared.plan.extras.clone()
    } else {
        vec![]
    };
    let selected: Vec<_> = prepared
        .plan
        .pending
        .iter()
        .chain(&prepared.plan.repair)
        .cloned()
        .collect();
    let replaced: Vec<_> = selected
        .iter()
        .filter(|n| prepared.packages.contains_key(*n))
        .chain(&purge)
        .cloned()
        .collect();
    let architecture = recipe::apt::architecture(host)?;
    let (downloads, previous) = {
        let work = recipe::WorkDir::new()?;
        let pins: Vec<_> = selected.iter().map(|n| prepared.pins[n].clone()).collect();
        let downloads =
            packages::download(host, files, &pins, &input.mirror, &work.0, &architecture)?;
        let previous = packages::previous(
            host,
            files,
            &replaced,
            &mut prepared.packages,
            &input.repack,
            &work.0,
            &architecture,
        )?;
        (downloads, previous)
    };
    let added = prepared
        .plan
        .pending
        .iter()
        .filter(|n| !prepared.packages.contains_key(*n))
        .cloned()
        .collect();
    let mut transaction = Transaction {
        files,
        old: prepared.packages,
        replaced,
        added,
        previous,
        pools,
        before,
        moved: vec![],
        ini: BTreeMap::new(),
        restarted: vec![],
        reloaded: vec![],
        reinstall: !prepared.plan.repair.is_empty(),
    };
    let changed = (|| -> Result<(bool, Vec<String>, Vec<String>, bool)> {
        if input.extras == Extras::Purge && !prepared.plan.unowned.is_empty() {
            let backup = files.path(BACKUP).join(format!(
                "{}-{:016x}",
                std::process::id(),
                rand::random::<u64>()
            ));
            for (version, found) in &prepared.unowned {
                runtime::disable_unowned(files, version, found, &backup, &mut transaction.moved)?;
            }
        }
        if !purge.is_empty() {
            let mut args = vec!["dpkg".into(), "--purge".into(), "--".into()];
            args.extend(purge.iter().cloned());
            if invoke(
                host,
                &args.iter().map(String::as_str).collect::<Vec<_>>(),
                false,
            )?
            .code
                != 0
            {
                return Err(Failure::package(
                    Kind::InstallFailed,
                    &purge[0],
                    "dpkg --purge failed",
                ));
            }
        }
        let installed = downloads.is_empty()
            || packages::install(host, files, &downloads, transaction.reinstall)?;
        runtime::keep_disabled(&transaction.pools)?;
        if !installed {
            return Err(Failure::package(
                Kind::InstallFailed,
                &selected[0],
                "apt-get install failed",
            ));
        }
        let current = inventory::installed(host)?;
        if let Some((name, _)) = prepared
            .pins
            .iter()
            .find(|(n, p)| current.get(*n) != Some(&p.version))
        {
            return Err(Failure::package(
                Kind::InstallFailed,
                name,
                "not at its recipe version after install",
            ));
        }
        for (version, build) in &prepared.versions {
            runtime::place_modules(host, version, input.pcov, input.opentelemetry)?;
            runtime::upload_progress(files, version, &mut transaction.ini)?;
            runtime::check_cli(host, files, version, build, input.pcov, input.extras)?;
        }
        let mut changed =
            !selected.is_empty() || !purge.is_empty() || !transaction.moved.is_empty();
        for version in prepared.versions.keys() {
            changed |= runtime::links(files, version)? != transaction.before[version];
        }
        let action = if changed { "restart" } else { "reload" };
        changed |= !transaction.ini.is_empty();
        let disabled =
            input.fpm == Fpm::Disabled && runtime::disable_fpm(host, &prepared.versions)?;
        let scope = if action == "reload" {
            Fpm::All
        } else {
            input.fpm
        };
        let mut deferred = vec![];
        let mut stale = vec![];
        if changed && input.fpm != Fpm::Disabled && scope != Fpm::None {
            for version in prepared.versions.keys() {
                let units = runtime::fpm_units(host, files, version, scope)?;
                stale.extend(units.skipped);
                for (unit, configuration) in units.active {
                    let touched = if action == "restart" {
                        &mut transaction.restarted
                    } else {
                        &mut transaction.reloaded
                    };
                    let request = runtime::Refresh {
                        version,
                        unit: &unit,
                        configuration: &configuration,
                        opentelemetry: input.opentelemetry,
                        action,
                        deadline,
                    };
                    let reason = runtime::refresh(host, files, &request, touched)?;
                    let Some(reason) = reason else {
                        continue;
                    };
                    if reason == "fpm_invalid"
                        && (input.fpm == Fpm::Shared
                            || action == "reload" && input.fpm == Fpm::None)
                    {
                        deferred.push(unit);
                        continue;
                    }
                    let mut error = Failure::new(
                        if reason == "fpm_invalid" {
                            Kind::FpmInvalid
                        } else {
                            Kind::RolledBack
                        },
                        reason,
                    );
                    error.data.unit = Some(unit);
                    return Err(error);
                }
            }
        }
        stale.sort();
        stale.dedup();
        Ok((changed || disabled, deferred, stale, disabled))
    })();
    let (changed, deferred, stale, fpm_disabled) =
        changed.map_err(|e| transaction.failure(host, e))?;
    Ok(Report {
        outcome: if changed {
            Outcome::Applied
        } else {
            Outcome::Current
        },
        data: Applied {
            changed: prepared.plan.pending,
            repaired: prepared.plan.repair,
            purged: purge,
            disabled: if transaction.moved.is_empty() {
                vec![]
            } else {
                prepared.plan.unowned
            },
            restarted: transaction.restarted,
            reloaded: transaction.reloaded,
            deferred,
            stale_masters: stale,
            fpm_disabled,
        },
    })
}

pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "php.inspect-build";
    const SUMMARY: &'static str =
        "Inspect the pinned PHP build, extra packages, and extension ownership.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Input;
    type Output = Planned;
    type Error = Failure;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Planned> {
        Ok(prepare(&mut recipe::Native(context), &Files::system(), &input)?.plan)
    }
}
pub struct Install;
impl Command for Install {
    const NAME: &'static str = "php.install-build";
    const SUMMARY: &'static str =
        "Install and verify the exact PHP recipe build with rollback on failed activation.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    const RESOURCES: &'static [&'static str] = &["packages"];
    type Input = Input;
    type Output = Report;
    type Error = Failure;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("machine-php-build".into())
    }
    fn run(input: Input, context: &Context) -> Result<Report> {
        let _signals = recipe::interruption::Guard::install()?;
        let deadline = context
            .deadline()
            .checked_sub(Duration::from_secs(10))
            .filter(|d| *d > Instant::now())
            .ok_or_else(|| Failure::io("Insufficient time for PHP cleanup"))?;
        let bounded = Context::new(context.platform, deadline);
        apply(
            &mut recipe::Native(&bounded),
            &Files::system(),
            &input,
            deadline,
        )
    }
}
