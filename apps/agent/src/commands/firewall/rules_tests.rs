use super::*;

struct Fake {
    active: bool,
    rows: Vec<Shape>,
    calls: Vec<Vec<String>>,
    fail_comment: Option<String>,
    fail_recovery: bool,
    failed: bool,
    fail_delete: Option<usize>,
    unreadable: Vec<String>,
}
impl Fake {
    fn new(rows: Vec<Shape>) -> Self {
        Self {
            active: true,
            rows,
            calls: vec![],
            fail_comment: None,
            fail_recovery: false,
            failed: false,
            fail_delete: None,
            unreadable: vec![],
        }
    }
    fn mutations(&self) -> Vec<&Vec<String>> {
        self.calls
            .iter()
            .filter(|args| args[0] != "status")
            .collect()
    }
    fn render(&self) -> String {
        if !self.active {
            return "Status: inactive\n".into();
        }
        let mut text = "Status: active\n".to_owned();
        for (i, s) in self.rows.iter().enumerate() {
            let endpoint = |value: &str| {
                if value == "any" {
                    "Anywhere".into()
                } else {
                    value.to_owned()
                }
            };
            let mut target = if s.port == "any" {
                endpoint(&s.destination)
            } else {
                format!(
                    "{}{}{}",
                    if s.destination == "any" {
                        String::new()
                    } else {
                        format!("{} ", s.destination)
                    },
                    s.port,
                    if s.protocol == "any" {
                        String::new()
                    } else {
                        format!("/{}", s.protocol)
                    }
                )
            };
            let mut source = endpoint(&s.source);
            if s.family.as_deref() == Some("v6") {
                target.push_str(" (v6)");
                source.push_str(" (v6)");
            }
            if s.direction == "fwd" {
                if let Some(interface) = &s.in_interface {
                    source.push_str(&format!(" on {interface}"));
                }
                if let Some(interface) = &s.out_interface {
                    target.push_str(&format!(" on {interface}"));
                }
            } else if let Some(interface) = s.in_interface.as_ref().or(s.out_interface.as_ref()) {
                target.push_str(&format!(" on {interface}"));
            }
            text.push_str(&format!(
                "[{}] {target} {} {} {source} # {}\n",
                i + 1,
                s.action.to_uppercase(),
                s.direction.to_uppercase(),
                s.comment
            ));
        }
        text.push_str(&self.unreadable.join("\n"));
        text
    }
}
fn arg<'a>(args: &'a [String], key: &str) -> Option<&'a str> {
    args.iter()
        .position(|s| s == key)
        .and_then(|i| args.get(i + 1))
        .map(String::as_str)
}
impl Host for Fake {
    fn stored(&mut self) -> io::Result<Vec<Row>> {
        Ok(self
            .rows
            .iter()
            .map(|s| Row {
                number: None,
                comment: s.comment.clone(),
                shape: Some(s.clone()),
            })
            .collect())
    }
    fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)> {
        self.calls.push(args.to_vec());
        if args[0] == "status" {
            return Ok((0, self.render()));
        }
        if self.failed && self.fail_recovery {
            return Ok((1, "refused recovery".into()));
        }
        if args == ["--force", "enable"] {
            self.active = true;
            return Ok((0, String::new()));
        }
        if args[0] == "--force" {
            assert_eq!(args[1], "delete");
            if let Some(remaining) = &mut self.fail_delete {
                *remaining -= 1;
                if *remaining == 0 {
                    self.fail_delete = None;
                    return Ok((1, "private deletion failure".into()));
                }
            }
            self.rows.remove(args[2].parse::<usize>().unwrap() - 1);
            return Ok((0, String::new()));
        }
        let comment = arg(args, "comment").unwrap();
        if self.fail_comment.as_deref() == Some(comment) && !self.failed {
            self.failed = true;
            return Ok((1, "private failure".into()));
        }
        let source = arg(args, "from").unwrap();
        let destination = arg(args, "to").unwrap();
        let families = if source == "any" && destination == "any" {
            vec!["v4", "v6"]
        } else if source.contains(':') || destination.contains(':') {
            vec!["v6"]
        } else {
            vec!["v4"]
        };
        let direction = if args[0] == "route" { "fwd" } else { &args[1] };
        let (incoming, outgoing) = if direction == "fwd" {
            let interface = |direction| {
                args.windows(3)
                    .find(|v| v[0] == direction && v[1] == "on")
                    .map(|v| v[2].clone())
            };
            (interface("in"), interface("out"))
        } else if direction == "in" {
            (arg(args, "on").map(str::to_owned), None)
        } else {
            (None, arg(args, "on").map(str::to_owned))
        };
        for family in families {
            let shape = Shape {
                comment: comment.into(),
                action: if args[0] == "route" {
                    args[1].clone()
                } else {
                    args[0].clone()
                },
                direction: direction.into(),
                source: inventory::normalize_endpoint(source).unwrap(),
                destination: inventory::normalize_endpoint(destination).unwrap(),
                port: arg(args, "port").unwrap_or("any").into(),
                protocol: arg(args, "proto").unwrap_or("any").into(),
                in_interface: incoming.clone(),
                out_interface: outgoing.clone(),
                family: Some(family.into()),
            };
            if !self.rows.contains(&shape) {
                self.rows.push(shape);
            }
        }
        Ok((0, String::new()))
    }
}
fn shape(comment: &str, port: &str) -> Shape {
    Shape {
        comment: comment.into(),
        action: "allow".into(),
        direction: "in".into(),
        source: "any".into(),
        destination: "10.44.0.2".into(),
        port: port.into(),
        protocol: "tcp".into(),
        in_interface: Some("orbit".into()),
        out_interface: None,
        family: Some("v4".into()),
    }
}
fn lan(id: u32, port: &str) -> Shape {
    let mut s = shape(&format!("orbit:router-lan-https:{id}"), port);
    s.in_interface = None;
    s.source = "10.20.0.11".into();
    s.destination = "10.20.0.10".into();
    s
}
#[test]
fn router_expands_before_pruning_repeats_and_preserves_unrelated_comments() {
    let old = lan(5, "443");
    let new = lan(6, "443");
    let unrelated = shape("orbit:router-lan-https-operator", "443");
    let mut host = Fake::new(vec![old.clone(), unrelated.clone()]);
    router(
        &RouterInput {
            desired: vec![new.clone()],
            expand: true,
            prune: false,
        },
        &mut host,
    )
    .unwrap();
    assert!(host.rows.contains(&old));
    assert!(host.rows.contains(&new));
    router(
        &RouterInput {
            desired: vec![new.clone()],
            expand: false,
            prune: true,
        },
        &mut host,
    )
    .unwrap();
    assert_eq!(host.rows, [unrelated, new.clone()]);
    host.calls.clear();
    router(
        &RouterInput {
            desired: vec![new],
            expand: true,
            prune: true,
        },
        &mut host,
    )
    .unwrap();
    assert!(host.mutations().is_empty());
}

