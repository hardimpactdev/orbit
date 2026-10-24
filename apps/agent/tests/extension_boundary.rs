//! Core composes extension crates once; Node behavior stays in the owning extension.
use std::{fs, path::Path};
fn sources(path: &Path, output: &mut Vec<std::path::PathBuf>) {
    for entry in fs::read_dir(path).unwrap() {
        let path = entry.unwrap().path();
        if path.is_dir() {
            sources(&path, output);
        } else if path.extension().is_some_and(|ext| ext == "rs") {
            output.push(path);
        }
    }
}
#[test]
fn core_references_each_extension_only_at_its_composition_line() {
    let root = Path::new(env!("CARGO_MANIFEST_DIR"));
    let manifest: toml::Value = fs::read_to_string(root.join("Cargo.toml"))
        .unwrap()
        .parse()
        .unwrap();
    let mut files = Vec::new();
    sources(&root.join("src"), &mut files);
    sources(&root.join("crates"), &mut files);
    for (name, dependency) in manifest["dependencies"].as_table().unwrap() {
        if !dependency
            .get("path")
            .and_then(toml::Value::as_str)
            .is_some_and(|path| path.contains("gateway/extensions/"))
        {
            continue;
        }
        let module = name.replace('-', "_");
        let expected = format!("{module}::register(&mut registry);");
        let mut count = 0;
        for path in &files {
            for line in fs::read_to_string(path)
                .unwrap()
                .lines()
                .filter(|line| line.contains(&module))
            {
                assert_eq!(path, &root.join("src/command/registry.rs"));
                assert_eq!(line.trim(), expected);
                count += 1;
            }
        }
        assert_eq!(count, 1, "{name} must have one registration");
    }
}
