use super::*;

struct Fake {
    uid: u32,
    gid: u32,
    home: String,
}
impl Host for Fake {
    fn account(&self) -> Result<(u32, u32, String), String> {
        Ok((self.uid, self.gid, self.home.clone()))
    }
}

#[test]
fn only_the_pinned_orbit_uid_and_gid_with_an_absolute_home_pass() {
    let host = Fake {
        uid: 1000,
        gid: 1000,
        home: "/home/orbit".into(),
    };
    assert_eq!(owner(&host).unwrap(), PathBuf::from("/home/orbit"));
    for (uid, gid, home) in [
        (1001, 1000, "/home/orbit"),
        (1000, 1001, "/home/orbit"),
        (1000, 1000, "orbit"),
    ] {
        let host = Fake {
            uid,
            gid,
            home: home.into(),
        };
        assert!(matches!(owner(&host), Err(Error::Failed { .. })));
    }
}
