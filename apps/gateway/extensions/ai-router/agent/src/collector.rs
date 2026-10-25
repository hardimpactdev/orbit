use crate::{
    grpc,
    quota::{array, iso, now, number, text, windows},
    snapshot,
};
use serde_json::{json, Map, Value};
use std::{collections::BTreeSet, time::Duration};
use time::{format_description::well_known::Rfc2822, OffsetDateTime};

pub const LOCK_KEY: &str = "orbit:ai-router:lock";
pub const RAW_KEY: &str = "orbit:ai-router:raw";
pub const SNAPSHOT_KEY: &str = "orbit:ai-router:snapshot";
pub const MODEL_FILES_KEY: &str = "orbit:ai-router:model-files";
pub const ACCOUNT_PREFIX: &str = "orbit:ai-router:account:";
pub const BACKOFF_PREFIX: &str = "orbit:ai-router:backoff:";

#[derive(Debug, Clone, PartialEq)]
pub enum Error {
    Http(u16, f64),
    Invalid(&'static str),
    Cache,
    Request,
}
impl Error {
    pub fn message(&self) -> String {
        match self {
            Self::Http(status, _) => format!("HTTP {status}"),
            Self::Invalid(message) => (*message).into(),
            Self::Cache => "Quota cache unavailable".into(),
            Self::Request => "Quota request failed".into(),
        }
    }
}
pub trait Cache: Send + Sync {
    fn get(
        &self,
        key: &str,
    ) -> impl std::future::Future<Output = Result<Option<String>, Error>> + Send;
    fn set(
        &self,
        key: &str,
        value: &str,
        seconds: Option<u64>,
    ) -> impl std::future::Future<Output = Result<(), Error>> + Send;
    fn acquire(
        &self,
        holder: &str,
    ) -> impl std::future::Future<Output = Result<bool, Error>> + Send;
    fn release(&self, holder: &str) -> impl std::future::Future<Output = Result<(), Error>> + Send;
}
pub trait Api: Send + Sync {
    fn request(
        &self,
        method: &str,
        path: &str,
        body: Option<Value>,
    ) -> impl std::future::Future<Output = Result<Value, Error>> + Send;
}
pub struct Management {
    client: reqwest::Client,
    origin: String,
    key: String,
}
impl Management {
    pub fn new(origin: String, key: String) -> Result<Self, Error> {
        let origin = origin
            .trim_end_matches('/')
            .trim_end_matches("/v0/management")
            .to_owned();
        let url = reqwest::Url::parse(&origin)
            .map_err(|_| Error::Invalid("Invalid CLIProxyAPI origin"))?;
        if !matches!(url.scheme(), "http" | "https")
            || url.host_str().is_none()
            || !url.username().is_empty()
            || url.password().is_some()
            || url.query().is_some()
            || url.fragment().is_some()
        {
            return Err(Error::Invalid("Invalid CLIProxyAPI origin"));
        }
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(10))
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .map_err(|_| Error::Request)?;
        Ok(Self {
            client,
            origin,
            key,
        })
    }
}
impl Api for Management {
    async fn request(&self, method: &str, path: &str, body: Option<Value>) -> Result<Value, Error> {
        let method = reqwest::Method::from_bytes(method.as_bytes()).map_err(|_| Error::Request)?;
        let mut request = self
            .client
            .request(method, format!("{}/v0/management{path}", self.origin))
            .bearer_auth(&self.key)
            .header("Accept", "application/json")
            .header("Content-Type", "application/json");
        if let Some(body) = body {
            request = request.json(&body);
        }
        let response = request.send().await.map_err(|_| Error::Request)?;
        if !response.status().is_success() {
            let retry = response
                .headers()
                .get("retry-after")
                .and_then(|s| s.to_str().ok())
                .unwrap_or("");
            return Err(Error::Http(
                response.status().as_u16(),
                retry_delay(&json!({"Retry-After": retry}), now()),
            ));
        }
        let bytes = response.bytes().await.map_err(|_| Error::Request)?;
        let body: Value = if bytes.is_empty() {
            json!({})
        } else {
            serde_json::from_slice(&bytes)
                .map_err(|_| Error::Invalid("Invalid CLIProxyAPI response"))?
        };
        Ok(if body.is_object() { body } else { json!({}) })
    }
}
pub fn retry_delay(headers: &Value, now: f64) -> f64 {
    let Some(headers) = headers.as_object() else {
        return 0.;
    };
    for (key, value) in headers {
        if !key.eq_ignore_ascii_case("retry-after") {
            continue;
        }
        let value = value.as_array().and_then(|a| a.first()).unwrap_or(value);
        if let Some(seconds) = number(value) {
            return seconds.max(0.);
        }
        return OffsetDateTime::parse(&text(value).replace("GMT", "+0000"), &Rfc2822)
            .map(|d| (d.unix_timestamp() as f64 - now).max(0.))
            .unwrap_or(0.);
    }
    0.
}
pub struct Collector<C, A> {
    pub cache: C,
    pub api: A,
    holder: String,
}
impl<C: Cache, A: Api> Collector<C, A> {
    pub fn new(cache: C, api: A) -> Self {
        Self {
            cache,
            api,
            holder: format!("{:032x}", rand::random::<u128>()),
        }
    }
    pub async fn collect(&self, now: f64) -> Result<(), Error> {
        if !self.cache.acquire(&self.holder).await? {
            return Ok(());
        }
        let result = self.collect_locked(now).await;
        let release = self.cache.release(&self.holder).await;
        result.and(release)
    }
    async fn collect_locked(&self, now: f64) -> Result<(), Error> {
        let previous = self.snapshot(now).await?;
        let files = self.auth_files().await?;
        let mut accounts = Vec::new();
        for file in &files {
            if let Some(account) = self
                .account(file, array(&previous["accounts"]), now)
                .await?
            {
                accounts.push(account);
            }
        }
        let models = self.models(&files).await?;
        self.publish(json!({"providers": snapshot::pools(&accounts), "accounts": accounts, "models": models, "collected_at": iso(now)})).await
    }
    pub async fn snapshot(&self, now: f64) -> Result<Value, Error> {
        let snapshot: Value = match self.cache.get(SNAPSHOT_KEY).await? {
            Some(raw) => serde_json::from_str(&raw).map_err(|_| Error::Cache)?,
            None => json!({"accounts": [], "providers": [], "collected_at": null}),
        };
        if !snapshot.is_object() {
            return Err(Error::Cache);
        }
        Ok(snapshot::fresh(snapshot, now))
    }
    async fn publish(&self, snapshot: Value) -> Result<(), Error> {
        self.cache.set(RAW_KEY, &json!({"accounts": snapshot["accounts"], "collected_at": snapshot["collected_at"]}).to_string(), None).await?;
        self.cache
            .set(SNAPSHOT_KEY, &snapshot.to_string(), None)
            .await
    }
    pub async fn toggle(&self, account: &str, disabled: bool, now: f64) -> Result<Value, Error> {
        let files = self.auth_files().await?;
        let file = files
            .iter()
            .find(|f| account_id(f) == account)
            .ok_or(Error::Http(404, 0.))?;
        self.api
            .request(
                "PATCH",
                "/auth-files/status",
                Some(json!({"name": file["name"], "disabled": disabled})),
            )
            .await?;
        let snapshot = self.snapshot(now).await?;
        let mut accounts = array(&snapshot["accounts"]).to_vec();
        let mut result = json!({"id": account, "disabled": disabled});
        for item in &mut accounts {
            if item["id"] == account {
                item["disabled"] = json!(disabled);
                item["status"] = json!(if disabled { "disabled" } else { "enabled" });
                result = item.clone();
            }
        }
        self.publish(json!({"providers": snapshot::pools(&accounts), "accounts": accounts, "models": array(&snapshot["models"]), "collected_at": snapshot["collected_at"]})).await?;
        Ok(result)
    }
    async fn auth_files(&self) -> Result<Vec<Value>, Error> {
        let payload = self.api.request("GET", "/auth-files", None).await?;
        Ok(array(&payload["files"])
            .iter()
            .filter(|f| f.is_object())
            .cloned()
            .collect())
    }
    async fn models(&self, files: &[Value]) -> Result<Vec<Value>, Error> {
        let previous: Value = self
            .cache
            .get(MODEL_FILES_KEY)
            .await?
            .and_then(|raw| serde_json::from_str(&raw).ok())
            .unwrap_or(json!({}));
        let mut by_file = Map::new();
        let mut seen = BTreeSet::new();
        let mut ordered = Vec::new();
        for file in files {
            let name = text(&file["name"]);
            if name.is_empty() {
                continue;
            }
            let mut url =
                reqwest::Url::parse("http://localhost/auth-files/models").expect("fixed URL");
            url.query_pairs_mut().append_pair("name", name);
            let path = format!("{}?{}", url.path(), url.query().unwrap_or_default());
            let current = match self.api.request("GET", &path, None).await {
                Ok(payload) => models_from_file(file, &payload),
                Err(_) => array(&previous[name])
                    .iter()
                    .filter(|m| !text(&m["id"]).is_empty() && !text(&m["provider"]).is_empty())
                    .map(|m| json!({"id": m["id"], "provider": m["provider"]}))
                    .collect(),
            };
            for model in &current {
                if seen.insert(text(&model["id"]).to_owned()) {
                    ordered.push(model.clone());
                }
            }
            by_file.insert(name.into(), json!(current));
        }
        self.cache
            .set(MODEL_FILES_KEY, &Value::Object(by_file).to_string(), None)
            .await?;
        Ok(ordered)
    }
    async fn account(
        &self,
        file: &Value,
        previous: &[Value],
        now: f64,
    ) -> Result<Option<Value>, Error> {
        let id = account_id(file);
        let Some(provider) = provider(file).filter(|_| !id.is_empty()) else {
            return Ok(None);
        };
        let key = format!("{ACCOUNT_PREFIX}{id}");
        let previous: Value = match self.cache.get(&key).await? {
            Some(raw) => serde_json::from_str(&raw).map_err(|_| Error::Cache)?,
            None => previous
                .iter()
                .find(|a| a["id"] == id)
                .cloned()
                .unwrap_or(json!({})),
        };
        if !previous.is_object() {
            return Err(Error::Cache);
        }
        let mut account = previous.clone();
        let label = ["email", "label", "name"]
            .iter()
            .map(|k| text(&file[k]))
            .find(|s| !s.is_empty())
            .unwrap_or("account");
        for (key, value) in json!({"id": id, "provider": provider, "label": label, "disabled": file["disabled"] == true, "status": file["status"].as_str().filter(|s| !s.is_empty()).unwrap_or("enabled"), "windows": array(&previous["windows"]), "error": previous["error"], "success": file.get("success").unwrap_or(&json!(0)), "failed": file.get("failed").unwrap_or(&json!(0))}).as_object().expect("object") {
            account[key] = value.clone();
        }
        if account["disabled"] == true {
            account["status"] = json!("disabled");
            return Ok(Some(account));
        }
        let backoff_key = format!("{BACKOFF_PREFIX}{id}");
        let backed_off = self
            .cache
            .get(&backoff_key)
            .await?
            .and_then(|s| s.parse::<u64>().ok())
            .is_some_and(|until| until as f64 > now.floor());
        if number(&previous["next_check_at"]).unwrap_or(0.) > now || backed_off {
            if let Some(status) = previous.get("status") {
                account["status"] = status.clone();
            }
            return Ok(Some(account));
        }
        let mut reservation = account.clone();
        reservation["windows"] = json!([]);
        reservation["status"] = json!("error");
        reservation["error"] = json!("Quota check interrupted; retry delayed");
        reservation["next_check_at"] = json!(now + 3600.);
        self.cache.set(&key, &reservation.to_string(), None).await?;
        let interval = match self.quota(file, id, provider, now).await {
            Ok(windows) => {
                account["windows"] = json!(windows);
                account["error"] = Value::Null;
                account["failures"] = json!(0);
                if provider == "claude" {
                    900.
                } else {
                    300.
                }
            }
            Err(error) => {
                let failures = previous["failures"].as_u64().unwrap_or(0).saturating_add(1);
                let retry = match error {
                    Error::Http(_, retry) => retry,
                    _ => 0.,
                };
                let interval = (3600. * 2f64.powi(failures.saturating_sub(1).min(5) as i32))
                    .min(86400.)
                    .max(retry);
                let backoff = matches!(error, Error::Http(429, _));
                account["windows"] = json!([]);
                account["error"] = json!(error.message());
                account["failures"] = json!(failures);
                account["status"] = json!(if backoff { "backoff" } else { "error" });
                if backoff {
                    self.cache
                        .set(
                            &backoff_key,
                            &((now as u64).saturating_add(interval as u64)).to_string(),
                            Some((interval as u64).max(1)),
                        )
                        .await?;
                }
                interval
            }
        };
        account["checked_at"] = json!(now);
        account["next_check_at"] = json!(now + interval);
        self.cache.set(&key, &account.to_string(), None).await?;
        Ok(Some(account))
    }
    async fn quota(
        &self,
        file: &Value,
        id: &str,
        provider: &str,
        now: f64,
    ) -> Result<Vec<Value>, Error> {
        let url = match provider {
            "claude" => "https://api.anthropic.com/api/oauth/usage",
            "codex" => "https://chatgpt.com/backend-api/wham/usage",
            "grok" => "https://cli-chat-proxy.grok.com/v1/billing?format=credits",
            "kimi" => "https://api.kimi.com/coding/v1/usages",
            "antigravity" => {
                "https://daily-cloudcode-pa.googleapis.com/v1internal:retrieveUserQuotaSummary"
            }
            _ => return Err(Error::Invalid("Unsupported quota provider")),
        };
        let mut header = json!({"Authorization": "Bearer $TOKEN$", "Accept": "application/json"});
        match provider {
            "claude" => {
                header["anthropic-beta"] = json!("oauth-2025-04-20");
                header["Content-Type"] = json!("application/json");
            }
            "codex" => {
                let id = file["account_id"]
                    .as_str()
                    .filter(|s| !s.is_empty())
                    .or_else(|| file["id_token"]["chatgpt_account_id"].as_str())
                    .unwrap_or_default();
                if !id.is_empty() {
                    header["ChatGPT-Account-Id"] = json!(id);
                }
            }
            "grok" => header["X-XAI-Token-Auth"] = json!("xai-grok-cli"),
            "antigravity" => {
                header["User-Agent"] =
                    json!("antigravity/cli/1.0.13 (aidev_client; os_type=darwin; arch=arm64)")
            }
            _ => {}
        }
        let mut request = json!({"auth_index": id, "method": "GET", "url": url, "header": header});
        if provider == "antigravity" {
            if text(&file["project_id"]).is_empty() {
                return Err(Error::Invalid("Antigravity project is missing"));
            }
            request["method"] = json!("POST");
            request["data"] = json!(json!({"project": file["project_id"]}).to_string());
        }
        let payload = self.api.request("POST", "/api-call", Some(request)).await?;
        check_status(&payload, now)?;
        let mut windows = windows(provider, &payload["body"]).map_err(Error::Invalid)?;
        if windows.is_empty() && provider == "grok" {
            let payload = self.api.request("POST", "/api-call", Some(json!({"auth_index": id, "method": "POST", "url": "https://grok.com/grok_api_v2.GrokBuildBilling/GetGrokCreditsConfig", "header": {"Authorization": "Bearer $TOKEN$", "Content-Type": "application/grpc-web-text", "Accept": "application/grpc-web-text", "Origin": "https://grok.com", "Referer": "https://grok.com/?_s=usage", "User-Agent": "CodexBar", "x-grpc-web": "1", "x-user-agent": "connect-es/2.1.1"}, "data": "AAAAAAA="}))).await?;
            check_status(&payload, now)?;
            windows = grpc::windows(&payload["body"], now).map_err(Error::Invalid)?;
        }
        if windows.is_empty() {
            return Err(Error::Invalid("Provider returned no quota windows"));
        }
        Ok(windows)
    }
}
fn check_status(payload: &Value, now: f64) -> Result<(), Error> {
    let status = payload["status_code"].as_u64().unwrap_or(200);
    if (200..300).contains(&status) {
        return Ok(());
    }
    Err(Error::Http(
        u16::try_from(status).unwrap_or(502),
        retry_delay(
            payload
                .get("header")
                .filter(|v| v.is_object() && !v.as_object().unwrap().is_empty())
                .unwrap_or(&payload["headers"]),
            now,
        ),
    ))
}
fn account_id(file: &Value) -> &str {
    file["auth_index"]
        .as_str()
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| text(&file["name"]))
}
fn provider(file: &Value) -> Option<&'static str> {
    for key in ["provider", "type", "account_type", "label", "name"] {
        let value = text(&file[key]).to_lowercase();
        if value == "antigravity" {
            return Some("antigravity");
        }
        if value == "anthropic" || value.contains("claude") {
            return Some("claude");
        }
        if value == "openai" || value.contains("codex") || value.contains("gpt") {
            return Some("codex");
        }
        if value == "xai" || value.contains("grok") {
            return Some("grok");
        }
        if value == "moonshot" || value.contains("kimi") {
            return Some("kimi");
        }
    }
    None
}
fn model_provider(value: &str) -> &str {
    match value.to_lowercase().as_str() {
        "openai" => "codex",
        "anthropic" => "claude",
        "xai" => "grok",
        "moonshot" => "kimi",
        _ => value,
    }
}
fn models_from_file(file: &Value, payload: &Value) -> Vec<Value> {
    let mut seen = BTreeSet::new();
    array(&payload["models"])
        .iter()
        .filter_map(|entry| {
            let id = text(&entry["id"]);
            let provider = entry["owned_by"]
                .as_str()
                .filter(|s| !s.is_empty())
                .unwrap_or_else(|| text(&file["provider"]));
            if id.is_empty() || provider.is_empty() || !seen.insert(id) {
                return None;
            }
            Some(json!({"id": id, "provider": model_provider(provider)}))
        })
        .collect()
}

#[cfg(test)]
#[path = "collector_tests.rs"]
mod tests;
