use super::*;
use std::{
    collections::BTreeMap,
    sync::{Arc, Mutex},
};

#[derive(Default, Clone)]
struct Memory(Arc<Mutex<BTreeMap<String, String>>>);
impl Cache for Memory {
    async fn get(&self, key: &str) -> Result<Option<String>, Error> {
        Ok(self.0.lock().unwrap().get(key).cloned())
    }
    async fn set(&self, key: &str, value: &str, _: Option<u64>) -> Result<(), Error> {
        self.0.lock().unwrap().insert(key.into(), value.into());
        Ok(())
    }
    async fn acquire(&self, holder: &str) -> Result<bool, Error> {
        let mut values = self.0.lock().unwrap();
        if values.contains_key(LOCK_KEY) {
            return Ok(false);
        }
        values.insert(LOCK_KEY.into(), holder.into());
        Ok(true)
    }
    async fn release(&self, holder: &str) -> Result<(), Error> {
        let mut values = self.0.lock().unwrap();
        if values.get(LOCK_KEY).is_some_and(|v| v == holder) {
            values.remove(LOCK_KEY);
        }
        Ok(())
    }
}
impl Memory {
    fn value(&self, key: &str) -> Value {
        self.0
            .lock()
            .unwrap()
            .get(key)
            .map(|s| serde_json::from_str(s).unwrap())
            .unwrap_or(Value::Null)
    }
}
type Handler = dyn Fn(&str, &str, Option<Value>) -> Result<Value, Error> + Send + Sync;
struct MockApi(Box<Handler>);
impl Api for MockApi {
    async fn request(&self, method: &str, path: &str, body: Option<Value>) -> Result<Value, Error> {
        (self.0)(method, path, body)
    }
}
fn api(
    handler: impl Fn(&str, &str, Option<Value>) -> Result<Value, Error> + Send + Sync + 'static,
) -> MockApi {
    MockApi(Box::new(handler))
}
fn file(provider: &str) -> Value {
    json!({"name": "account.json", "auth_index": "account", "provider": provider})
}
fn quota() -> Value {
    json!({"status_code": 200, "body": {"rate_limit": {"primary_window": {"used_percent": 21, "limit_window_seconds": 18000, "reset_at": 2000000000}}}})
}

