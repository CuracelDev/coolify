# Deployment Operator Playbook

## What it is

Deployment Operator is an opt-in post-deploy safety loop for supported applications.

After a deployment, it can:

1. verify the generated Coolify URL,
2. detect a small set of known failure patterns,
3. apply one safe remediation when supported,
4. retry the deployment once,
5. record the result on the deployment row.

It is intentionally narrow and rule-based.

## Supported now

- Nixpacks applications
- Nixpacks static apps (`is_static` + `publish_directory`)
- Dockerfile applications in constrained mode
- Standalone static build-pack applications in verify-only mode
- Railpack applications in verify-only mode
- Preview deployments in record-only / verify-only mode
- Constrained Docker Compose applications in verify-only mode
- Multi-destination applications in primary-canary verify-only mode

## Not supported yet

- Automatic custom-domain fixes
- Docker Compose remediation / retry
- Railpack remediation / retry
- Standalone static remediation / retry
- Preview remediation / retry
- Automatic rollout fanout for multi-destination apps
- Verification of non-primary destination rows in multi-destination apps

## What it verifies

### Primary verification

The source of truth is the **generated Coolify default URL** for the app.

- If the default URL responds successfully, the deployment is considered healthy.
- If it fails and the failure matches a known safe rule, the operator may remediate and retry once.

### Secondary verification

If the default URL passes, custom domains are checked separately in **observe-only** mode.

- Custom-domain results do **not** override deployment success.
- Custom-domain results are recorded as `passed`, `pending`, or `failed`.

### Verify-only slices

These paths currently use verification only and do not remediate automatically:

- standalone static build-pack apps
- Railpack apps
- preview deployments
- constrained Docker Compose apps
- multi-destination canary rows

## Current remediation rules

### Nixpacks / Tailwind / Node mismatch

If a Nixpacks deployment fails with a known Node / native-binding signature (for example Tailwind oxide binding issues), the operator can:

- set `NIXPACKS_NODE_VERSION=22`
- queue one forced rebuild retry

No remediation is currently performed for:

- standalone static build-pack apps
- Railpack apps
- preview deployments
- Docker Compose apps
- multi-destination canary rows

## Attempt limits

- Maximum total attempts: **2**
- Maximum automatic remediation attempts: **1**

If the second attempt fails, the operator stops and records the outcome.

## Safety rules

- The operator only runs when explicitly enabled on the application.
- It only acts on the **latest** deployment attempt for the app.
- It will not mutate stale or superseded deployments.
- Retries use `force_rebuild=true`.
- It does not rewrite final deployment status directly; it stores operator metadata separately.

## UI location

In the application UI:

- **Application → Advanced → Deployment Operator (Beta)**

The section shows:

- whether the app is currently supported,
- the generated Coolify URL requirement,
- current scope and limits,
- whether custom domains are observe-only.

## How to use it

1. Open the application.
2. Go to **Advanced**.
3. In **Deployment Operator (Beta)**, confirm the app is supported.
4. Enable operator mode.
5. Deploy as normal.
6. Review deployment history for:
   - operator verification result
   - any remediation rule used
   - retry lineage if a retry was triggered

## How to read results

Typical operator states on a deployment row:

- `recorded` — verification passed and was stored
- `verification_failed` — default-route verification failed
- `retry_queued` — operator applied a supported remediation and queued one retry
- `max_attempts_reached` — operator stopped after reaching the hard cap
- `superseded` — a newer deployment existed, so the operator skipped action
- `ineligible` — the app or deployment type is out of scope

## Recommended rollout policy

Use this order:

1. enable on selected staging apps first,
2. verify at least one healthy path and one remediated path,
3. enable on a small set of production apps,
4. expand only after reviewing deployment history for false positives.

## Current limitations

- Custom-domain checks are observe-only.
- Compose, Railpack, static, preview, and multi-destination support are still intentionally narrow and mostly verify-only.
- The operator is not a generic self-healing engine.
- New remediation rules should only be added when they are deterministic and low-risk.
