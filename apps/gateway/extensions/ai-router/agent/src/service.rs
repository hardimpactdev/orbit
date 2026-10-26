use crate::{
    collector::{Collector, Error, Management},
    quota::{array, now},
    snapshot,
    valkey::Valkey,
};
use axum::{
    body::Bytes,
    extract::{Path, State},
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, patch, put},
    Json, Router,
};
use orbit_agent_contract::{Platform, Service};
use serde_json::{json, Value};
use std::{sync::Arc, time::Duration};

pub struct CollectorService;
impl Service for CollectorService {
    const NAME: &'static str = "ai-router-collector";
    fn supported(platform: Platform) -> bool {
        platform == Platform::Linux
    }
    fn run(arguments: &[String]) -> i32 {
        if !arguments.is_empty() {
            eprintln!("The AI router collector accepts no arguments.");
            return 1;
        }
        let result = Config::load(|name| std::env::var(name).ok()).and_then(|config| {
            let runtime = tokio::runtime::Builder::new_multi_thread()
                .enable_all()
                .build()
                .map_err(|_| Error::Invalid("Collector runtime unavailable"))?;
            runtime.block_on(serve(config))
        });
        match result {
            Ok(()) => 0,
            Err(error) => {
                eprintln!("{}", error.message());
                1
            }
        }
    }
}
struct Config {
    port: u16,
    cache: Valkey,
    origin: String,
    key: String,
    read_token: String,
    control_token: String,
}
impl Config {
    fn load(env: impl Fn(&str) -> Option<String>) -> Result<Self, Error> {
        let required = |name| {
            env(name)
                .filter(|s| !s.is_empty())
                .ok_or(Error::Invalid("Missing collector configuration"))
        };
        let port = |name| {
            required(name)?
                .parse::<u16>()
                .ok()
                .filter(|p| *p > 0)
                .ok_or(Error::Invalid("Invalid collector port"))
        };
        Ok(Self {
            port: port("AI_ROUTER_PORT")?,
            cache: Valkey {
                host: required("AI_ROUTER_CACHE_HOST")?,
                port: port("AI_ROUTER_CACHE_PORT")?,
                username: env("AI_ROUTER_CACHE_USERNAME").unwrap_or_default(),
                password: env("AI_ROUTER_CACHE_PASSWORD").unwrap_or_default(),
            },
            origin: required("AI_ROUTER_CLIPROXY_URL")?,
            key: required("AI_ROUTER_MANAGEMENT_KEY")?,
            read_token: required("AI_ROUTER_READ_TOKEN")?,
            control_token: required("AI_ROUTER_CONTROL_TOKEN")?,
        })
    }
}
struct App {
    collector: Collector<Valkey, Management>,
    read_token: String,
    control_token: String,
}
async fn serve(config: Config) -> Result<(), Error> {
    let app = Arc::new(App {
        collector: Collector::new(config.cache, Management::new(config.origin, config.key)?),
        read_token: config.read_token,
        control_token: config.control_token,
    });
    let listener = tokio::net::TcpListener::bind((std::net::Ipv4Addr::LOCALHOST, config.port))
        .await
        .map_err(|_| Error::Invalid("Collector listener unavailable"))?;
    let worker = app.clone();
    let task = tokio::spawn(async move {
        loop {
            if let Err(error) = worker.collector.collect(now()).await {
                eprintln!("Quota collection failed: {}", error.message());
            }
            tokio::time::sleep(Duration::from_secs(60)).await;
        }
    });
    let result = axum::serve(listener, router(app))
        .await
        .map_err(|_| Error::Invalid("Collector listener failed"));
    task.abort();
    result
}
fn router(app: Arc<App>) -> Router {
    Router::new()
        .route("/health", get(|| async { Json(json!({"ok": true})) }))
        .route("/api/v1/usage", get(usage))
        .route("/api/v1/providers/{provider}", get(provider))
        .route("/v1/quota-stats", get(stats))
        .route("/quota-stats", get(stats))
        .route("/v1/providers", get(providers))
        .route("/v1/accounts/{account}", patch(toggle))
        .route(
            "/api/v1/providers/{provider}/accounts/{account}",
            put(put_account),
        )
        .fallback(|| async { response(404, json!({"error": "not_found"})) })
        .with_state(app)
}
fn response(status: u16, body: Value) -> Response {
    (
        StatusCode::from_u16(status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR),
        Json(body),
    )
        .into_response()
}
fn authorized(headers: &HeaderMap, token: &str) -> bool {
    !token.is_empty()
        && headers
            .get("authorization")
            .and_then(|v| v.to_str().ok())
            .is_some_and(|v| v.strip_prefix("Bearer ") == Some(token))
}
async fn read(app: &App, headers: &HeaderMap) -> Result<Value, Response> {
    if !authorized(headers, &app.read_token) {
        return Err(response(401, json!({"error": "unauthorized"})));
    }
    app.collector
        .snapshot(now())
        .await
        .map_err(|_| response(502, json!({"error": "snapshot_unavailable"})))
}
async fn usage(State(app): State<Arc<App>>, headers: HeaderMap) -> Response {
    match read(&app, &headers).await {
        Ok(s) => response(200, snapshot::codexbar(&s)),
        Err(r) => r,
    }
}
async fn provider(
    State(app): State<Arc<App>>,
    Path(provider): Path<String>,
    headers: HeaderMap,
) -> Response {
    match read(&app, &headers).await {
        Ok(s) => {
            let data = snapshot::codexbar(&s);
            let provider = array(&data["providers"])
                .iter()
                .find(|p| p["id"] == provider);
            response(
                if provider.is_some() { 200 } else { 404 },
                json!({"updatedAt": data["updatedAt"], "provider": provider}),
            )
        }
        Err(r) => r,
    }
}
async fn stats(State(app): State<Arc<App>>, headers: HeaderMap) -> Response {
    match read(&app, &headers).await {
        Ok(s) => response(200, snapshot::quota_stats(&s)),
        Err(r) => r,
    }
}
async fn providers(State(app): State<Arc<App>>, headers: HeaderMap) -> Response {
    match read(&app, &headers).await {
        Ok(s) => response(200, s["providers"].clone()),
        Err(r) => r,
    }
}
async fn toggle(
    State(app): State<Arc<App>>,
    Path(account): Path<String>,
    headers: HeaderMap,
    body: Bytes,
) -> Response {
    if !authorized(&headers, &app.control_token) {
        return response(401, json!({"error": "unauthorized"}));
    }
    let Ok(body) = serde_json::from_slice::<Value>(&body) else {
        return response(422, json!({"error": "invalid_json"}));
    };
    match app
        .collector
        .toggle(&account, body["disabled"] == true, now())
        .await
    {
        Ok(account) => response(200, account),
        Err(Error::Http(404, _)) => response(404, json!({"error": "not_found"})),
        Err(_) => response(502, json!({"error": "account_update_failed"})),
    }
}
async fn put_account(
    State(app): State<Arc<App>>,
    Path((provider, account)): Path<(String, String)>,
    headers: HeaderMap,
    body: Bytes,
) -> Response {
    if !authorized(&headers, &app.control_token) {
        return response(401, json!({"error": "unauthorized"}));
    }
    let provider = if provider == "xai" { "grok" } else { &provider };
    let Ok(snapshot) = app.collector.snapshot(now()).await else {
        return response(502, json!({"error": "snapshot_unavailable"}));
    };
    if !array(&snapshot["accounts"])
        .iter()
        .any(|a| a["id"] == account && a["provider"] == provider)
    {
        return response(404, json!({"error": "not_found"}));
    }
    let body = serde_json::from_slice::<Value>(&body).unwrap_or(Value::Null);
    let Some(disabled) = body["disabled"].as_bool() else {
        return response(422, json!({"error": "disabled_must_be_boolean"}));
    };
    if app
        .collector
        .toggle(&account, disabled, now())
        .await
        .is_err()
    {
        return response(502, json!({"error": "account_update_failed"}));
    }
    match app.collector.snapshot(now()).await {
        Ok(s) => response(200, snapshot::codexbar(&s)),
        Err(_) => response(502, json!({"error": "snapshot_unavailable"})),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{collector::SNAPSHOT_KEY, valkey::tests::Server};
    #[test]
    fn refuses_missing_configuration_before_starting() {
        let config = [
            ("AI_ROUTER_PORT", "8787"),
            ("AI_ROUTER_CACHE_HOST", "127.0.0.1"),
            ("AI_ROUTER_CACHE_PORT", "6379"),
            ("AI_ROUTER_CLIPROXY_URL", "http://127.0.0.1:8317"),
            ("AI_ROUTER_MANAGEMENT_KEY", "management"),
            ("AI_ROUTER_READ_TOKEN", "read"),
            ("AI_ROUTER_CONTROL_TOKEN", "control"),
        ];
        assert!(Config::load(|key| config
            .iter()
            .find(|(name, _)| key == *name)
            .map(|(_, v)| (*v).into()))
        .is_ok());
        for (missing, _) in config {
            assert!(Config::load(|key| config
                .iter()
                .find(|(name, _)| key == *name && key != missing)
                .map(|(_, v)| (*v).into()))
            .is_err());
        }
        assert_eq!(CollectorService::run(&["unexpected".into()]), 1);
    }
    #[tokio::test]
    async fn http_reads_only_cache_and_separates_read_and_control_authority() {
        let cache = Server::start().await;
        cache.values.lock().unwrap().insert(SNAPSHOT_KEY.into(), json!({"accounts":[{"id":"a","provider":"grok","label":"A","disabled":false,"windows":[{"label":"7d","used_percent":42,"remaining_percent":58}]}],"models":[],"collected_at":"2026-09-21T10:00:00Z"}).to_string());
        let calls = Arc::new(std::sync::Mutex::new(Vec::<(String, Value)>::new()));
        let recorded = calls.clone();
        let management = Router::new()
            .route(
                "/v0/management/auth-files",
                get(|| async {
                    Json(json!({"files":[{"name":"grok.json","auth_index":"a","provider":"xai"}]}))
                }),
            )
            .route(
                "/v0/management/auth-files/status",
                patch(move |headers: HeaderMap, Json(body): Json<Value>| {
                    let recorded = recorded.clone();
                    async move {
                        recorded
                            .lock()
                            .unwrap()
                            .push((headers["authorization"].to_str().unwrap().into(), body));
                        Json(json!({}))
                    }
                }),
            );
        let upstream = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let origin = format!("http://{}", upstream.local_addr().unwrap());
        let upstream_task =
            tokio::spawn(async move { axum::serve(upstream, management).await.unwrap() });
        let app = Arc::new(App {
            collector: Collector::new(
                cache.client(),
                Management::new(origin, "management-secret".into()).unwrap(),
            ),
            read_token: "read".into(),
            control_token: "control".into(),
        });
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let origin = format!("http://{}", listener.local_addr().unwrap());
        let task = tokio::spawn(async move { axum::serve(listener, router(app)).await.unwrap() });
        let client = reqwest::Client::new();
        assert_eq!(
            client
                .get(format!("{origin}/health"))
                .send()
                .await
                .unwrap()
                .status(),
            200
        );
        for path in [
            "/api/v1/usage",
            "/api/v1/providers/xai",
            "/v1/quota-stats",
            "/quota-stats",
            "/v1/providers",
        ] {
            assert_eq!(
                client
                    .get(format!("{origin}{path}"))
                    .bearer_auth("control")
                    .send()
                    .await
                    .unwrap()
                    .status(),
                401
            );
            assert_eq!(
                client
                    .get(format!("{origin}{path}"))
                    .bearer_auth("read")
                    .send()
                    .await
                    .unwrap()
                    .status(),
                200
            );
        }
        assert!(calls.lock().unwrap().is_empty());
        let path = format!("{origin}/api/v1/providers/xai/accounts/a");
        assert_eq!(
            client
                .put(&path)
                .bearer_auth("read")
                .json(&json!({"disabled":true}))
                .send()
                .await
                .unwrap()
                .status(),
            401
        );
        assert_eq!(
            client
                .put(&path)
                .bearer_auth("control")
                .json(&json!({"disabled":"true"}))
                .send()
                .await
                .unwrap()
                .status(),
            422
        );
        assert_eq!(
            client
                .put(path.replace("/xai/", "/codex/"))
                .bearer_auth("control")
                .json(&json!({"disabled":true}))
                .send()
                .await
                .unwrap()
                .status(),
            404
        );
        let result = client
            .put(&path)
            .bearer_auth("control")
            .json(&json!({"disabled":true}))
            .send()
            .await
            .unwrap();
        assert_eq!(result.status(), 200);
        assert_eq!(
            result.json::<Value>().await.unwrap()["providers"][0]["disabledAccounts"],
            1
        );
        assert_eq!(
            *calls.lock().unwrap(),
            [(
                "Bearer management-secret".into(),
                json!({"name":"grok.json","disabled":true})
            )]
        );
        task.abort();
        upstream_task.abort();
    }
}
