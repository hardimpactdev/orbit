use crate::quota::{array, number, sort, text, timestamp};
use serde_json::{json, Map, Value};
use std::collections::BTreeMap;

pub fn pools(accounts: &[Value]) -> Vec<Value> {
    let mut groups: BTreeMap<&str, Vec<&Value>> = BTreeMap::new();
    for account in accounts {
        groups
            .entry(text(&account["provider"]))
            .or_default()
            .push(account);
    }
    groups.into_iter().map(|(provider, members)| {
        let mut totals: BTreeMap<&str, (f64, usize, Option<&str>)> = BTreeMap::new();
        for account in &members {
            if account["disabled"] == true { continue; }
            for window in array(&account["windows"]) {
                let Some(label) = window["label"].as_str() else { continue; };
                let entry = totals.entry(label).or_default();
                entry.0 += number(&window["used_percent"]).unwrap_or(0.);
                entry.1 += 1;
                if let Some(reset) = window["resets_at"].as_str() { entry.2 = Some(entry.2.map_or(reset, |old| old.min(reset))); }
            }
        }
        let mut windows: Vec<_> = totals.into_iter().map(|(label, (sum, count, reset))| {
            let used = sum / count as f64;
            json!({"label": label, "used_percent": used, "remaining_percent": (100. - used).max(0.), "resets_at": reset})
        }).collect();
        sort(&mut windows);
        json!({"provider": provider, "accounts": members, "windows": windows})
    }).collect()
}
pub fn fresh(mut snapshot: Value, now: f64) -> Value {
    if let Some(accounts) = snapshot["accounts"].as_array_mut() {
        for account in accounts {
            if let Some(windows) = account["windows"].as_array_mut() {
                windows.retain(|w| timestamp(&w["resets_at"]).is_none_or(|reset| reset > now));
            }
        }
    }
    snapshot["providers"] = json!(pools(array(&snapshot["accounts"])));
    snapshot
}
pub fn quota_stats(snapshot: &Value) -> Value {
    let mut groups = Vec::new();
    let mut providers = Map::new();
    for pool in array(&snapshot["providers"]) {
        let mut lowest: Option<f64> = None;
        for window in array(&pool["windows"]) {
            groups.push(json!({"name": window["label"], "used_percent": window["used_percent"], "remaining_percent": window["remaining_percent"], "reset_at": window["resets_at"]}));
            if let Some(remaining) = number(&window["remaining_percent"]) {
                lowest = Some(lowest.map_or(remaining, |n| n.min(remaining)));
            }
        }
        if let Some(lowest) = lowest {
            providers.insert(
                text(&pool["provider"]).into(),
                json!({"remaining_percent": lowest}),
            );
        }
    }
    json!({"quota_groups": groups, "total_requests": 0, "total_tokens": 0, "providers": providers})
}
fn window(w: &Value, total: usize, covered: usize) -> Value {
    json!({"id": w["label"], "title": w["label"], "usedPercent": w["used_percent"], "resetAt": timestamp(&w["resets_at"]), "coveredAccounts": covered, "totalAccounts": total, "basis": if total > 1 { "Account average · estimated" } else { "Reported quota" }})
}
pub fn codexbar(snapshot: &Value) -> Value {
    let providers: Vec<_> = array(&snapshot["providers"]).iter().map(|pool| {
        let members = array(&pool["accounts"]);
        let active: Vec<_> = members.iter().filter(|a| a["disabled"] != true).collect();
        let accounts: Vec<_> = members.iter().map(|a| json!({"id": a["id"], "label": a["label"], "plan": "", "disabled": a["disabled"], "unavailable": matches!(text(&a["status"]), "error" | "backoff"), "error": a["error"], "checkedAt": a["checked_at"], "nextCheckAt": a["next_check_at"], "windows": array(&a["windows"]).iter().map(|w| window(w, 1, 1)).collect::<Vec<_>>()})).collect();
        let windows: Vec<_> = array(&pool["windows"]).iter().map(|w| window(w, active.len(), active.iter().filter(|a| array(&a["windows"]).iter().any(|v| v["label"] == w["label"])).count())).collect();
        let provider = text(&pool["provider"]);
        let mut chars = provider.chars();
        let name = chars.next().map(|c| c.to_uppercase().to_string() + chars.as_str()).unwrap_or_default();
        json!({"id": if provider == "grok" { "xai" } else { provider }, "name": name, "accounts": accounts, "activeAccounts": active.len(), "disabledAccounts": members.len() - active.len(), "success": members.iter().filter_map(|a| number(&a["success"])).sum::<f64>(), "failed": members.iter().filter_map(|a| number(&a["failed"])).sum::<f64>(), "windows": windows})
    }).collect();
    json!({"updatedAt": timestamp(&snapshot["collected_at"]).unwrap_or(0.), "providers": providers})
}
