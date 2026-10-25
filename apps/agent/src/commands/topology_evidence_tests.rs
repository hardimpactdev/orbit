use super::*;
use std::fs;

#[test]
fn reads_only_the_fixed_file_and_bounds_the_newest_bytes_on_every_read() {
    let checkout = std::env::temp_dir().join(format!("orbit-evidence-{}", std::process::id()));
    fs::create_dir(&checkout).unwrap();
    let input = Input {
        checkout: checkout.clone(),
    };
    assert_eq!(
        read(&input),
        Ok(Output {
            content_base64: None
        })
    );
    fs::create_dir(checkout.join(".e2e")).unwrap();
    fs::write(checkout.join(".e2e/evidence.log"), "not evidence").unwrap();
    assert_eq!(
        read(&input),
        Ok(Output {
            content_base64: None
        })
    );
    let path = checkout.join(".e2e/evidence.jsonl");
    fs::write(&path, b"partial\n{\"v\":1}\n").unwrap();
    for _ in 0..2 {
        let output = read(&input).unwrap();
        assert_eq!(
            STANDARD.decode(output.content_base64.unwrap()).unwrap(),
            b"partial\n{\"v\":1}\n"
        );
    }
    let mut bytes = vec![b'x'; LIMIT as usize];
    bytes.extend_from_slice(b"\0\xff\xfe\n{\"v\":1}\n");
    fs::write(&path, &bytes).unwrap();
    let output = read(&input).unwrap();
    assert_eq!(
        STANDARD
            .decode(output.content_base64.as_ref().unwrap())
            .unwrap(),
        bytes[bytes.len() - LIMIT as usize..]
    );
    assert!(serde_json::to_vec(&output).unwrap().len() < 2 * LIMIT as usize);
    assert_eq!(fs::read(&path).unwrap(), bytes);
    fs::remove_file(&path).unwrap();
    fs::create_dir(&path).unwrap();
    assert_eq!(
        read(&input),
        Ok(Output {
            content_base64: None
        })
    );
    fs::remove_dir_all(&checkout).unwrap();
}

#[test]
fn invalid_paths_and_unreadable_evidence_have_typed_refusals() {
    for checkout in ["", ".", "relative", "/tmp/../etc"] {
        assert_eq!(
            read(&Input {
                checkout: checkout.into()
            }),
            Err(Error::Invalid {})
        );
    }
    assert!(serde_json::from_value::<Input>(
        serde_json::json!({"checkout":"/tmp", "file":"secret"})
    )
    .is_err());
    let checkout =
        std::env::temp_dir().join(format!("orbit-evidence-error-{}", std::process::id()));
    fs::create_dir(&checkout).unwrap();
    std::os::unix::fs::symlink(".e2e", checkout.join(".e2e")).unwrap();
    assert_eq!(
        read(&Input {
            checkout: checkout.clone()
        }),
        Err(Error::Unreadable {})
    );
    fs::remove_dir_all(checkout).unwrap();
}
