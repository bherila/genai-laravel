# Changelog

## 0.3.0 — Unreleased

- Explicitly adopt signed MCP Laravel Bridge 0.6 (`^0.6.0`) while retaining
  patched MCP PHP SDK 0.9 (`^0.9.0`). This is a public opt-in minor because bridge
  0.6 corrects generated schema output. The released 0.2 line's bridge `^0.5.0`
  constraint is not widened in a patch; applications requiring bridge 0.6 must
  adopt genai-laravel 0.3 too.
- Exercise actual mailbox MCP lifecycle outputs against their advertised
  schemas under the shared local-only validator, including empty tool input
  objects and raw-response callback/delivery metadata privacy.
- Audit each freshly resolved compatibility/MariaDB dependency graph as well
  as the existing locked static job. Full validation remains in CI.
- No provider behavior, mailbox API, callback delivery policy, auth-manager
  rollout, SDK 0.10 support or local-fragment rebasing changes accompany this
  compatibility release.
