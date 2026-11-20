//! Orbit's desired `oauth-model-alias.antigravity` list of CLIProxyAPI. Orbit owns only this channel.
use crate::{
    collector::{Api, Error},
    quota::{array, text},
};
use serde_json::{json, Value};

pub const CHANNEL: &str = "antigravity";

/// Base Gemini names that agy sends, mapped to the tiered ids of the Antigravity pool, as (name, alias).
/// Every entry forks, so the tiered id stays listed beside its alias.
pub const ANTIGRAVITY: [(&str, &str); 7] = [
    ("gemini-4-argon-high", "gemini-4-argon"),
    ("gemini-4-argon-high", "gemini-3.1-pro"),
    ("gemini-4-argon-high", "gemini-3.1-pro-preview"),
    ("gemini-3.8-flash-high", "gemini-3.8-flash"),
    ("gemini-3.8-flash-high", "gemini-3.7-flash"),
    ("gemini-3.8-flash-high", "gemini-3.6-flash"),
    ("gemini-3.8-flash-high", "gemini-3.1-flash-lite-preview"),
];

fn desired() -> Vec<Value> {
    ANTIGRAVITY
        .iter()
        .map(|(name, alias)| json!({"name": name, "alias": alias, "fork": true}))
        .collect()
}

/// Sets the antigravity list through the management API when it differs, and reports whether it changed it.
/// The router persists the change to its configuration file and keeps every other key and channel.
pub async fn converge(api: &impl Api) -> Result<bool, Error> {
    let current = api.request("GET", "/oauth-model-alias", None).await?;
    let desired = desired();
    if array(&current["oauth-model-alias"][CHANNEL]) == desired.as_slice() {
        return Ok(false);
    }
    let response = api
        .request(
            "PATCH",
            "/oauth-model-alias",
            Some(json!({"channel": CHANNEL, "aliases": desired})),
        )
        .await?;
    if text(&response["status"]) != "ok" {
        return Err(Error::Invalid("CLIProxyAPI did not save the model aliases"));
    }
    Ok(true)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    struct Router {
        aliases: Mutex<Value>,
        calls: Mutex<Vec<String>>,
    }
    impl Api for Router {
        async fn request(
            &self,
            method: &str,
            path: &str,
            body: Option<Value>,
        ) -> Result<Value, Error> {
            assert_eq!(path, "/oauth-model-alias");
            self.calls.lock().unwrap().push(method.into());
            let mut aliases = self.aliases.lock().unwrap();
            match method {
                "GET" => Ok(json!({"oauth-model-alias": aliases.clone()})),
                "PATCH" => {
                    let body = body.unwrap();
                    aliases[text(&body["channel"])] = body["aliases"].clone();
                    Ok(json!({"status": "ok"}))
                }
                _ => panic!("unexpected {method}"),
            }
        }
    }
    fn router(aliases: Value) -> Router {
        Router {
            aliases: Mutex::new(aliases),
            calls: Mutex::new(Vec::new()),
        }
    }

    #[test]
    fn keeps_the_live_antigravity_values() {
        assert_eq!(
            Value::Array(desired()),
            json!([
                {"name": "gemini-4-argon-high", "alias": "gemini-4-argon", "fork": true},
                {"name": "gemini-4-argon-high", "alias": "gemini-3.1-pro", "fork": true},
                {"name": "gemini-4-argon-high", "alias": "gemini-3.1-pro-preview", "fork": true},
                {"name": "gemini-3.8-flash-high", "alias": "gemini-3.8-flash", "fork": true},
                {"name": "gemini-3.8-flash-high", "alias": "gemini-3.7-flash", "fork": true},
                {"name": "gemini-3.8-flash-high", "alias": "gemini-3.6-flash", "fork": true},
                {"name": "gemini-3.8-flash-high", "alias": "gemini-3.1-flash-lite-preview", "fork": true},
            ])
        );
    }

    #[tokio::test]
    async fn sets_only_the_antigravity_channel_and_then_changes_nothing() {
        let codex = json!([{"name": "gpt-6", "alias": "gpt-latest"}]);
        let router = router(json!({
            "codex": codex,
            "antigravity": [{"name": "gemini-4-argon-high", "alias": "gemini-4-argon", "fork": true}],
        }));
        assert!(converge(&router).await.unwrap());
        assert_eq!(*router.calls.lock().unwrap(), ["GET", "PATCH"]);
        let aliases = router.aliases.lock().unwrap().clone();
        assert_eq!(aliases["codex"], codex);
        assert_eq!(aliases[CHANNEL], Value::Array(desired()));

        router.calls.lock().unwrap().clear();
        assert!(!converge(&router).await.unwrap());
        assert_eq!(*router.calls.lock().unwrap(), ["GET"]);
        assert_eq!(router.aliases.lock().unwrap()["codex"], codex);
    }

    #[tokio::test]
    async fn sets_the_list_on_a_router_without_aliases() {
        let router = router(Value::Null);
        assert!(converge(&router).await.unwrap());
        assert_eq!(
            router.aliases.lock().unwrap()[CHANNEL],
            Value::Array(desired())
        );
    }

    #[tokio::test]
    async fn reports_an_unsaved_change() {
        struct Refusing;
        impl Api for Refusing {
            async fn request(
                &self,
                method: &str,
                _: &str,
                _: Option<Value>,
            ) -> Result<Value, Error> {
                Ok(match method {
                    "GET" => json!({}),
                    _ => json!({"error": "failed to save config"}),
                })
            }
        }
        assert!(converge(&Refusing).await.is_err());
    }
}
