use super::*;
use regex::Regex;
use std::io::Write;
use std::os::unix::fs::PermissionsExt;

const MAC: &str = "10:66:6a:f0:65:d4";
const VM_IP: &str = "10.251.78.14";
const STAMP: &str = "2026-11-07T19:00:05Z";

struct TempDir(PathBuf);
impl TempDir {
    fn new() -> Self {
        let path = std::env::temp_dir().join(format!(
            "orbit-agent-egress-{}-{}",
            std::process::id(),
            rand::random::<u64>()
        ));
        std::fs::create_dir_all(&path).unwrap();
        Self(path)
    }
}
impl Drop for TempDir {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.0);
    }
}

fn ip(text: &str) -> Ipv4Addr {
    text.parse().unwrap()
}

fn dns(rest: &str) -> String {
    format!("Nov  7 19:00:01 dnsmasq[1234]: {rest}")
}

fn query(serial: u64, name: &str) -> String {
    dns(&format!(
        "{serial} {VM_IP}/43210 query[A] {name} from {VM_IP}"
    ))
}

fn reply(serial: u64, name: &str, value: &str) -> String {
    dns(&format!("{serial} {VM_IP}/43210 reply {name} is {value}"))
}

/// A kernel log line from the VM, as current kernels print it with `log flags ether`: `MACSRC=`, `MACDST=` and
/// `MACPROTO=`.
fn connection(source: &str, destination: &str, protocol: &str, port: u16) -> String {
    from_mac(MAC, source, destination, protocol, port)
}

fn from_mac(mac: &str, source: &str, destination: &str, protocol: &str, port: u16) -> String {
    format!(
        "Nov  7 19:00:05 beast kernel: [12345.678901] orbit-egress IN=orbittask0 OUT=enp5s0 \
         MACSRC={mac} MACDST=aa:bb:cc:dd:ee:ff MACPROTO=0800 SRC={source} DST={destination} LEN=60 TOS=0x00 \
         PREC=0x00 TTL=63 ID=1 DF PROTO={protocol} SPT=43210 DPT={port} WINDOW=64240 RES=0x00 SYN URGP=0"
    )
}

/// The older form of the same line: one `MAC=<dst6>:<src6>:<type2>` field.
fn legacy_mac(mac: &str, source: &str, destination: &str, protocol: &str, port: u16) -> String {
    format!(
        "Nov  7 19:00:05 beast kernel: [12345.678901] orbit-egress IN=orbittask0 OUT=enp5s0 \
         MAC=aa:bb:cc:dd:ee:ff:{mac}:08:00 SRC={source} DST={destination} LEN=60 TOS=0x00 \
         PREC=0x00 TTL=63 ID=1 DF PROTO={protocol} SPT=43210 DPT={port} WINDOW=64240 RES=0x00 SYN URGP=0"
    )
}

/// The same line without any MAC field.
fn without_mac(source: &str, destination: &str, protocol: &str, port: u16) -> String {
    connection(source, destination, protocol, port)
        .split(' ')
        .filter(|token| !token.starts_with("MAC"))
        .collect::<Vec<_>>()
        .join(" ")
}

fn lease(mac: &str, address: &str, name: &str) -> String {
    format!("1794200000 {mac} {address} {name} 01:{mac}\n")
}

/// A temporary host with one VM `beast-tvm-3` on `orbittask0`.
struct World {
    _root: TempDir,
    paths: Paths,
}

impl World {
    fn new() -> Self {
        Self::under("orbittask0")
    }

    /// A host with the bridge `network` and one VM on it.
    fn under(network: &str) -> Self {
        let root = TempDir::new();
        let paths = Paths::under(&root.0, network);
        for directory in [
            &paths.hosts_dir,
            paths.dns_log.parent().unwrap(),
            paths.connection_log.parent().unwrap(),
        ] {
            std::fs::create_dir_all(directory).unwrap();
        }
        let world = Self { _root: root, paths };
        world.set_vm("project_beast-tvm-3.eth0", MAC, "beast-tvm-3", VM_IP);
        world.append(&world.paths.dns_log, "");
        world.append(&world.paths.connection_log, "");
        world
    }

    fn set_vm(&self, file: &str, mac: &str, name: &str, address: &str) {
        std::fs::write(self.paths.hosts_dir.join(file), format!("{mac},{name}\n")).unwrap();
        std::fs::write(&self.paths.leases, lease(mac, address, name)).unwrap();
    }

    fn append(&self, path: &Path, text: &str) {
        let mut file = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)
            .unwrap();
        file.write_all(text.as_bytes()).unwrap();
    }

    fn dns(&self, lines: &[String]) {
        self.append(&self.paths.dns_log, &(lines.join("\n") + "\n"));
    }

    fn connections(&self, lines: &[String]) {
        self.append(&self.paths.connection_log, &(lines.join("\n") + "\n"));
    }

    fn observer(&self) -> Observer {
        Observer::with_paths("orbittask0", self.paths.clone())
    }
}

/// Reads once and expects every source to be available.
fn observe(observer: &mut Observer, stamp: &str) {
    assert_eq!(observer.observe(Instant::now(), stamp), []);
}

/// What the Gateway accepts for a fact of kind `egress`.
fn assert_gateway_shape(subject: &str, data: &Value) {
    let matches = |pattern: &str, text: &str| Regex::new(pattern).unwrap().is_match(text);
    assert!(
        matches(r"^[a-z0-9][a-z0-9._-]{0,62}$", subject),
        "{subject}"
    );
    assert!(matches(
        r"^orbittask[a-z0-9]{1,6}$",
        data["network"].as_str().unwrap()
    ));
    assert!(matches(
        r"^([0-9a-f]{2}:){5}[0-9a-f]{2}$",
        data["mac"].as_str().unwrap()
    ));
    assert!(data["overflow"].is_u64());
    let rows = data["rows"].as_array().unwrap();
    assert!(rows.len() <= 1500);
    for row in rows {
        let keys: Vec<&str> = row
            .as_object()
            .unwrap()
            .keys()
            .map(String::as_str)
            .collect();
        assert_eq!(
            keys,
            [
                "destination",
                "kind",
                "port",
                "protocol",
                "addresses",
                "sources",
                "flags",
                "first_seen"
            ]
        );
        let destination = row["destination"].as_str().unwrap();
        assert!(destination.len() <= 253 && matches(r"^[a-z0-9._-]+$", destination));
        assert!(["name", "address"].contains(&row["kind"].as_str().unwrap()));
        assert!(row["port"].is_null() || (1..=65535).contains(&row["port"].as_u64().unwrap()));
        assert!(
            row["protocol"].is_null()
                || ["tcp", "udp"].contains(&row["protocol"].as_str().unwrap())
        );
        let addresses = row["addresses"].as_array().unwrap();
        assert!(addresses.len() <= 8);
        assert!(addresses
            .iter()
            .all(|a| a.as_str().unwrap().parse::<Ipv4Addr>().is_ok()));
        let sources = row["sources"].as_array().unwrap();
        assert!((1..=2).contains(&sources.len()));
        assert!(sources
            .iter()
            .all(|s| ["conn", "dns"].contains(&s.as_str().unwrap())));
        let flags = row["flags"].as_array().unwrap();
        assert!(flags.len() <= 3);
        assert!(flags
            .iter()
            .all(|f| ["dot", "dns", "doh"].contains(&f.as_str().unwrap())));
        assert!(matches(
            r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$",
            row["first_seen"].as_str().unwrap()
        ));
    }
}

