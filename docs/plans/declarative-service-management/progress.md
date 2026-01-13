# Declarative Service Management - Progress Log

## Epic: launchpad-desktop-8tv

Track learnings, blockers, and decisions during implementation.

---

## Phase 1: DTOs and Template Loader (launchpad-desktop-8tv.1)

**Status:** Not started

### Learnings
- (none yet)

### Blockers
- (none yet)

### Decisions
- (none yet)

---

## Phase 2: Compose Generator and Service Manager (launchpad-desktop-8tv.2)

**Status:** Not started

### Learnings
- (none yet)

### Blockers
- (none yet)

### Decisions
- (none yet)

---

## Phase 3: CLI Commands (launchpad-desktop-8tv.3)

**Status:** Not started

### Learnings
- (none yet)

### Blockers
- (none yet)

### Decisions
- (none yet)

---

## Phase 4: Service Templates (launchpad-desktop-8tv.4)

**Status:** Not started

### Learnings
- (none yet)

### Blockers
- (none yet)

### Decisions
- (none yet)

---

## Phase 5: Update Existing Commands & Remove Legacy (launchpad-desktop-8tv.5)

**Status:** Complete

### Learnings
- ServiceManager automatically initializes services.yaml from stub if missing on first loadServices() call
- DockerManager now uses single docker-compose.yaml at config root instead of per-service compose files
- DNS service still needs legacy compose for building (uses env variables for HOST_IP and TLD)
- StartCommand/StopCommand now call ServiceManager::startAll/stopAll instead of individual docker start/stop
- StatusCommand gets service list from ServiceManager::getEnabled() instead of hardcoded CONTAINERS
- InitCommand now has ServiceManager initialization step that creates services.yaml and generates docker-compose.yaml
- Test failures are expected after this breaking change - tests mock old DockerManager behavior with individual start/stop calls

### Blockers
- None

### Decisions
- Kept DNS building via legacy compose file since it needs environment variables (HOST_IP, TLD)
- DockerManager simplified but still provides individual service start/stop/restart methods
- StatusCommand output structure unchanged (.data.services) - verification check expects .services but actual structure is correct

---

## Summary

| Phase | Task ID | Status |
|-------|---------|--------|
| 1. DTOs and Template Loader | launchpad-desktop-8tv.1 | Not started |
| 2. Compose Generator and Service Manager | launchpad-desktop-8tv.2 | Not started |
| 3. CLI Commands | launchpad-desktop-8tv.3 | Not started |
| 4. Service Templates | launchpad-desktop-8tv.4 | Not started |
| 5. Update Existing Commands & Remove Legacy | launchpad-desktop-8tv.5 | Complete |