#[tokio::test]
async fn reserves_before_network_and_reuses_interval_after_restart() {
    let memory = Memory::default();
    let check = memory.clone();
    let collector = Collector::new(
        memory.clone(),
        api(move |_, path, body| {
            assert_eq!(path, "/api-call");
            assert_eq!(body.unwrap()["header"]["Authorization"], "Bearer $TOKEN$");
            assert_eq!(
                check.value("orbit:ai-router:account:account")["next_check_at"],
                4600.
            );
            Ok(json!({"status_code": 200, "body": {"five_hour": {"utilization": 10}}}))
        }),
    );
    let account = collector
        .account(&file("claude"), &[], 1000.)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(account["next_check_at"], 1900.);
    let restarted = Collector::new(
        memory.clone(),
        api(|_, path, _| match path {
            "/auth-files" => Ok(json!({"files": [file("claude")]})),
            "/auth-files/models?name=account.json" => Ok(json!({"models": []})),
            _ => panic!("cached quota must not fetch {path}"),
        }),
    );
    restarted.collect(1100.).await.unwrap();
    assert_eq!(
        restarted.snapshot(1100.).await.unwrap()["accounts"][0]["checked_at"],
        1000.
    );
    assert!(!memory.0.lock().unwrap().contains_key(LOCK_KEY));
}
#[tokio::test]
async fn backoff_and_failures_clear_windows_without_inventing_zero() {
    let memory = Memory::default();
    let collector = Collector::new(
        memory.clone(),
        api(|_, _, _| {
            Ok(
                json!({"status_code": 429, "header": {"Retry-After": ["7200"]}, "body": "private diagnostic"}),
            )
        }),
    );
    let account = collector
        .account(&file("codex"), &[], 1000.)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(account["status"], "backoff");
    assert_eq!(account["next_check_at"], 8200.);
    assert_eq!(account["error"], "HTTP 429");
    assert_eq!(account["windows"], json!([]));
    let no_network = Collector::new(memory.clone(), api(|_, _, _| panic!("must wait")));
    assert_eq!(
        no_network
            .account(&file("codex"), &[], 1100.)
            .await
            .unwrap()
            .unwrap(),
        account
    );
    let collector = Collector::new(memory, api(|_, _, _| Err(Error::Request)));
    let second = collector
        .account(&file("codex"), &[], 8300.)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(second["failures"], 2);
    assert_eq!(second["next_check_at"], 15500.);
    assert_eq!(second["error"], "Quota request failed");
}
#[tokio::test]
async fn wrapped_response_disabled_account_and_cached_snapshot_expiry() {
    let collector = Collector::new(
        Memory::default(),
        api(|_, _, _| {
            let mut result = quota();
            result["body"] = json!(result["body"].to_string());
            Ok(result)
        }),
    );
    let account = collector
        .account(&file("codex"), &[], 1000.)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(account["windows"][0]["label"], "5h");
    assert_eq!(account["windows"][0]["remaining_percent"], 79.);
    collector
        .publish(json!({"accounts": [account], "models": []}))
        .await
        .unwrap();
    assert!(
        array(&collector.snapshot(2000000001.).await.unwrap()["providers"][0]["windows"])
            .is_empty()
    );
    let disabled = Collector::new(Memory::default(), api(|_, _, _| panic!("disabled")));
    let mut f = file("codex");
    f["disabled"] = json!(true);
    assert_eq!(
        disabled.account(&f, &[], 1000.).await.unwrap().unwrap()["status"],
        "disabled"
    );
}
#[tokio::test]
async fn lock_contention_and_failed_round_release() {
    let cache = Memory::default();
    cache.acquire("other").await.unwrap();
    let collector = Collector::new(cache.clone(), api(|_, _, _| panic!("held lock")));
    collector.collect(1000.).await.unwrap();
    assert_eq!(cache.get(LOCK_KEY).await.unwrap().as_deref(), Some("other"));
    cache.release("other").await.unwrap();
    let collector = Collector::new(cache.clone(), api(|_, _, _| Err(Error::Http(503, 0.))));
    assert!(collector.collect(1000.).await.is_err());
    assert_eq!(cache.get(LOCK_KEY).await.unwrap(), None);
}
#[tokio::test]
async fn models_keep_failed_file_replace_empty_and_drop_removed_file() {
    let cache = Memory::default();
    let phase = Arc::new(Mutex::new(0));
    let current = phase.clone();
    let collector = Collector::new(
        cache.clone(),
        api(move |_, path, _| {
            let phase = *current.lock().unwrap();
            match path {
                "/auth-files/models?name=a+b.json" if phase == 1 => Err(Error::Request),
                "/auth-files/models?name=a+b.json" => {
                    Ok(json!({"models": [{"id": "shared", "owned_by": "openai"}, {"id": "first"}]}))
                }
                "/auth-files/models?name=second.json" if phase == 2 => Ok(json!({"models": []})),
                "/auth-files/models?name=second.json" => Ok(
                    json!({"models": [{"id": "shared", "owned_by": "anthropic"}, {"id": "second", "type": "openai"}]}),
                ),
                _ => panic!("unexpected model endpoint: {path}"),
            }
        }),
    );
    let files = vec![
        json!({"name": "a b.json", "provider": "xai"}),
        json!({"name": "second.json", "provider": "anthropic"}),
    ];
    let expected = json!([{"id":"shared","provider":"codex"},{"id":"first","provider":"grok"},{"id":"second","provider":"claude"}]);
    assert_eq!(json!(collector.models(&files).await.unwrap()), expected);
    *phase.lock().unwrap() = 1;
    assert_eq!(json!(collector.models(&files).await.unwrap()), expected);
    *phase.lock().unwrap() = 2;
    assert!(collector.models(&files[1..]).await.unwrap().is_empty());
    assert_eq!(cache.value(MODEL_FILES_KEY), json!({"second.json": []}));
}
#[tokio::test]
async fn toggle_uses_auth_filename_preserves_models_and_never_fetches_quota() {
    let cache = Memory::default();
    cache.set(SNAPSHOT_KEY, &json!({"accounts": [{"id": "account", "provider": "codex", "disabled": false, "windows": [{"label":"5h", "used_percent":21}]}], "models": [{"id":"model", "provider":"codex"}], "collected_at":"2026-09-21T10:00:00+00:00"}).to_string(), None).await.unwrap();
    let collector = Collector::new(
        cache.clone(),
        api(|method, path, body| match (method, path) {
            ("GET", "/auth-files") => Ok(json!({"files":[file("codex")]})),
            ("PATCH", "/auth-files/status") => {
                assert_eq!(
                    body.unwrap(),
                    json!({"name":"account.json","disabled":true})
                );
                Ok(json!({}))
            }
            _ => panic!("toggle fetched quota"),
        }),
    );
    assert_eq!(
        collector.toggle("account", true, 1000.).await.unwrap()["disabled"],
        true
    );
    assert_eq!(cache.value(SNAPSHOT_KEY)["models"][0]["id"], "model");
    assert_eq!(
        cache.value(SNAPSHOT_KEY)["providers"][0]["windows"],
        json!([])
    );
    assert_eq!(
        collector.toggle("absent", true, 1000.).await,
        Err(Error::Http(404, 0.))
    );
}
#[test]
fn provider_windows_and_projection_keep_the_published_contract() {
    let cases = [
        (
            "grok",
            json!({"config":{"creditUsagePercent":42,"currentPeriod":{"type":"USAGE_PERIOD_TYPE_WEEKLY"}}}),
            "7d",
            42.,
        ),
        (
            "kimi",
            json!({"limits":[{"window":{"duration":5,"timeUnit":"TIME_UNIT_HOUR"},"detail":{"used":"3","limit":"10"}}]}),
            "5h",
            30.,
        ),
        (
            "antigravity",
            json!({"groups":[{"displayName":"Gemini Models","buckets":[{"remainingFraction":0.7,"window":"weekly"}]}]}),
            "Gemini models · Weekly limit",
            30.,
        ),
        (
            "claude",
            json!({"limits":[{"kind":"weekly_scoped","scope":{"model":{"display_name":"Fable 5"}},"is_active":true,"percent":10}],"iguana_necktie":{"utilization":99}}),
            "7-day Fable 5",
            10.,
        ),
    ];
    for (provider, body, label, used) in cases {
        let windows = windows(provider, &body).unwrap();
        assert_eq!(windows[0]["label"], label);
        assert!((number(&windows[0]["used_percent"]).unwrap() - used).abs() < 1e-10);
    }
    let accounts = json!([
        {"id":"one","label":"One","provider":"grok","disabled":false,"windows":[{"label":"7d","used_percent":20,"resets_at":"2030-01-01T00:00:00Z"}]},
        {"id":"two","label":"Two","provider":"grok","disabled":false,"windows":[{"label":"7d","used_percent":60,"resets_at":"2031-01-01T00:00:00Z"}]},
        {"id":"off","label":"Off","provider":"grok","disabled":true,"windows":[{"label":"7d","used_percent":100}]}
    ]);
    let snapshot = json!({"providers":snapshot::pools(array(&accounts)),"collected_at":"2026-09-21T10:00:00+00:00"});
    let view = snapshot::codexbar(&snapshot);
    assert_eq!(view["providers"][0]["id"], "xai");
    assert_eq!(view["providers"][0]["windows"][0]["usedPercent"], 40.);
    assert_eq!(view["providers"][0]["windows"][0]["coveredAccounts"], 2);
    assert_eq!(
        snapshot::quota_stats(&snapshot)["providers"]["grok"]["remaining_percent"],
        60.
    );
    assert!(number(&view["updatedAt"]).unwrap() > 0.);
}
#[test]
fn model_normalization_and_retry_after() {
    let models = models_from_file(
        &json!({"provider":"antigravity"}),
        &json!({"models":[{"id":"a","owned_by":"Anthropic"},{"id":"a","owned_by":"openai"},{"id":"b","owned_by":"moonshot"},{"id":"c","owned_by":"google"},{"id":"d","type":"openai"},{"id":5}]}),
    );
    assert_eq!(
        json!(models),
        json!([{"id":"a","provider":"claude"},{"id":"b","provider":"kimi"},{"id":"c","provider":"google"},{"id":"d","provider":"antigravity"}])
    );
    assert_eq!(retry_delay(&json!({"retry-after":["10"]}), 0.), 10.);
    assert_eq!(
        retry_delay(&json!({"Retry-After":"Thu, 01 Jan 1970 00:01:00 GMT"}), 10.),
        50.
    );
    assert_eq!(retry_delay(&json!({"Retry-After":"invalid"}), 10.), 0.);
}