#[test]
fn router_restores_each_prior_shape_on_partial_failure_and_reports_failed_recovery() {
    for fail_recovery in [false, true] {
        let mut second = lan(5, "440:443");
        second.source = "10.20.0.12".into();
        second.protocol = "any".into();
        let mut ipv6 = lan(5, "443");
        ipv6.source = "2001:db8::11".into();
        ipv6.destination = "2001:db8::10".into();
        ipv6.family = Some("v6".into());
        let snapshot = vec![lan(5, "444"), second, ipv6];
        let mut host = Fake::new(snapshot.clone());
        host.fail_comment = Some("orbit:router-lan-https:5".into());
        host.fail_recovery = fail_recovery;
        let result = router(
            &RouterInput {
                desired: vec![lan(5, "443"), lan(6, "443")],
                expand: true,
                prune: true,
            },
            &mut host,
        );
        assert!(
            matches!(result, Err(Error::Convergence {})) && !fail_recovery
                || matches!(result, Err(Error::Recovery {})) && fail_recovery
        );
        if !fail_recovery {
            assert!(same_inventory(&host.rows, &snapshot));
        }
    }
}

#[test]
fn router_refuses_duplicate_owned_rows_before_mutation_and_cleans_ipv6_residue() {
    let mut host = Fake::new(vec![lan(5, "443"), lan(5, "443")]);
    assert!(matches!(
        router(
            &RouterInput {
                desired: vec![],
                expand: false,
                prune: true
            },
            &mut host
        ),
        Err(Error::Convergence {})
    ));
    assert!(host.mutations().is_empty());
    let mut v6 = lan(5, "443");
    v6.family = Some("v6".into());
    v6.source = "2001:db8::11".into();
    v6.destination = "2001:db8::10".into();
    let mut host = Fake::new(vec![v6]);
    router(
        &RouterInput {
            desired: vec![],
            expand: false,
            prune: true,
        },
        &mut host,
    )
    .unwrap();
    assert!(host.rows.is_empty());
}

#[test]
fn router_restores_a_partially_pruned_inventory_and_refuses_unreadable_owned_rows() {
    let snapshot = vec![lan(5, "443"), lan(6, "444"), lan(7, "440:443")];
    let mut host = Fake::new(snapshot.clone());
    host.fail_delete = Some(2);
    assert!(matches!(
        router(
            &RouterInput {
                desired: vec![],
                expand: false,
                prune: true
            },
            &mut host
        ),
        Err(Error::Convergence {})
    ));
    assert!(same_inventory(&snapshot, &host.rows));
    for prefix in FAMILIES {
        let mut host = Fake::new(snapshot.clone());
        host.unreadable
            .push(format!("[9] OpenSSH ALLOW IN Anywhere # {prefix}:99"));
        assert!(matches!(
            router(
                &RouterInput {
                    desired: vec![],
                    expand: false,
                    prune: true
                },
                &mut host
            ),
            Err(Error::Convergence {})
        ));
        assert!(host.mutations().is_empty());
    }
    let mut host = Fake::new(vec![lan(5, "443")]);
    host.unreadable
        .push("[9] OpenSSH ALLOW IN Anywhere # orbit:router-lan-https-operator".into());
    router(
        &RouterInput {
            desired: vec![],
            expand: false,
            prune: true,
        },
        &mut host,
    )
    .unwrap();
    assert!(host.rows.is_empty());
    assert_eq!(host.unreadable.len(), 1);
}
