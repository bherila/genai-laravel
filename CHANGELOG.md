# Changelog

## 0.3.3 — 2026-11-16

- `AnthropicClient` serves `ToolChoice::any()` and `ToolChoice::tool()` on models
  that reject a forced tool choice (Claude Sonnet 5.5, Claude Opus 5.5, Fable and
  Mythos 5.1+), which previously failed every such request with a 400. The
  request goes out as `auto`, with a system-prompt line naming the tool. A
  plain-text reply is asked once more in the same conversation, and both turns'
  usage is summed into the response. Models not on the list are learned from
  their first rejection. Models that accept forcing keep the forced choice and
  an unchanged request.
- New `AnthropicClient::rejectsForcedToolChoice()`.
- `PricingBook` supports long-prompt tiers. A `long_prompt` row (`above_tokens`
  plus the four prices) bills the whole request at the higher rate once input,
  cache-read and cache-creation tokens together pass the threshold, as Claude
  Haiku 5.5 does above 100,000 tokens. Existing price rows are unchanged.

## 0.3.2 — 2026-11-15

- Add `SpreadsheetToText::convertWithReport()` and its `SpreadsheetConversion`
  result so callers can check `isComplete()` instead of parsing the text marker.
  The report includes row, cell, output and time truncation, including values a
  bounded reader dropped while loading sparse XLSX, XLS or ODS workbooks. The
  existing `convert()` text/marker API remains compatible.
- Keep the XLSX completeness check's second read to the archive parts the initial
  load actually inflated, retaining the conversion deadline and archive bounds.
  Uncertain or partial extraction stays incomplete rather than being claimed as
  a whole-workbook result.
- Consumers requiring complete extraction, including PII scans, should hold an
  incomplete report. The 0.3 line continues to require MCP Laravel Bridge ^0.6
  and SDK ^0.9; consumers on 0.2 must explicitly adopt that compatibility change.

## 0.3.1 — Unreleased

- The MCP server's initialize instructions describe the mailbox tools instead of
  telling the client to claim requests until the queue is empty. A client reads
  them in every session whatever its user asked, and one that screens server
  instructions discarded the work loop as standing orders. The drain workflow
  stays in the README's starter prompt, for the user to give. No tool, schema or
  mailbox behavior changes.

## 0.3.0 — Unreleased

- Explicitly adopt signed MCP Laravel Bridge 0.6 (`^0.6.0`) while retaining
  patched MCP PHP SDK 0.9 (`^0.9.0`). This is a public opt-in minor because bridge
  0.6 corrects generated schema output. The released 0.2 line's bridge `^0.5.0`
  constraint is not widened in a patch; applications requiring bridge 0.6 must
  adopt genai-laravel 0.3 too.
- Correct claim/renewal tool-schema empty `properties` maps to serialize as `{}`
  after database array decoding, without changing stored payloads or idempotency
  hashes.
- Exercise actual mailbox MCP lifecycle outputs against their advertised
  schemas under the shared local-only validator, including empty tool input
  objects and raw-response callback/delivery metadata privacy.
- Audit each freshly resolved compatibility/MariaDB dependency graph as well
  as the existing locked static job. Full validation remains in CI.
- No provider behavior, mailbox API, callback delivery policy, auth-manager
  rollout, SDK 0.10 support or local-fragment rebasing changes accompany this
  compatibility release.