fn data_of(observer: &Observer) -> Value {
    let facts: Vec<_> = observer.facts().collect();
    assert_eq!(facts.len(), 1);
    assert_eq!(facts[0].0, "beast-tvm-3");
    assert_gateway_shape(&facts[0].0, &facts[0].1);
    facts[0].1.clone()
}

fn rows(data: &Value) -> Vec<Value> {
    data["rows"].as_array().unwrap().clone()
}

fn row<'a>(rows: &'a [Value], destination: &str, port: Option<u64>) -> &'a Value {
    rows.iter()
        .find(|row| row["destination"] == destination && row["port"] == json!(port))
        .unwrap_or_else(|| panic!("no row for {destination} {port:?} in {rows:?}"))
}

// Parsers

#[test]
fn a_query_line_gives_serial_client_and_lower_case_name() {
    assert_eq!(
        parse_dns_line(&query(5, "Registry.NPMJS.org")),
        Some(DnsLine::Query {
            serial: 5,
            client: ip(VM_IP),
            name: "registry.npmjs.org".into()
        })
    );
}

#[test]
fn a_reply_cached_or_config_answer_with_an_ipv4_address_is_an_answer() {
    for verb in ["reply", "cached", "config"] {
        let line = dns(&format!(
            "7 {VM_IP}/43210 {verb} registry.npmjs.org is 104.16.1.34"
        ));
        assert_eq!(
            parse_dns_line(&line),
            Some(DnsLine::Answer {
                serial: 7,
                address: ip("104.16.1.34")
            }),
            "{verb}"
        );
    }
}

#[test]
fn lines_that_are_not_ipv4_questions_or_answers_are_ignored() {
    let lines = [
        dns(&format!(
            "5 {VM_IP}/43210 query[AAAA] example.com from {VM_IP}"
        )),
        dns(&format!(
            "5 {VM_IP}/43210 query[PTR] 4.3.2.1.in-addr.arpa from {VM_IP}"
        )),
        reply(5, "www.example.com", "<CNAME>"),
        reply(5, "example.com", "NXDOMAIN"),
        reply(5, "example.com", "NODATA"),
        reply(5, "example.com", "NODATA-IPv4"),
        reply(5, "example.com", "2606:4700::6810:122"),
        dns("5 10.251.78.1/53 forwarded example.com to 9.9.9.9"),
        dns("DHCPACK(orbittask0) 10.251.78.14 10:66:6a:f0:65:d4 beast-tvm-3"),
        // Without log-queries=extra there is no serial.
        dns(&format!("query[A] example.com from {VM_IP}")),
        query(5, "bad!name.example.com"),
        query(5, &"a".repeat(254)),
        "not a dnsmasq line at all".into(),
    ];
    for line in lines {
        assert_eq!(parse_dns_line(&line), None, "{line}");
    }
    assert!(parse_dns_line(&query(5, &"a".repeat(253))).is_some());
}

#[test]
fn a_connection_line_gives_source_destination_protocol_and_port_whatever_the_prefix() {
    let wanted = Connection {
        interface: Some("orbittask0".into()),
        frame: Frame::Source(MAC.into()),
        source: ip(VM_IP),
        destination: ip("140.82.121.4"),
        protocol: Proto::Tcp,
        port: 443,
    };
    let full = connection(VM_IP, "140.82.121.4", "TCP", 443);
    assert_eq!(parse_connection_line(&full), Some(wanted));
    let bare = full.split_once("orbit-egress ").unwrap().1;
    assert!(parse_connection_line(&format!("orbit-egress {bare}")).is_some());
    let udp = parse_connection_line(&connection(VM_IP, "8.8.8.8", "UDP", 53)).unwrap();
    assert_eq!((udp.protocol, udp.port), (Proto::Udp, 53));
}

/// The line the kernel 7.0 on shark logged, byte for byte after the time stamp.
const SHARK_LINE: &str = "kernel: orbit-egress IN=orbittask0 OUT=enp3s0 MACSRC=10:66:6a:25:ff:26 \
MACDST=10:66:6a:ec:52:0e MACPROTO=0800 SRC=10.251.78.3 DST=140.82.121.3 LEN=60 TOS=0x00 PREC=0x00 TTL=63 ID=7783 DF \
PROTO=TCP SPT=48336 DPT=443 WINDOW=64240 RES=0x00 SYN URGP=0";

#[test]
fn the_line_a_real_kernel_logs_names_the_vm_by_macsrc() {
    assert_eq!(
        parse_connection_line(SHARK_LINE),
        Some(Connection {
            interface: Some("orbittask0".into()),
            frame: Frame::Source("10:66:6a:25:ff:26".into()),
            source: ip("10.251.78.3"),
            destination: ip("140.82.121.3"),
            protocol: Proto::Tcp,
            port: 443,
        })
    );
    let udp = SHARK_LINE
        .replace("PROTO=TCP", "PROTO=UDP")
        .replace("DPT=443", "DPT=53");
    let udp = parse_connection_line(&udp).unwrap();
    assert_eq!((udp.protocol, udp.port), (Proto::Udp, 53));
}

#[test]
fn macsrc_wins_over_the_older_mac_field_and_the_older_form_still_works() {
    let frame = |line: String| parse_connection_line(&line).unwrap().frame;
    assert_eq!(
        frame(legacy_mac(MAC, VM_IP, "140.82.121.4", "TCP", 443)),
        Frame::Source(MAC.into())
    );
    let both = format!(
        "{} MAC=aa:bb:cc:dd:ee:ff:10:66:6a:00:00:99:08:00",
        connection(VM_IP, "140.82.121.4", "TCP", 443)
    );
    assert_eq!(frame(both), Frame::Source(MAC.into()));
    let only_legacy = legacy_mac("10:66:6A:F0:65:D4", VM_IP, "140.82.121.4", "TCP", 443);
    assert_eq!(frame(only_legacy), Frame::Source(MAC.into()));
}

#[test]
fn a_malformed_mac_field_names_no_vm_and_no_field_names_none_either() {
    let with = |field: &str| {
        let line = format!("{} {field}", without_mac(VM_IP, "140.82.121.4", "TCP", 443));
        parse_connection_line(&line).unwrap().frame
    };
    assert_eq!(with("MACSRC=10:66:6a:f0:65"), Frame::Unusable);
    assert_eq!(with("MACSRC=10:66:6a:f0:65:zz"), Frame::Unusable);
    assert_eq!(with("MACSRC="), Frame::Unusable);
    assert_eq!(with("MACSRC=10:66:6A:F0:65:D4"), Frame::Source(MAC.into()));
    // A bad MACSRC is not rescued by the older field.
    assert_eq!(
        with("MACSRC=nonsense MAC=aa:bb:cc:dd:ee:ff:10:66:6a:f0:65:d4:08:00"),
        Frame::Unusable
    );
    assert_eq!(
        with("MAC=aa:bb:cc:dd:ee:ff:10:66:6a:f0:65:d4"),
        Frame::Unusable
    );
    assert_eq!(
        with("MAC=aa:bb:cc:dd:ee:ff:10:66:6a:f0:65:zz:08:00"),
        Frame::Unusable
    );
    assert_eq!(with("MAC="), Frame::Unusable);
    let bare = parse_connection_line(&without_mac(VM_IP, "140.82.121.4", "TCP", 443)).unwrap();
    assert_eq!(bare.frame, Frame::Absent);
}

