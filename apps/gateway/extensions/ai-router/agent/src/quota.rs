use serde_json::{json, Value};
use time::{format_description::well_known::Rfc3339, OffsetDateTime};

pub fn number(value: &Value) -> Option<f64> {
    value
        .as_f64()
        .or_else(|| value.as_str()?.parse().ok())
        .filter(|n| n.is_finite())
}
pub fn timestamp(value: &Value) -> Option<f64> {
    value.as_f64().or_else(|| {
        let mut input = value.as_str()?.to_owned();
        // ISO 8601 permits an offset without a colon, including the stored poll timestamp.
        let bytes = input.as_bytes();
        if bytes.len() > 5
            && matches!(bytes[bytes.len() - 5], b'+' | b'-')
            && bytes[bytes.len() - 4..].iter().all(u8::is_ascii_digit)
        {
            input.insert(input.len() - 2, ':');
        }
        OffsetDateTime::parse(&input, &Rfc3339)
            .ok()
            .map(|t| t.unix_timestamp_nanos() as f64 / 1e9)
    })
}
pub fn iso(seconds: f64) -> Option<String> {
    if !seconds.is_finite() {
        return None;
    }
    OffsetDateTime::from_unix_timestamp_nanos((seconds * 1e9) as i128)
        .ok()?
        .format(&Rfc3339)
        .ok()
}
pub fn now() -> f64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs_f64()
}
pub fn array(value: &Value) -> &[Value] {
    value.as_array().map(Vec::as_slice).unwrap_or_default()
}
pub fn text(value: &Value) -> &str {
    value.as_str().unwrap_or_default()
}
fn present<'a>(values: &[&'a Value]) -> &'a Value {
    values
        .iter()
        .find(|v| !v.is_null() && **v != "")
        .copied()
        .unwrap_or(&Value::Null)
}
fn push(result: &mut Vec<Value>, title: &str, used: Option<f64>, reset: &Value) {
    let Some(used) = used.filter(|n| n.is_finite()) else {
        return;
    };
    let used = used.clamp(0., 100.);
    let label = match title {
        "5-hour limit" | "5h limit" => "5h",
        "7-day limit" | "Weekly limit" => "7d",
        "Daily limit" => "1d",
        _ => title,
    };
    result.push(
        json!({"label": label, "used_percent": used, "remaining_percent": 100. - used,
        "resets_at": timestamp(reset).and_then(iso)}),
    );
}
pub fn sort(windows: &mut [Value]) {
    fn rank(label: &str) -> u8 {
        if label.ends_with(['d', 'w']) {
            0
        } else if label.ends_with(['h', 'm', 's']) {
            1
        } else {
            2
        }
    }
    windows.sort_by(|a, b| {
        (rank(text(&a["label"])), text(&a["label"]))
            .cmp(&(rank(text(&b["label"])), text(&b["label"])))
    });
}
pub fn windows(provider: &str, body: &Value) -> Result<Vec<Value>, &'static str> {
    let parsed;
    let body = if let Some(body) = body.as_str() {
        parsed =
            serde_json::from_str::<Value>(body).map_err(|_| "Invalid provider quota response")?;
        &parsed
    } else {
        body
    };
    let mut result = Vec::new();
    match provider {
        "codex" => {
            let mut limits = vec![("", &body["rate_limit"])];
            for item in array(&body["additional_rate_limits"]) {
                let scope = text(present(&[&item["limit_name"], &item["metered_feature"]]));
                limits.push((
                    if scope.is_empty() {
                        "Additional"
                    } else {
                        scope
                    },
                    &item["rate_limit"],
                ));
            }
            for (scope, limit) in limits {
                for slot in ["primary_window", "secondary_window"] {
                    let w = &limit[slot];
                    let seconds = number(&w["limit_window_seconds"]).unwrap_or(0.) as i64;
                    let title = match seconds {
                        18000 => "5-hour limit".into(),
                        86400 => "Daily limit".into(),
                        604800 => "Weekly limit".into(),
                        0 => "Quota".into(),
                        _ => format!("{}-hour limit", seconds / 3600),
                    };
                    let title = if scope.is_empty() {
                        title
                    } else {
                        format!("{scope} {title}")
                    };
                    push(
                        &mut result,
                        &title,
                        number(&w["used_percent"]),
                        &w["reset_at"],
                    );
                }
            }
        }
        "grok" => {
            let c = if body["config"].is_object() {
                &body["config"]
            } else {
                body
            };
            let period = &c["currentPeriod"];
            push(
                &mut result,
                if text(&period["type"]).to_uppercase().contains("MONTHLY") {
                    "Monthly credits"
                } else {
                    "Weekly limit"
                },
                number(&c["creditUsagePercent"]),
                &period["end"],
            );
        }
        "kimi" => {
            for (index, item) in array(&body["limits"]).iter().enumerate() {
                let detail = if item["detail"].is_object() {
                    &item["detail"]
                } else {
                    item
                };
                let duration = number(present(&[
                    &item["window"]["duration"],
                    &item["duration"],
                    &detail["duration"],
                ]))
                .unwrap_or(0.) as i64;
                let unit = text(present(&[
                    &item["window"]["timeUnit"],
                    &item["timeUnit"],
                    &detail["timeUnit"],
                ]))
                .to_uppercase();
                let unit = unit.trim_start_matches("TIME_UNIT_").trim_end_matches('S');
                let mut suffix = match unit {
                    "SECOND" => "s",
                    "HOUR" => "h",
                    "DAY" => "d",
                    "WEEK" => "w",
                    _ => "m",
                };
                let mut amount = duration;
                if suffix == "m" && amount != 0 && amount % 60 == 0 {
                    amount /= 60;
                    suffix = "h";
                }
                let named = text(present(&[
                    &detail["name"],
                    &item["name"],
                    &detail["title"],
                    &item["title"],
                    &detail["scope"],
                    &item["scope"],
                ]))
                .trim();
                let title = if !named.is_empty() {
                    named.to_owned()
                } else if amount != 0 {
                    format!("{amount}{suffix} limit")
                } else {
                    format!("Limit #{}", index + 1)
                };
                kimi_window(&mut result, detail, &title);
            }
            if body["usage"].is_object() {
                let title = text(present(&[&body["usage"]["name"], &body["usage"]["title"]]));
                kimi_window(
                    &mut result,
                    &body["usage"],
                    if title.is_empty() {
                        "Weekly limit"
                    } else {
                        title
                    },
                );
            }
            if result.is_empty() {
                for (key, title) in [
                    ("limit_5h", "5h limit"),
                    ("limit_month_total", "Monthly limit"),
                    ("limit_month_code", "Monthly coding limit"),
                ] {
                    let w = &body["usages"][key];
                    push(
                        &mut result,
                        title,
                        number(&w["used_ratio"]).map(|n| n * 100.),
                        &w["reset_time"],
                    );
                }
            }
        }
        "antigravity" => {
            for group in array(&body["groups"]) {
                let name = match text(&group["displayName"]) {
                    "Gemini Models" => "Gemini models",
                    "Claude and GPT Models" => "Claude and GPT models",
                    "" => "Models",
                    other => other,
                };
                for bucket in array(&group["buckets"]) {
                    let label = match text(&bucket["window"]) {
                        "weekly" => "Weekly limit",
                        "5h" => "5-hour limit",
                        "daily" => "Daily limit",
                        "monthly" => "Monthly limit",
                        _ => "Quota",
                    };
                    push(
                        &mut result,
                        &format!("{name} · {label}"),
                        number(&bucket["remainingFraction"]).map(|n| 100. * (1. - n)),
                        &bucket["resetTime"],
                    );
                }
            }
        }
        "claude" => {
            let scoped: Vec<_> = array(&body["limits"])
                .iter()
                .filter(|w| {
                    w["kind"] == "weekly_scoped"
                        && matches!(
                            text(&w["scope"]["model"]["display_name"])
                                .to_lowercase()
                                .as_str(),
                            "fable" | "fable 5"
                        )
                        && number(&w["percent"]).is_some()
                })
                .collect();
            let fable = scoped
                .iter()
                .find(|w| w["is_active"] == true)
                .or_else(|| scoped.first());
            for (key, title) in [
                ("five_hour", "5-hour limit"),
                ("seven_day", "7-day limit"),
                ("seven_day_oauth_apps", "7-day OAuth apps"),
                ("seven_day_opus", "7-day Opus"),
                ("seven_day_sonnet", "7-day Sonnet"),
                ("seven_day_cowork", "7-day Cowork"),
                ("iguana_necktie", "7-day Fable 5"),
            ] {
                if key == "iguana_necktie" {
                    if let Some(w) = fable {
                        push(&mut result, title, number(&w["percent"]), &w["resets_at"]);
                        continue;
                    }
                }
                push(
                    &mut result,
                    title,
                    number(&body[key]["utilization"]),
                    &body[key]["resets_at"],
                );
            }
        }
        _ => {}
    }
    sort(&mut result);
    Ok(result)
}
fn kimi_window(result: &mut Vec<Value>, detail: &Value, title: &str) {
    if let Some(limit) = number(&detail["limit"]).filter(|n| *n > 0.) {
        let used =
            number(&detail["used"]).or_else(|| number(&detail["remaining"]).map(|n| limit - n));
        push(
            result,
            title,
            used.map(|n| 100. * n / limit),
            present(&[
                &detail["reset_at"],
                &detail["resetAt"],
                &detail["reset_time"],
                &detail["resetTime"],
            ]),
        );
    }
}
