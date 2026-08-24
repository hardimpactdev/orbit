# macOS bundle version proof

- Candidate: `3476d98b7820b9978affb4089f027f2ef4ada1e6`
- Venue: `host-macos`
- Host: Mini (`Darwin arm64`)
- Command: `bin/orbit-build-native-release-assets --output=.orbit/native-release-proof`
- Expected: `CFBundleShortVersionString` and `CFBundleVersion` both equal `0.1.196`.
- Observed short version: `0.1.196`
- Observed build version: `0.1.196`
- Result: passed
- Desktop archive SHA-256: `d54624e6e2e8446b47ca51ef07c3f73cff27cdac54c6103d14eb747163705590`
- Agent SHA-256: `ceb49b2287814f197c539b48f6e4625bb5039c8fe26bb4eb0dd4d4ba60ff71f0`
- DMG SHA-256: `ac854405a3777fc5e8316bd2268ab469795a886cff0dc54ebe523a444c6f1cee`