#[test]
fn a_connection_line_without_what_the_agent_needs_is_ignored() {
    let full = connection(VM_IP, "140.82.121.4", "TCP", 443);
    assert_eq!(
        parse_connection_line(&connection(VM_IP, "1.2.3.4", "ICMP", 1)),
        None
    );
    assert_eq!(
        parse_connection_line(&connection(VM_IP, "1.2.3.4", "TCP", 0)),
        None
    );
    assert_eq!(
        parse_connection_line(&full.replace("orbit-egress ", "")),
        None
    );
    assert_eq!(parse_connection_line(&full.replace("DPT=443", "")), None);
    assert_eq!(
        parse_connection_line(&full.replace("DST=140.82.121.4", "DST=nope")),
        None
    );
    assert_eq!(parse_connection_line(""), None);
}

#[test]
fn only_public_addresses_are_outside() {
    for outside in [
        "8.8.8.8",
        "140.82.121.4",
        "172.32.0.1",
        "100.63.255.255",
        "100.128.0.1",
    ] {
        assert!(is_outside(ip(outside)), "{outside}");
    }
    for inside in [
        "10.0.0.1",
        "172.16.0.1",
        "172.31.255.255",
        "192.168.1.1",
        "127.0.0.1",
        "169.254.1.1",
        "100.64.0.1",
        "100.127.255.255",
        "224.0.0.251",
        "239.255.255.250",
        "240.0.0.1",
        "255.255.255.255",
        "0.0.0.0",
        "0.1.2.3",
    ] {
        assert!(!is_outside(ip(inside)), "{inside}");
    }
}

#[test]
fn host_files_and_leases_give_the_mac_name_and_address() {
    assert_eq!(
        parse_host_file(&format!("{MAC},beast-tvm-3\n")),
        Some((MAC.into(), "beast-tvm-3".into()))
    );
    assert_eq!(
        parse_host_file("10:66:6A:F0:65:D4,10.251.78.14,beast-tvm-3\n"),
        Some((MAC.into(), "beast-tvm-3".into()))
    );
    for bad in [
        "",
        "nonsense\n",
        "10:66:6a:f0:65,x\n",
        &format!("{MAC}\n"),
        &format!("{MAC},-bad\n"),
        &format!("{MAC},Beast-1\n"),
        &format!("{MAC},{}\n", "a".repeat(64)),
    ] {
        assert_eq!(parse_host_file(bad), None, "{bad:?}");
    }
    assert!(parse_host_file(&format!("{MAC},{}\n", "a".repeat(63))).is_some());
    let leases = format!(
        "duid 00:01:00:01:2a:5f\n{}1794200001 12345 fd42::5 other 00:01\n{}",
        lease(MAC, VM_IP, "beast-tvm-3"),
        lease("10:66:6a:00:00:01", "10.251.78.15", "*"),
    );
    let identity = Identity::parse([format!("{MAC},beast-tvm-3").as_str()], &leases);
    assert_eq!(identity.names.len(), 1);
    assert_eq!(identity.leases.len(), 2);
    assert_eq!(identity.leases[&ip(VM_IP)], MAC);
}

#[test]
fn the_agent_reads_orbits_own_dns_log_and_not_the_one_incus_truncates() {
    let paths = Paths::for_network("orbittask0");
    assert_eq!(
        paths.dns_log,
        Path::new("/var/log/orbit/dnsmasq-orbittask0.log")
    );
    assert_eq!(
        paths.connection_log,
        Path::new("/var/log/orbit/task-egress.log")
    );
    assert_eq!(
        paths.hosts_dir,
        Path::new("/var/lib/incus/networks/orbittask0/dnsmasq.hosts")
    );
    assert_eq!(
        paths.leases,
        Path::new("/var/lib/incus/networks/orbittask0/dnsmasq.leases")
    );
}

// Serial map

#[test]
fn a_question_is_forgotten_after_two_minutes() {
    let (mut serials, start) = (Serials::default(), Instant::now());
    serials.insert(1, ip(VM_IP), "example.com".into(), start);
    assert!(serials.get(1, start + Duration::from_secs(119)).is_some());
    assert!(serials.get(1, start + Duration::from_secs(120)).is_none());
    serials.insert(
        2,
        ip(VM_IP),
        "other.com".into(),
        start + Duration::from_secs(121),
    );
    assert!(!serials.asked.contains_key(&1));
    assert_eq!(serials.order.len(), 1);
}

#[test]
fn the_question_map_holds_at_most_8192_and_drops_the_oldest() {
    let (mut serials, start) = (Serials::default(), Instant::now());
    for serial in 0..8200 {
        serials.insert(serial, ip(VM_IP), "example.com".into(), start);
    }
    assert_eq!(serials.asked.len(), SERIAL_CAP);
    assert!(serials.get(0, start).is_none());
    assert!(serials.get(7, start).is_none());
    assert!(serials.get(8, start).is_some());
    assert!(serials.get(8199, start).is_some());
}

// Offsets

#[test]
fn a_log_is_followed_from_its_end_by_complete_lines() {
    let world = World::new();
    let path = world.paths.dns_log.clone();
    world.append(&path, "history 1\nhistory 2\n");
    let mut tail = Tail::default();
    assert!(
        tail.read(&path, MAX_READ).unwrap().is_empty(),
        "first sight starts at the end"
    );
    assert!(tail.read(&path, MAX_READ).unwrap().is_empty());
    world.append(&path, "one\ntwo\npart");
    assert_eq!(tail.read(&path, MAX_READ).unwrap(), ["one", "two"]);
    world.append(&path, "ial\nthree\n");
    assert_eq!(tail.read(&path, MAX_READ).unwrap(), ["partial", "three"]);
    assert!(tail.read(&path, MAX_READ).unwrap().is_empty());
}

#[test]
fn a_log_that_shrinks_is_read_again_from_the_start() {
    let world = World::new();
    let path = world.paths.dns_log.clone();
    let mut tail = Tail::default();
    world.append(&path, "a long first line that will be cut away\n");
    tail.read(&path, MAX_READ).unwrap();
    std::fs::write(&path, "new\n").unwrap();
    assert_eq!(tail.read(&path, MAX_READ).unwrap(), ["new"]);
}

#[test]
fn a_rotated_log_with_a_new_inode_is_read_from_the_start() {
    let world = World::new();
    let path = world.paths.dns_log.clone();
    let mut tail = Tail::default();
    world.append(&path, "old\n");
    tail.read(&path, MAX_READ).unwrap();
    std::fs::rename(&path, path.with_extension("log.1")).unwrap();
    assert!(
        tail.read(&path, MAX_READ).unwrap().is_empty(),
        "the file is gone for a moment"
    );
    // The new file is longer than the old offset, so only the inode shows the rotation.
    world.append(&path, "fresh one\nfresh two\n");
    assert_eq!(
        tail.read(&path, MAX_READ).unwrap(),
        ["fresh one", "fresh two"]
    );
}

#[test]
fn a_read_takes_at_most_the_limit_and_skips_a_line_longer_than_it() {
    let world = World::new();
    let path = world.paths.dns_log.clone();
    let mut tail = Tail::default();
    tail.read(&path, 16).unwrap();
    world.append(&path, &format!("{}\nshort\nlast\n", "x".repeat(40)));
    let mut seen = Vec::new();
    for _ in 0..10 {
        seen.extend(tail.read(&path, 16).unwrap());
    }
    assert!(seen.contains(&"short".to_owned()));
    assert!(seen.contains(&"last".to_owned()));
    assert!(!seen.iter().any(|line| line.len() > 16));
}

// The join

