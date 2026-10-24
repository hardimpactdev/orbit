use super::*;
use std::collections::VecDeque;
struct Fake {
    root: PathBuf,
    uid: u32,
    gid: u32,
    answers: VecDeque<Output>,
    calls: Vec<Vec<String>>,
}
impl Fake {
    fn new(answers: &[&str]) -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-image-boot-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(root.join(".ssh")).unwrap();
        fs::write(root.join(".ssh/authorized_keys"), "public key").unwrap();
        Self {
            root,
            uid: 1000,
            gid: 1000,
            answers: answers
                .iter()
                .map(|s| Output {
                    code: 0,
                    stdout: (*s).into(),
                    stderr: String::new(),
                    truncated: false,
                })
                .collect(),
            calls: vec![],
        }
    }
}
impl Drop for Fake {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}
impl Host for Fake {
    fn run(&mut self, program: &'static str, args: &[&str]) -> Output {
        self.calls.push(
            std::iter::once(program)
                .chain(args.iter().copied())
                .map(str::to_owned)
                .collect(),
        );
        self.answers.pop_front().expect("unexpected host call")
    }
    fn account(&self) -> Result<(u32, u32, String), String> {
        Ok((self.uid, self.gid, self.root.display().to_string()))
    }
}
const LEAN: &[&str] = &[
    "masked\n",
    "active\n",
    "[Service]\nExecStart=wait --ipv4\n",
    "[{\"dev\":\"enp5s0\"}]",
    "Network File: /etc/systemd/network/00-orbit-task-vm.network\n",
    "Status: active\n",
];
#[test]
fn verifies_owner_key_lean_boot_and_firewall_on_repeated_checks() {
    let mut host = Fake::new(&[LEAN, LEAN].concat());
    for _ in 0..2 {
        assert!(
            check(
                &mut host,
                &CheckInput {
                    lean_boot: true,
                    firewall: true
                }
            )
            .unwrap()
            .ready
        );
    }
    assert_eq!(
        host.calls[4],
        vec![
            "/usr/bin/networkctl",
            "status",
            "--no-pager",
            "--",
            "enp5s0"
        ]
    );
    assert!(check(
        &mut Fake::new(&[]),
        &CheckInput {
            lean_boot: false,
            firewall: false
        }
    )
    .is_ok());
    let mut host = Fake::new(LEAN);
    host.answers[0].code = 1;
    assert!(check(
        &mut host,
        &CheckInput {
            lean_boot: true,
            firewall: true
        }
    )
    .is_ok());
}
#[test]
fn owner_and_key_refusals_run_no_host_process() {
    for kind in ["uid", "gid", "key", "empty"] {
        let mut host = Fake::new(&[]);
        match kind {
            "uid" => host.uid = 1001,
            "gid" => host.gid = 1001,
            "key" => fs::remove_file(host.root.join(".ssh/authorized_keys")).unwrap(),
            _ => fs::write(host.root.join(".ssh/authorized_keys"), "").unwrap(),
        };
        assert!(matches!(
            check(
                &mut host,
                &CheckInput {
                    lean_boot: true,
                    firewall: true
                }
            ),
            Err(Error::Failed { .. })
        ));
        assert!(host.calls.is_empty());
    }
}
#[test]
fn refuses_each_failed_or_malformed_provider_observation() {
    for index in 0..LEAN.len() {
        for truncated in [false, true] {
            let mut host = Fake::new(LEAN);
            host.answers[index].stdout = "invalid".into();
            host.answers[index].truncated = truncated;
            assert!(matches!(
                check(
                    &mut host,
                    &CheckInput {
                        lean_boot: true,
                        firewall: true
                    }
                ),
                Err(Error::Failed { .. })
            ));
            assert_eq!(host.calls.len(), index + 1);
        }
    }
    for index in 1..LEAN.len() {
        let mut host = Fake::new(LEAN);
        host.answers[index].code = 1;
        assert!(matches!(
            check(
                &mut host,
                &CheckInput {
                    lean_boot: true,
                    firewall: true
                }
            ),
            Err(Error::Failed { .. })
        ));
    }
}
#[test]
fn boot_writes_fixed_files_and_masks_on_repeated_calls_and_cloud_images_only_mask() {
    let mut host = Fake::new(&["", "", ""]);
    let root = host.root.clone();
    for _ in 0..2 {
        assert!(
            boot(&root, &mut host, &BootInput { lean: true })
                .unwrap()
                .ready
        );
        assert_eq!(
            fs::read(root.join(NETWORK.trim_start_matches('/'))).unwrap(),
            NETWORK_TEXT
        );
        assert_eq!(
            fs::read(root.join(WAIT.trim_start_matches('/'))).unwrap(),
            WAIT_TEXT
        );
    }
    let mut cloud = Fake::new(&[""]);
    let root = cloud.root.clone();
    boot(&root, &mut cloud, &BootInput { lean: false }).unwrap();
    assert!(!root.join("etc").exists());
    assert_eq!(cloud.calls[0][1], "mask");
    assert_eq!(&cloud.calls[0][2..], MASKS);
}
#[test]
fn boot_failure_does_not_claim_ready_and_unknown_flags_cannot_select_actions() {
    let mut host = Fake::new(&[""]);
    host.answers[0].code = 1;
    let root = host.root.clone();
    assert!(
        matches!(boot(&root,&mut host,&BootInput{lean:false}),Err(Error::Failed{stage,..}) if stage=="service masks")
    );
    fs::write(root.join("etc"), "not a directory").unwrap();
    assert!(
        matches!(boot(&root,&mut host,&BootInput{lean:true}),Err(Error::Failed{stage,..}) if stage=="network files")
    );
    assert_eq!(host.calls.len(), 1);
    assert!(serde_json::from_str::<CheckInput>(
        r#"{"lean_boot":false,"firewall":false,"command":"x"}"#
    )
    .is_err());
    assert_eq!(PrepareBoot::privilege(Platform::Macos), None);
}