#[tokio::test]
async fn corrupt_cache_is_reported_without_killing_the_poll_loop() {
    let cache = Memory::default();
    let collector = Collector::new(
        cache.clone(),
        api(|_, _, _| panic!("invalid cache must not poll")),
    );
    cache.set(SNAPSHOT_KEY, "[]", None).await.unwrap();
    assert_eq!(collector.collect(1000.).await, Err(Error::Cache));
    assert_eq!(cache.get(LOCK_KEY).await.unwrap(), None);
    cache
        .set(SNAPSHOT_KEY, r#"{"accounts":[]}"#, None)
        .await
        .unwrap();
    cache
        .set("orbit:ai-router:account:account", "false", None)
        .await
        .unwrap();
    assert_eq!(
        collector.account(&file("codex"), &[], 1000.).await,
        Err(Error::Cache)
    );
    assert_eq!(
        crate::quota::timestamp(&json!("2026-09-21T10:00:00+0000")),
        crate::quota::timestamp(&json!("2026-09-21T10:00:00Z"))
    );
}

#[tokio::test]
async fn grok_period_only_response_uses_the_text_billing_proxy_once() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let recorded = calls.clone();
    let collector = Collector::new(
        Memory::default(),
        api(move |method, path, body| {
            assert_eq!((method, path), ("POST", "/api-call"));
            let body = body.unwrap();
            assert_eq!(body["auth_index"], "account");
            assert_eq!(body["header"]["Authorization"], "Bearer $TOKEN$");
            let mut calls = recorded.lock().unwrap();
            calls.push(body.clone());
            if calls.len() == 1 {
                assert_eq!(body["header"]["X-XAI-Token-Auth"], "xai-grok-cli");
                Ok(
                    json!({"status_code":200,"body":{"config":{"currentPeriod":{"type":"USAGE_PERIOD_TYPE_WEEKLY"}}}}),
                )
            } else {
                assert_eq!(body["header"]["Content-Type"], "application/grpc-web-text");
                assert_eq!(
                    body["url"],
                    "https://grok.com/grok_api_v2.GrokBuildBilling/GetGrokCreditsConfig"
                );
                assert_eq!(body["data"], "AAAAAAA=");
                Ok(
                    json!({"status_code":200,"body":crate::grpc::tests::fixture(None, 900, 2000, "0", &[], 1)}),
                )
            }
        }),
    );
    let account = collector
        .account(&file("xai"), &[], 1000.)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(account["windows"][0]["remaining_percent"], 100.);
    assert_eq!(account["next_check_at"], 1300.);
    assert_eq!(calls.lock().unwrap().len(), 2);
}
