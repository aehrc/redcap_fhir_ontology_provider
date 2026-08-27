# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A REDCap **External Module** that lets a FHIR terminology server act as a REDCap *Ontology Provider* — an alternative to the built-in BioPortal lookup. An ontology provider takes a search term and returns code + display pairs. This one does it via the FHIR `ValueSet/$expand` operation.

This is a fork of `aehrc/redcap_fhir_ontology_provider`, continuing its release line (upstream's last release was `v0.5`; this repo adds `v0.5.1` / `v0.5.2`). Upstream has been dormant since 2024-02.

## No build, no tests, no dependencies

There is no composer, npm, Makefile, CI, or test framework. Do not go looking for a test suite — its absence is expected, not a broken setup. The only automated verification available is:

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php -l FindValueSetService.php
python3 -c "import json;json.load(open('config.json'));print('valid')"
```

Everything else requires a running REDCap instance with this module installed. Treat runtime behaviour as unverifiable locally and say so rather than asserting it works.

## Hard constraints

- **PHP 5.4 floor.** `config.json` declares `"php-version-min": "5.4.0"`. The null-coalescing operator `??` (PHP 7+) must not be used. Use `isset($x) ? $x : $default`.
- **`framework-version` is `1`.** Do not bump it to reach a newer External Modules API — that is a breaking change needing its own decision.
- **Designer JavaScript lives inside a PHP heredoc.** `getOnlineDesignerSection()` returns a `<<<EOD ... EOD;` block containing the entire Online Designer UI. PHP interpolates `$name` inside heredocs, so `$('#id')` and `$('<tr>')` are safe (a `(` cannot begin a PHP identifier) but any new JS variable written as `$foo` would be silently eaten by PHP. Declare JS locals as `var foo`. `php -l` will not catch an interpolation mistake — re-read the diff.

## Architecture

**Three source files, one manifest.** The module file is large (~1100 lines) and mixes concerns — provider logic, designer markup, the HTTP layer, and auth all live in it.

- `config.json` — manifest: settings schema, hook permissions, compatibility floors.
- `FhirOntologyAutocompleteExternalModule.php` — everything else.
- `FindValueSetService.php` — thin AJAX entry point for the Online Designer. Validates params and dispatches to the module; has no logic of its own. **Requires authentication** — it is deliberately *not* in `no-auth-pages`.

### How the provider gets registered

REDCap's extension point is `\OntologyManager`. The module registers itself in its **constructor**, not in a hook:

```php
$manager = \OntologyManager::getOntologyManager();
$manager->addProvider($this);
```

`redcap_every_page_before_render()` is intentionally empty — its only job is to force REDCap to instantiate the module on every page so the constructor runs. Don't "clean up" that empty hook; deleting it unregisters the provider everywhere.

The `\OntologyProvider` contract is implemented by: `getProviderName()`, `getServicePrefix()` (returns `FHIR`), `searchOntology()`, `getOnlineDesignerSection()`, `getLabelForValue()`.

### Two request paths

1. **Data entry / survey autocomplete** → REDCap calls `searchOntology($valueset_id, $search_term, $result_limit)` → `ValueSet/$expand`. Fires **once per keystroke**, so anything on this path is performance-critical.
2. **Online Designer** → browser calls `FindValueSetService.php` → `findValueSet()` (search for a valueset) or `getValueSetInfo()` (preview one). Requests go browser → REDCap → FHIR server, never browser → FHIR server directly; this keeps credentials server-side and works behind a proxy.

### Stored value format

Selected codes are stored as `code|system` (not `code|display|system`) — display strings pushed the value past REDCap's 100-character limit and broke label lookup. `getLabelForValue()` returns the raw value, so the code is what users see.

### Resilience layer

Two mechanisms protect REDCap from a slow or unavailable terminology server. Both matter because outbound calls are synchronous and each one holds a PHP-FPM worker; without them, terminology-server downtime can take the whole REDCap instance offline, including projects that never use this module.

- **Timeout** — the `fhir_timeout` setting (default 10s, `DEFAULT_TIMEOUT`) is threaded through all four outbound paths: `http_get` curl, `http_get` stream fallback, both `http_post` curl calls, and the `http_post` stream fallback. Signatures are `http_get($url, $timeout, $basic_auth, $headers, $cookies)` and `http_post($url, $params, $timeout, $content_type, $basic_auth, $headers)`.
- **Circuit breaker** — 3 consecutive failures open it for 60s, then one probe is admitted (`isCircuitOpen()` re-arms the window on its way out so concurrent callers keep failing fast). State lives in **undeclared** system settings `fhir_breaker_failures` / `fhir_breaker_open_until`; the framework accepts arbitrary keys and these are internal, so do not add them to `config.json`.

Two design boundaries here are deliberate:

- The breaker wraps the **three FHIR entry points** (`searchOntology`, `findValueSet`, `getValueSetInfo`), *not* `httpGet`/`httpPost`. Putting it in the shared helpers would also trap OAuth2 token negotiation, which targets a different host.
- `recordFhirFailureIfSlow()` only counts a failure when the call consumed ≥80% of the timeout. A fast rejection (e.g. a user's malformed ECL ValueSet URL returning 4xx) must not trip a system-wide breaker.
- `recordFhirSuccess()` writes only when there is state to clear, so a healthy server costs zero DB writes on the per-keystroke path.

**Degradation must not fabricate data.** When a lookup fails, `searchOntology` must not emit the configured "No Results Found" entry — that entry is selectable and saveable into a record, so an outage would store a false negative. The `$fhirFailed` flag gates this. `getValueSetInfo()` returns `false` on failure, which the service layer turns into a 502 carrying an `OperationOutcome` — the shape the designer's JS error handler already parses.

### Auth

`getAuthHeader()` supports none / Basic / OAuth2 client credentials. OAuth2 tokens cache in `$_SESSION` (per-user, not server-wide — a known limitation deferred to a future migration). `expires_in` is **seconds** per RFC 6749; the cache renews early by `min(60, floor(lifetime/2))`.

Credential settings use `"type": "password"`. That masks the config UI only — the External Modules framework still stores such values as **plain text**, so credentials remain readable in `redcap_external_module_settings` and in database backups. Do not describe this as encryption.

### `@HIDECHOICE`

`getHideChoice()` parses `@HIDECHOICE='code1,code2'` from a field's annotation and filters those codes out of results. It runs on the per-keystroke path, so how the annotation is *fetched* matters:

- `global $Proj` must be declared — without it `$Proj` is null inside the function and every call falls through to a full `REDCap::getDataDictionary()`.
- The in-memory fast path is gated on `isset($Proj->metadata[$field])`, **not** on `field_annotation` existing. `field_annotation` is NULL for un-annotated fields (the common case), so gating on it sends most fields back to a dictionary load.
- `$Proj` is only trusted when it matches `$_GET['pid']`.

## Deployment

REDCap discovers modules by directory name under `<redcap-root>/modules/`, e.g. `fhir-ontology-provider_v0.5.1/`. The version lives in the **directory name**, not in git or `config.json`. Versions coexist and are switched in Control Center → External Modules; that switch is the rollback mechanism. REDCap has no connection to git — merging or pushing changes nothing on a server until files are copied across.

`v0.5.1` and `v0.5.2` differ only in whether `cc_client_secret` / `basic_user_password` are `"text"` or `"password"`. They ship separately because the credential value does **not** migrate when a setting's type changes: after enabling the masked version the password must be re-entered or lookups fail silently.

## Prior work

`docs/superpowers/specs/` and `docs/superpowers/plans/` hold the design and implementation plan for the 2026-08 security and performance remediation. The spec records what was deliberately *not* fixed and why, plus limitations that could not be verified without REDCap source. Read it before re-litigating a decision.

`README.md` is the admin-facing reference — settings, per-version changelog, deployment. Update it when adding or changing a setting.

## Importable config detected

A Gemini CLI config exists at `~/.gemini/settings.json`. Reply `/import` to scan and list what's importable (MCP servers, slash commands, subagents, skills, instructions), then `/import --yes=<digest>` (the scan output names the digest) to apply user-level items. If `/import` isn't available on this surface, run `claude import` from a terminal instead.
