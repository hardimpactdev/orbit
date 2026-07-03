use axum::{routing::get, Json, Router};
use orbit_agent::{
    build_service_status_snapshot, default_http_bind_addr, run_polling_worker_loop,
    ServiceStatusSnapshot,
};
use std::time::Duration;

#[tokio::main]
async fn main() {
    std::thread::spawn(|| run_polling_worker_loop(Duration::from_secs(15)));

    let app = Router::new()
        .route("/health", get(health))
        .route("/status", get(status));

    let bind_addr = default_http_bind_addr();
    let listener = tokio::net::TcpListener::bind(&bind_addr)
        .await
        .unwrap_or_else(|error| {
            panic!("failed to bind Orbit Agent HTTP service on {bind_addr}: {error}")
        });

    eprintln!("Orbit Agent service listening on http://{bind_addr}");

    axum::serve(listener, app)
        .await
        .expect("failed to run Orbit Agent HTTP service");
}

async fn health() -> Json<HealthResponse> {
    Json(HealthResponse {
        status: "ok".to_string(),
    })
}

async fn status() -> Json<ServiceStatusSnapshot> {
    Json(build_service_status_snapshot())
}

#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize)]
struct HealthResponse {
    status: String,
}

#[cfg(test)]
mod tests {
    use super::*;
    use orbit_agent::default_http_bind_addr;

    #[test]
    fn health_response_is_ok() {
        let response = HealthResponse {
            status: "ok".to_string(),
        };

        assert_eq!(response.status, "ok");
    }

    #[test]
    fn default_bind_addr_targets_loopback_agent_port() {
        assert_eq!(default_http_bind_addr(), "127.0.0.1:9477");
    }
}