#[test]
fn a_connection_to_an_address_the_vm_resolved_is_a_row_of_the_name() {
    let world = World::new();
    let mut observer = world.observer();
    world.dns(&[query(1, "history.example.com")]);
    observe(&mut observer, STAMP);
    assert!(
        rows(&data_of(&observer)).is_empty(),
        "history is not replayed"
    );

    world.dns(&[
        query(2, "registry.npmjs.org"),
        reply(2, "registry.npmjs.org", "104.16.1.34"),
    ]);
    world.connections(&[connection(VM_IP, "104.16.1.34", "TCP", 443)]);
    observe(&mut observer, STAMP);

    assert_eq!(
        data_of(&observer),
        json!({
            "network": "orbittask0",
            "mac": MAC,
            "rows": [{
                "destination": "registry.npmjs.org", "kind": "name", "port": 443, "protocol": "tcp",
                "addresses": ["104.16.1.34"], "sources": ["conn", "dns"], "flags": [],
                "first_seen": STAMP
            }],
            "overflow": 0
        })
    );
}

#[test]
fn an_address_in_a_cname_chain_counts_for_the_name_the_vm_asked_for() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.dns(&[
        query(3, "www.example.com"),
        reply(3, "www.example.com", "<CNAME>"),
        reply(3, "cdn.example.net", "<CNAME>"),
        reply(3, "edge.example.net", "104.16.1.34"),
        reply(3, "edge.example.net", "104.16.1.35"),
        dns(&format!(
            "4 {VM_IP}/43210 cached www.example.com is 104.16.1.34"
        )),
    ]);
    world.connections(&[connection(VM_IP, "104.16.1.35", "TCP", 443)]);
    observe(&mut observer, STAMP);

    let data = data_of(&observer);
    let rows = rows(&data);
    assert_eq!(rows.len(), 1, "{rows:?}");
    let found = row(&rows, "www.example.com", Some(443));
    assert_eq!(found["addresses"], json!(["104.16.1.35"]));
    assert_eq!(found["sources"], json!(["conn", "dns"]));
}

#[test]
fn nxdomain_and_nodata_answers_make_no_row() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.dns(&[
        query(1, "missing.example.com"),
        reply(1, "missing.example.com", "NXDOMAIN"),
        query(2, "empty.example.com"),
        reply(2, "empty.example.com", "NODATA-IPv4"),
    ]);
    observe(&mut observer, STAMP);
    assert!(rows(&data_of(&observer)).is_empty());
}

#[test]
fn an_answer_that_comes_after_two_minutes_is_not_credited() {
    let world = World::new();
    let mut observer = world.observer();
    let start = Instant::now();
    assert_eq!(observer.observe(start, STAMP), []);
    world.dns(&[query(9, "slow.example.com")]);
    assert_eq!(observer.observe(start, STAMP), []);
    world.dns(&[reply(9, "slow.example.com", "93.184.216.34")]);
    assert_eq!(
        observer.observe(start + Duration::from_secs(121), STAMP),
        []
    );
    assert!(rows(&data_of(&observer)).is_empty());
}

#[test]
fn an_address_the_vm_never_resolved_is_an_address_row() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.connections(&[connection(VM_IP, "185.199.108.133", "TCP", 22)]);
    observe(&mut observer, STAMP);

    assert_eq!(
        rows(&data_of(&observer)),
        [json!({
            "destination": "185.199.108.133", "kind": "address", "port": 22, "protocol": "tcp",
            "addresses": ["185.199.108.133"], "sources": ["conn"], "flags": [], "first_seen": STAMP
        })]
    );
}

#[test]
fn a_name_the_vm_resolved_but_never_connected_to_is_a_dns_row_until_it_is() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.dns(&[
        query(1, "lonely.example.org"),
        reply(1, "lonely.example.org", "93.184.216.34"),
    ]);
    observe(&mut observer, STAMP);

    assert_eq!(
        rows(&data_of(&observer)),
        [json!({
            "destination": "lonely.example.org", "kind": "name", "port": null, "protocol": null,
            "addresses": ["93.184.216.34"], "sources": ["dns"], "flags": [], "first_seen": STAMP
        })]
    );

    world.connections(&[connection(VM_IP, "93.184.216.34", "UDP", 443)]);
    observe(&mut observer, STAMP);
    let data = data_of(&observer);
    let rows = rows(&data);
    assert_eq!(
        rows.len(),
        1,
        "the connection row tells the name now: {rows:?}"
    );
    assert_eq!(
        row(&rows, "lonely.example.org", Some(443))["protocol"],
        "udp"
    );
}

#[test]
fn a_dns_answer_with_a_private_address_makes_no_row() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.dns(&[
        query(1, "internal.orbit"),
        reply(1, "internal.orbit", "10.0.0.7"),
    ]);
    observe(&mut observer, STAMP);
    assert!(rows(&data_of(&observer)).is_empty());
}

#[test]
fn a_private_destination_is_dropped() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.connections(&[
        connection(VM_IP, "10.0.0.5", "TCP", 22),
        connection(VM_IP, "169.254.169.254", "TCP", 80),
        connection(VM_IP, "224.0.0.251", "UDP", 5353),
        "garbage".into(),
    ]);
    observe(&mut observer, STAMP);
    assert!(rows(&data_of(&observer)).is_empty());
}

#[test]
fn the_mac_of_the_log_line_names_the_vm_and_the_lease_is_the_fallback_only_without_it() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.connections(&[
        // The VM's MAC wins over a source address that no lease holds.
        from_mac(MAC, "10.251.78.99", "140.82.121.1", "TCP", 443),
        // Another MAC is no VM, whatever its source address says.
        from_mac("10:66:6a:00:00:99", VM_IP, "140.82.121.2", "TCP", 443),
        // Without the field the source address and the lease name the VM.
        without_mac(VM_IP, "140.82.121.3", "TCP", 443),
        without_mac("10.251.78.99", "140.82.121.4", "TCP", 443),
        // The older form names the VM too.
        legacy_mac(MAC, "10.251.78.99", "140.82.121.6", "TCP", 443),
        // A field of another shape names no VM, and no guess is made from the address.
        connection(VM_IP, "140.82.121.5", "TCP", 443).replace(MAC, "10:66:6a"),
    ]);
    observe(&mut observer, STAMP);

    let data = data_of(&observer);
    let mut destinations: Vec<String> = rows(&data)
        .iter()
        .map(|row| row["destination"].as_str().unwrap().to_owned())
        .collect();
    destinations.sort();
    assert_eq!(
        destinations,
        ["140.82.121.1", "140.82.121.3", "140.82.121.6"]
    );
}

#[test]
fn dot_dns_and_doh_connections_carry_a_flag() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.connections(&[
        connection(VM_IP, "9.9.9.9", "TCP", 853),
        connection(VM_IP, "4.2.2.2", "UDP", 53),
        connection(VM_IP, "1.1.1.1", "TCP", 443),
        connection(VM_IP, "8.8.8.9", "TCP", 443),
        connection(VM_IP, "1.1.1.1", "TCP", 80),
    ]);
    observe(&mut observer, STAMP);
    let data = data_of(&observer);
    let rows = rows(&data);
    assert_eq!(row(&rows, "9.9.9.9", Some(853))["flags"], json!(["dot"]));
    assert_eq!(row(&rows, "4.2.2.2", Some(53))["flags"], json!(["dns"]));
    assert_eq!(row(&rows, "1.1.1.1", Some(443))["flags"], json!(["doh"]));
    assert_eq!(row(&rows, "8.8.8.9", Some(443))["flags"], json!([]));
    assert_eq!(row(&rows, "1.1.1.1", Some(80))["flags"], json!([]));
}

