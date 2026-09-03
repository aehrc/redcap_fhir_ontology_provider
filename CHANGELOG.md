# Changelog

## [0.5.1](https://github.com/aehrc/redcap_fhir_ontology_provider/compare/v0.5.0...v0.5.1) (2026-09-03)


### Bug Fixes

* add conventional-commit prefix to dependabot PR titles ([#11](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/11)) ([3aaa1e5](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/3aaa1e59a93dc3c9774c85ae52cf01a66c390fcd))
* correct typos in README ([#17](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/17)) ([fab953f](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/fab953fddb89f702d28acdfcf8472dbb85c06794))
* seed release-please's version baseline correctly ([#13](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/13)) ([8b4982a](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/8b4982a0796515ae2a3818b2407ed9f7a98fa5fc))

## [0.5] - 2024-02-23
- Add support for a LOINC FHIR server
- Change the stored value format to `code|system` (drop `display`), avoiding failures when long display text pushed values past REDCap's field length limit

## [0.4] - 2022-09-06
- Add basic `@HIDECHOICE` support (hide specific codes from a field's autocomplete results)
- Add a `User-Agent` header, since some FHIR servers (e.g. SNOMED's) reject requests without one
- Work around an `http_post` bug where a custom header combined with a custom content type caused the content type to be silently overwritten
- Recommend the `tx.ontoserver.csiro.au` server over the R4 server in documentation

## [0.3] - 2021-11-19
- Add support for an authenticated FHIR terminology server
- Add a web service so the Online Designer talks to REDCap, not directly to the FHIR server - protects authentication settings and allows the module to work behind a proxy

## [0.2.3] - 2020-02-03
- Fix a null `$project_id` handling issue in a hook

## [0.2.2] - 2019-08-05
- Fix `redcap_csrf_token` being incorrectly added to AJAX JSON POST requests

## [0.2.1] - 2019-01-29
- Fix a hook function signature incompatibility when `null` is passed

## [0.2] - 2019-01-25
- Add a tooltip mechanism for selected values
- Add an option to return a predefined value instead of an empty result set
- Add an indication when no search results are found

## [0.1] - 2018-11-23
- Initial release