#[test]
fn first_seen_never_changes_and_a_later_connection_adds_an_address() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.dns(&[
        query(1, "cdn.example.com"),
        reply(1, "cdn.example.com", "104.16.1.34"),
        reply(1, "cdn.example.com", "104.16.1.35"),
    ]);
    world.connections(&[connection(VM_IP, "104.16.1.34", "TCP", 443)]);
    observe(&mut observer, STAMP);
    world.connections(&[
        connection(VM_IP, "104.16.1.35", "TCP", 443),
        connection(VM_IP, "104.16.1.34", "TCP", 443),
    ]);
    observe(&mut observer, "2026-11-07T19:30:00Z");

    let data = data_of(&observer);
    let rows = rows(&data);
    let found = row(&rows, "cdn.example.com", Some(443));
    assert_eq!(found["first_seen"], STAMP);
    assert_eq!(found["addresses"], json!(["104.16.1.34", "104.16.1.35"]));
}

#[test]
fn a_vm_that_no_host_file_or_lease_names_loses_its_state_and_a_reused_name_starts_fresh() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.connections(&[connection(VM_IP, "140.82.121.4", "TCP", 443)]);
    observe(&mut observer, STAMP);
    assert_eq!(rows(&data_of(&observer)).len(), 1);

    // The same name comes back with another MAC: a new VM.
    world.set_vm(
        "project_beast-tvm-3.eth0",
        "10:66:6a:aa:bb:cc",
        "beast-tvm-3",
        VM_IP,
    );
    observe(&mut observer, "2026-11-07T20:00:00Z");
    let data = data_of(&observer);
    assert_eq!(data["mac"], "10:66:6a:aa:bb:cc");
    assert!(rows(&data).is_empty());

    // The VM is deleted.
    std::fs::remove_file(world.paths.hosts_dir.join("project_beast-tvm-3.eth0")).unwrap();
    std::fs::write(&world.paths.leases, "").unwrap();
    observe(&mut observer, STAMP);
    assert_eq!(observer.facts().count(), 0);
    assert!(observer.vms.is_empty() && observer.known().is_empty());
}

#[test]
fn a_vm_without_a_host_file_name_is_not_reported_and_a_lease_hostname_is_never_used() {
    let world = World::new();
    std::fs::write(&world.paths.leases, lease(MAC, VM_IP, "evil-name")).unwrap();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    assert_eq!(observer.facts().next().unwrap().0, "beast-tvm-3");
    std::fs::remove_file(world.paths.hosts_dir.join("project_beast-tvm-3.eth0")).unwrap();
    observe(&mut observer, STAMP);
    assert_eq!(observer.facts().count(), 0);
    assert!(observer.known().is_empty());
}

#[test]
fn the_agent_tracks_at_most_64_vms_and_keeps_the_ones_it_saw_last() {
    let hosts: Vec<String> = (0..70)
        .map(|index| format!("10:66:6a:00:00:{index:02x},vm-{index}"))
        .collect();
    let identity = |count: usize| Identity::parse(hosts[..count].iter().map(String::as_str), "");
    let mut observer = World::new().observer();
    observer.apply_identity(identity(70));
    assert_eq!(observer.vms.len(), 64);
    assert!(!observer.vms.contains_key("10:66:6a:00:00:00"));
    assert!(observer.vms.contains_key("10:66:6a:00:00:06"));
    observer.apply_identity(identity(70));
    assert_eq!(observer.vms.len(), 64, "the set is stable");
    assert!(observer.vms.contains_key("10:66:6a:00:00:06"));
    assert_eq!(observer.known().len(), 64);
}

// Unreadable files

#[test]
fn a_file_the_agent_cannot_open_is_not_available_and_changes_nothing() {
    if rustix::process::geteuid().is_root() {
        return;
    }
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    world.connections(&[connection(VM_IP, "140.82.121.4", "TCP", 443)]);
    observe(&mut observer, STAMP);
    assert_eq!(rows(&data_of(&observer)).len(), 1);

    let path = world.paths.connection_log.clone();
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o000)).unwrap();
    let problems = observer.observe(Instant::now(), STAMP);
    assert_eq!(
        problems,
        [Unreadable::new(&path, ErrorKind::PermissionDenied)]
    );
    assert_eq!(
        observer.facts().count(),
        0,
        "no fact while a source is unavailable"
    );
    assert_eq!(observer.known(), BTreeSet::from(["beast-tvm-3".to_owned()]));

    // The line written meanwhile is not lost.
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o644)).unwrap();
    world.connections(&[connection(VM_IP, "140.82.121.5", "TCP", 443)]);
    observe(&mut observer, STAMP);
    assert_eq!(rows(&data_of(&observer)).len(), 2);

    // The same holds for the identity files and the DNS log.
    for unreadable in [world.paths.leases.clone(), world.paths.dns_log.clone()] {
        std::fs::set_permissions(&unreadable, std::fs::Permissions::from_mode(0o000)).unwrap();
        let problems = observer.observe(Instant::now(), STAMP);
        assert_eq!(
            problems,
            [Unreadable::new(&unreadable, ErrorKind::PermissionDenied)]
        );
        assert_eq!(observer.facts().count(), 0);
        std::fs::set_permissions(&unreadable, std::fs::Permissions::from_mode(0o644)).unwrap();
    }
    observe(&mut observer, STAMP);
    assert_eq!(rows(&data_of(&observer)).len(), 2);
}

#[test]
fn missing_identity_files_mean_no_fact_and_no_panic() {
    let world = World::new();
    let mut observer = world.observer();
    std::fs::remove_file(&world.paths.leases).unwrap();
    let problems = observer.observe(Instant::now(), STAMP);
    assert_eq!(
        problems,
        [Unreadable::new(&world.paths.leases, ErrorKind::NotFound)]
    );
    assert_eq!(observer.facts().count(), 0);
}

#[test]
fn logs_that_do_not_exist_yet_read_as_empty_and_are_read_from_their_start_once_they_appear() {
    let world = World::new();
    std::fs::remove_file(&world.paths.dns_log).unwrap();
    std::fs::remove_file(&world.paths.connection_log).unwrap();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    assert!(rows(&data_of(&observer)).is_empty(), "no log is no problem");

    // rsyslog and dnsmasq create their logs on the first line.
    world.dns(&[
        query(1, "registry.npmjs.org"),
        reply(1, "registry.npmjs.org", "104.16.1.34"),
    ]);
    world.connections(&[connection(VM_IP, "104.16.1.34", "TCP", 443)]);
    observe(&mut observer, STAMP);
    let data = data_of(&observer);
    assert_eq!(
        row(&rows(&data), "registry.npmjs.org", Some(443))["sources"],
        json!(["conn", "dns"])
    );
}

#[test]
fn only_a_mac_with_a_host_file_is_tracked_and_a_lease_alone_is_not() {
    let world = World::new();
    std::fs::write(
        &world.paths.leases,
        format!(
            "{}{}",
            lease(MAC, VM_IP, "beast-tvm-3"),
            lease("10:66:6a:00:00:77", "10.251.78.77", "stray")
        ),
    )
    .unwrap();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    assert_eq!(observer.vms.len(), 1);
    assert!(!observer.vms.contains_key("10:66:6a:00:00:77"));
    // Its connections find no VM, even by the MAC in the log line.
    world.connections(&[from_mac(
        "10:66:6a:00:00:77",
        "10.251.78.77",
        "140.82.121.9",
        "TCP",
        443,
    )]);
    observe(&mut observer, STAMP);
    assert!(rows(&data_of(&observer)).is_empty());
}

#[test]
fn a_connection_line_of_another_bridge_or_without_a_bridge_is_ignored() {
    let world = World::new();
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    let other =
        connection(VM_IP, "140.82.121.6", "TCP", 443).replace("IN=orbittask0", "IN=orbittask1");
    let none = connection(VM_IP, "140.82.121.7", "TCP", 443).replace("IN=orbittask0 ", "");
    world.connections(&[other, none, connection(VM_IP, "140.82.121.8", "TCP", 443)]);
    observe(&mut observer, STAMP);
    let data = data_of(&observer);
    let destinations: Vec<&str> = data["rows"]
        .as_array()
        .unwrap()
        .iter()
        .map(|row| row["destination"].as_str().unwrap())
        .collect();
    assert_eq!(destinations, ["140.82.121.8"]);
}

#[test]
fn the_agent_complains_about_a_path_once_in_ten_minutes_without_content() {
    let (mut complaints, start) = (Complaints::default(), Instant::now());
    let problem = Unreadable::new(
        Path::new("/var/log/orbit/task-egress.log"),
        ErrorKind::PermissionDenied,
    );
    let other = Unreadable::new(
        Path::new("/var/log/orbit/dnsmasq-orbittask0.log"),
        ErrorKind::NotFound,
    );
    assert_eq!(
        complaints.note(&problem, start).unwrap(),
        "orbit-agent: egress cannot read /var/log/orbit/task-egress.log: permission denied"
    );
    assert_eq!(
        complaints.note(&problem, start + Duration::from_secs(599)),
        None
    );
    assert!(complaints
        .note(&other, start + Duration::from_secs(599))
        .is_some());
    assert!(complaints
        .note(&problem, start + Duration::from_secs(600))
        .is_some());
}

// Roll-up

fn outside(index: u32) -> Ipv4Addr {
    Ipv4Addr::from(0x0b00_0000 + index)
}

/// One VM and the budget it draws on.
struct Lab {
    shared: Shared,
    vm: Vm,
}

impl Lab {
    fn new() -> Self {
        let mut shared = Shared::new(BUDGET);
        let vm = Vm::new(&mut shared).unwrap();
        Self { shared, vm }
    }

    fn answer(&mut self, name: &str, address: Ipv4Addr, at: Instant) {
        self.vm.answer(&mut self.shared, name, address, at, STAMP);
    }

    fn connect(&mut self, address: Ipv4Addr, protocol: Proto, port: u16, at: Instant) {
        self.vm
            .connect(&mut self.shared, address, protocol, port, at, STAMP);
    }

    fn data(&self) -> Value {
        self.vm.data("orbittask0", MAC)
    }
}

#[test]
fn a_row_lists_at_most_eight_addresses() {
    let (mut lab, now) = (Lab::new(), Instant::now());
    for index in 0..12 {
        lab.answer("many.example.com", outside(index), now);
    }
    let data = lab.data();
    let rows = rows(&data);
    assert_eq!(rows.len(), 1);
    assert_eq!(rows[0]["addresses"].as_array().unwrap().len(), 8);
    assert_eq!(rows[0]["addresses"][0], "11.0.0.0");
}

#[test]
fn a_vm_keeps_1500_rows_and_counts_each_refused_row_once() {
    let (mut lab, now) = (Lab::new(), Instant::now());
    for index in 0..1505 {
        lab.connect(outside(index), Proto::Tcp, 443, now);
    }
    let data = lab.data();
    assert_eq!(rows(&data).len(), 1500);
    assert_eq!(data["overflow"], 5);
    // The same refused rows again, and a row that exists, change nothing.
    for _ in 0..50 {
        for index in 1495..1505 {
            lab.connect(outside(index), Proto::Tcp, 443, now);
        }
    }
    assert_eq!(lab.data(), data);
    assert_gateway_shape("beast-tvm-3", &data);
}

#[test]
fn a_saturated_vm_does_not_change_its_fact_or_become_dirty_by_repeats() {
    let (mut lab, now) = (Lab::new(), Instant::now());
    for index in 0..1501 {
        lab.connect(outside(index), Proto::Tcp, 443, now);
    }
    lab.vm.dirty = false;
    let before = fact_digest(&lab.data());
    for _ in 0..100 {
        lab.connect(outside(1500), Proto::Tcp, 443, now);
        lab.answer("refused.example.com", outside(9000), now);
    }
    // The first refused name counts once; then nothing moves.
    assert!(lab.vm.dirty);
    lab.vm.dirty = false;
    let after = fact_digest(&lab.data());
    assert_ne!(before, after);
    for _ in 0..100 {
        lab.connect(outside(1500), Proto::Tcp, 443, now);
        lab.answer("refused.example.com", outside(9000), now);
    }
    assert!(!lab.vm.dirty);
    assert_eq!(fact_digest(&lab.data()), after);
    assert_eq!(lab.data()["overflow"], 2);
}

#[test]
fn a_full_fact_of_the_longest_rows_stays_below_the_one_mebibyte_the_gateway_accepts() {
    let (mut lab, now) = (Lab::new(), Instant::now());
    for index in 0..1500 {
        let name = format!("{}{index:04}", "a".repeat(249));
        for address in 0..8 {
            lab.answer(&name, outside(index * 8 + address), now);
        }
    }
    let data = lab.data();
    assert_eq!(rows(&data).len(), 1500);
    assert!(rows(&data)
        .iter()
        .all(|row| row["addresses"].as_array().unwrap().len() == 8));
    assert!(
        data.to_string().len() < 1 << 20,
        "{}",
        data.to_string().len()
    );
    assert_gateway_shape("beast-tvm-3", &data);
}

#[test]
fn several_full_vms_stay_inside_the_byte_budget_and_give_it_back() {
    let now = Instant::now();
    let mut shared = Shared::new(BUDGET);
    let mut vms: Vec<Vm> = (0..MAX_VMS)
        .map(|_| Vm::new(&mut shared).unwrap())
        .collect();
    for (number, vm) in vms.iter_mut().enumerate() {
        for index in 0..1500 {
            let name = format!("{}{number:02}{index:04}", "a".repeat(247));
            vm.answer(
                &mut shared,
                &name,
                outside((number * 2000 + index) as u32),
                now,
                STAMP,
            );
        }
    }
    assert!(shared.used <= BUDGET, "{} > {BUDGET}", shared.used);
    assert!(
        shared.used > BUDGET / 10 * 9,
        "the budget is really reached: {}",
        shared.used
    );
    // The accounted bytes are what the entries cost, so nothing leaks.
    let pool: usize = shared
        .pool
        .iter()
        .map(|name| name.len() + NAME_OVERHEAD)
        .sum();
    let held: usize = vms.iter().map(|vm| vm.bytes).sum();
    assert_eq!(shared.used, pool + held);
    for vm in &vms {
        assert_eq!(
            vm.bytes,
            VM_BASE + vm.rows.len() * ROW_COST + vm.pairs * PAIR_COST
        );
    }
    let refused: u64 = vms.iter().map(|vm| vm.overflow).sum();
    assert!(refused > 0, "the budget refused rows and counted them");
    assert!(vms.iter().map(|vm| vm.rows.len()).sum::<usize>() < MAX_VMS * 1500);

    for vm in vms.drain(..) {
        shared.release(vm.bytes);
    }
    shared.prune();
    assert_eq!(shared.used, 0);
    assert!(shared.pool.is_empty());
}

#[test]
fn a_vm_remembers_at_most_1024_pairs_and_drops_the_oldest_answered() {
    let (mut lab, start) = (Lab::new(), Instant::now());
    for index in 0..1500u32 {
        let name = lab
            .shared
            .intern(&format!("host-{index}.example.com"))
            .unwrap();
        lab.vm.learn(
            &mut lab.shared,
            &name,
            outside(index),
            start + Duration::from_secs(u64::from(index)),
        );
    }
    assert_eq!(lab.vm.pairs, MAX_RESOLVED);
    assert_eq!(
        lab.vm.resolved.values().map(Vec::len).sum::<usize>(),
        MAX_RESOLVED
    );
    assert!(!lab.vm.resolved.contains_key(&outside(0)));
    assert!(lab.vm.resolved.contains_key(&outside(1499)));
}

#[test]
fn a_ninth_name_replaces_the_oldest_name_of_an_address() {
    let (mut lab, start) = (Lab::new(), Instant::now());
    let address = ip("104.16.1.34");
    for index in 0..9u64 {
        lab.answer(
            &format!("n{index}.example.com"),
            address,
            start + Duration::from_secs(index),
        );
    }
    let at = start + Duration::from_secs(10);
    let mut names: Vec<String> = lab
        .vm
        .fresh_names(address, at)
        .iter()
        .map(|name| name.to_string())
        .collect();
    names.sort();
    assert_eq!(names.len(), 8);
    assert!(
        names.contains(&"n8.example.com".to_owned()),
        "the newest is kept"
    );
    assert!(
        !names.contains(&"n0.example.com".to_owned()),
        "the oldest is gone"
    );
    // A repeated answer renews a name instead of adding one.
    lab.answer("n1.example.com", address, start + Duration::from_secs(20));
    lab.answer("n9.example.com", address, start + Duration::from_secs(21));
    let names: Vec<String> = lab
        .vm
        .fresh_names(address, start + Duration::from_secs(22))
        .iter()
        .map(|name| name.to_string())
        .collect();
    assert!(names.contains(&"n1.example.com".to_owned()));
    assert!(!names.contains(&"n2.example.com".to_owned()));
    assert_eq!(lab.vm.pairs, 8);
}

#[test]
fn a_connection_takes_a_name_only_from_an_answer_of_the_last_30_minutes() {
    let (mut lab, start) = (Lab::new(), Instant::now());
    let address = ip("104.16.1.34");
    lab.answer("registry.npmjs.org", address, start);
    lab.connect(
        address,
        Proto::Tcp,
        443,
        start + Duration::from_secs(29 * 60),
    );
    lab.connect(
        address,
        Proto::Tcp,
        80,
        start + Duration::from_secs(31 * 60),
    );
    let data = lab.data();
    let early = rows(&data);
    assert_eq!(row(&early, "registry.npmjs.org", Some(443))["kind"], "name");
    // The late connection finds no fresh name: it stays an address row.
    assert_eq!(row(&early, "104.16.1.34", Some(80))["kind"], "address");

    // A new answer makes the name fresh again.
    lab.answer(
        "registry.npmjs.org",
        address,
        start + Duration::from_secs(40 * 60),
    );
    lab.connect(
        address,
        Proto::Udp,
        443,
        start + Duration::from_secs(41 * 60),
    );
    let data = lab.data();
    assert_eq!(
        row(&rows(&data), "registry.npmjs.org", Some(443))["protocol"],
        "tcp"
    );
    assert!(rows(&data)
        .iter()
        .any(|row| row["destination"] == "registry.npmjs.org" && row["protocol"] == "udp"));
}

#[test]
fn an_expired_pair_is_forgotten_and_gives_its_bytes_back() {
    let (mut lab, start) = (Lab::new(), Instant::now());
    lab.answer("registry.npmjs.org", ip("104.16.1.34"), start);
    let held = lab.vm.bytes;
    lab.vm
        .expire(&mut lab.shared, start + Duration::from_secs(29 * 60));
    assert_eq!(lab.vm.pairs, 1);
    lab.vm
        .expire(&mut lab.shared, start + Duration::from_secs(30 * 60));
    assert_eq!(lab.vm.pairs, 0);
    assert_eq!(lab.vm.bytes, held - PAIR_COST);
}

#[test]
fn rows_are_sorted_by_destination_numeric_port_and_protocol_whatever_the_order_seen() {
    let now = Instant::now();
    let build = |order: &[(u16, Proto)]| {
        let mut lab = Lab::new();
        lab.answer("a.example.com", ip("93.184.216.34"), now);
        lab.connect(ip("9.9.9.9"), Proto::Tcp, 80, now);
        for (port, protocol) in order {
            lab.connect(ip("93.184.216.34"), *protocol, *port, now);
        }
        lab.data()
    };
    let forward = build(&[(80, Proto::Tcp), (80, Proto::Udp), (443, Proto::Tcp)]);
    let backward = build(&[(443, Proto::Tcp), (80, Proto::Udp), (80, Proto::Tcp)]);
    assert_eq!(forward.to_string(), backward.to_string());
    let order: Vec<(String, Value, Value)> = rows(&forward)
        .iter()
        .map(|row| {
            (
                row["destination"].as_str().unwrap().to_owned(),
                row["port"].clone(),
                row["protocol"].clone(),
            )
        })
        .collect();
    assert_eq!(
        order,
        [
            ("9.9.9.9".into(), json!(80), json!("tcp")),
            ("a.example.com".into(), json!(80), json!("tcp")),
            ("a.example.com".into(), json!(80), json!("udp")),
            ("a.example.com".into(), json!(443), json!("tcp")),
        ]
    );
}

// Watch list and lifecycle

#[test]
fn the_watch_list_names_a_bridge_or_nothing() {
    use crate::workspace::parse_watch_egress;
    let named = |network: &str| json!({"data": {"egress": {"network": network}}});
    assert_eq!(parse_watch_egress(&named("orbittask0")), vec!["orbittask0"]);
    assert_eq!(
        parse_watch_egress(&named("orbittaskab12cd")),
        vec!["orbittaskab12cd"]
    );
    for bad in [
        "orbittask",
        "orbittask1234567",
        "orbittaskA",
        "orbittask0/../x",
        "eth0",
        "",
        "orbittask 0",
    ] {
        assert!(parse_watch_egress(&named(bad)).is_empty(), "{bad:?}");
    }
    assert!(parse_watch_egress(&json!({"data": {"egress": null}})).is_empty());
    assert!(parse_watch_egress(&json!({"data": {}})).is_empty());
    assert!(parse_watch_egress(&json!({"data": {"egress": {"network": 5}}})).is_empty());
}

#[test]
fn the_watch_list_names_the_container_bridge_beside_the_vm_bridge() {
    use crate::workspace::parse_watch_egress;
    let both = |vm: Value, container: Value| json!({"data": {"egress": {"network": vm, "container_network": container}}});
    assert_eq!(
        parse_watch_egress(&both(json!("orbittask0"), json!("orbittaskc0"))),
        vec!["orbittask0", "orbittaskc0"]
    );
    assert_eq!(
        parse_watch_egress(&both(json!("orbittask0"), Value::Null)),
        vec!["orbittask0"]
    );
    assert_eq!(
        parse_watch_egress(&both(json!("orbittask0"), json!("eth0"))),
        vec!["orbittask0"]
    );
    assert_eq!(
        parse_watch_egress(&both(json!("orbittask0"), json!("orbittask0"))),
        vec!["orbittask0"]
    );
}

fn step(runner: &mut Runner, world: &World, network: Option<&str>) -> Step {
    let networks: Vec<String> = network.into_iter().map(str::to_owned).collect();
    runner.step_with(&networks, Instant::now(), STAMP, |name| {
        Observer::with_paths(name, world.paths.clone())
    })
}

fn dirty(runner: &Runner) -> bool {
    runner
        .observers
        .values()
        .next()
        .unwrap()
        .vms
        .values()
        .next()
        .unwrap()
        .dirty
}

#[test]
fn without_egress_in_the_watch_list_the_runner_holds_no_state_and_starts_at_the_end_again() {
    let world = World::new();
    let mut runner = Runner::default();
    assert!(!runner.active());
    let first = step(&mut runner, &world, Some("orbittask0"));
    assert!(runner.active());
    assert_eq!(first.facts.len(), 1);

    world.connections(&[connection(VM_IP, "140.82.121.4", "TCP", 443)]);
    step(&mut runner, &world, Some("orbittask0"));
    let cleared = step(&mut runner, &world, None);
    assert!(!runner.active());
    assert!(runner.observers.is_empty());
    assert!(cleared.facts.is_empty() && cleared.known.is_empty());

    let back = step(&mut runner, &world, Some("orbittask0"));
    assert_eq!(back.facts.len(), 1);
    assert!(rows(&back.facts[0].1).is_empty(), "history is not replayed");
    assert_eq!(back.known, BTreeSet::from(["beast-tvm-3".to_owned()]));
}

#[test]
fn another_bridge_starts_a_new_observer() {
    let world = World::new();
    let mut runner = Runner::default();
    step(&mut runner, &world, Some("orbittask0"));
    assert_eq!(
        runner.observers.keys().collect::<Vec<_>>(),
        vec!["orbittask0"]
    );
    step(&mut runner, &world, Some("orbittask1"));
    assert_eq!(
        runner.observers.keys().collect::<Vec<_>>(),
        vec!["orbittask1"]
    );
}

#[test]
fn a_container_host_observes_each_bridge_with_its_own_files_and_state() {
    let vms = World::new();
    let containers = World::under("orbittaskc0");
    std::fs::remove_file(containers.paths.hosts_dir.join("project_beast-tvm-3.eth0")).unwrap();
    containers.set_vm(
        "project_beast-tc-1.eth0",
        "10:66:6a:00:00:c1",
        "beast-tc-1",
        "10.251.78.5",
    );
    let networks = vec!["orbittask0".to_owned(), "orbittaskc0".to_owned()];
    let mut runner = Runner::default();
    let paths = |name: &str| {
        if name == "orbittaskc0" {
            containers.paths.clone()
        } else {
            vms.paths.clone()
        }
    };
    let first = runner.step_with(&networks, Instant::now(), STAMP, |name| {
        Observer::with_paths(name, paths(name))
    });
    let mut facts: Vec<(String, String)> = first
        .facts
        .iter()
        .map(|(name, data)| (name.clone(), data["network"].as_str().unwrap().to_owned()))
        .collect();
    facts.sort();
    assert_eq!(
        facts,
        vec![
            ("beast-tc-1".to_owned(), "orbittaskc0".to_owned()),
            ("beast-tvm-3".to_owned(), "orbittask0".to_owned()),
        ]
    );
    assert_eq!(
        first.known,
        BTreeSet::from(["beast-tc-1".to_owned(), "beast-tvm-3".to_owned()])
    );

    // A bridge the watch list no longer names drops its state; the other keeps its own.
    let vms_only = vec!["orbittask0".to_owned()];
    let after = runner.step_with(&vms_only, Instant::now(), STAMP, |name| {
        Observer::with_paths(name, paths(name))
    });
    assert_eq!(after.known, BTreeSet::from(["beast-tvm-3".to_owned()]));
    assert_eq!(
        runner.observers.keys().collect::<Vec<_>>(),
        vec!["orbittask0"]
    );
}

#[test]
fn a_new_vm_is_reported_at_once_a_change_after_three_ticks_and_an_unchanged_fact_only_at_the_resync(
) {
    assert_eq!(DEBOUNCE_TICKS, 3);
    assert_eq!(RESYNC_TICKS, 121);
    let world = World::new();
    let mut runner = Runner::default();
    assert_eq!(step(&mut runner, &world, Some("orbittask0")).facts.len(), 1);
    assert!(step(&mut runner, &world, Some("orbittask0"))
        .facts
        .is_empty());
    assert!(!dirty(&runner), "an unchanged VM is not built again");

    world.connections(&[connection(VM_IP, "140.82.121.4", "TCP", 443)]);
    assert!(step(&mut runner, &world, Some("orbittask0"))
        .facts
        .is_empty());
    assert!(dirty(&runner));
    let sent = step(&mut runner, &world, Some("orbittask0"));
    assert_eq!(sent.facts.len(), 1);
    assert_eq!(rows(&sent.facts[0].1).len(), 1);
    assert!(!dirty(&runner));

    // However long the Gateway took to answer, nothing is offered again before the resync.
    let mut quiet = 0;
    let again = loop {
        quiet += 1;
        let next = step(&mut runner, &world, Some("orbittask0"));
        if !next.facts.is_empty() || quiet > 200 {
            break next;
        }
    };
    assert_eq!(quiet, RESYNC_TICKS);
    assert_eq!(again.facts.len(), 1);
}

#[test]
fn a_busy_vm_is_sent_at_most_once_in_three_ticks() {
    let world = World::new();
    let mut runner = Runner::default();
    step(&mut runner, &world, Some("orbittask0"));
    let mut sends = 0;
    for index in 0..9 {
        world.connections(&[connection(VM_IP, &outside(index).to_string(), "TCP", 443)]);
        sends += step(&mut runner, &world, Some("orbittask0")).facts.len();
    }
    assert_eq!(sends, 3);
}

#[test]
fn at_most_four_facts_are_built_per_tick_and_the_rest_follow() {
    let world = World::new();
    let hosts: Vec<String> = (0..6)
        .map(|index| format!("10:66:6a:00:00:{index:02x},vm-{index}"))
        .collect();
    for (index, host) in hosts.iter().enumerate() {
        std::fs::write(
            world.paths.hosts_dir.join(format!("p_vm-{index}.eth0")),
            host,
        )
        .unwrap();
    }
    let mut observer = world.observer();
    observe(&mut observer, STAMP);
    let first = observer.due(MAX_SENDS);
    assert_eq!(first.len(), 4);
    assert_eq!(observer.due(MAX_SENDS).len(), 3);
    assert!(observer.due(MAX_SENDS).is_empty());
    let mut names: Vec<String> = first.into_iter().map(|(name, _)| name).collect();
    names.sort();
    assert_eq!(names.len(), 4);
}

#[test]
fn a_source_that_is_not_available_sends_no_fact_but_keeps_the_vm_known() {
    let world = World::new();
    let mut runner = Runner::default();
    assert_eq!(step(&mut runner, &world, Some("orbittask0")).facts.len(), 1);
    std::fs::remove_file(&world.paths.leases).unwrap();
    let missing = step(&mut runner, &world, Some("orbittask0"));
    assert!(missing.facts.is_empty());
    assert_eq!(missing.known, BTreeSet::from(["beast-tvm-3".to_owned()]));
}
