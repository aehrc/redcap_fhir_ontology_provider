# Changelog

## [1.0.0](https://github.com/aehrc/redcap_fhir_ontology_provider/compare/v0.5.1...v1.0.0) (2026-09-10)


### ⚠ BREAKING CHANGES

* `getOnlineDesignerSection()`'s DOM structure and element IDs changed. No stored data format change.
* raises php-version-min from 5.4.0 to 8.0.0. Sites running PHP older than 8.0 will no longer be able to install or enable this module version.

### Features

* add @FHIR-ONTOLOGY-OPTIONS action tag for return-all, code-template, and priority-codes ([#25](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/25)) ([f78d1e7](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/f78d1e733b8e903879f89df6fae3d32573f343cf))
* replace inline Online Designer ValueSet picker with a single popup dialog ([#22](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/22)) ([0fab806](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/0fab80628a1fa9fae65d20cd954635c24a96dedc))
* upgrade to EM framework 16, raising the PHP floor to 8.0.0 ([#19](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/19)) ([1f7435a](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/1f7435a1ac395d49004aa4a7c9bee4e2f7615ba6))


### Bug Fixes

* bound total FHIR request time, not just the connect phase ([#26](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/26)) ([676b00d](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/676b00d0fcfcf8d9be960991afb8971b4df3775b))
* cap findValueSet() results at 20 regardless of server-reported count ([#23](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/23)) ([0cba947](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/0cba94763901a0fce4978dee707c76022b0ec2d5))
* repair @HIDECHOICE's broken field-annotation lookup, add @FHIR-ONTOLOGY-HIDECHOICE ([#24](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/24)) ([16dadea](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/16dadea3beb60c366f23ec5b4ca0d352a8acad60))

## [0.5.1](https://github.com/aehrc/redcap_fhir_ontology_provider/compare/v0.5.0...v0.5.1) (2026-09-06)


### Bug Fixes

* add conventional-commit prefix to dependabot PR titles ([#11](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/11)) ([3aaa1e5](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/3aaa1e59a93dc3c9774c85ae52cf01a66c390fcd))
* correct overstated claims, constrain outbound requests, drop dead cache ([#5](https://github.com/aehrc/redcap_fhir_ontology_provider/issues/5)) ([09a5bdd](https://github.com/aehrc/redcap_fhir_ontology_provider/commit/09a5bdd1bbd04f8800cc955929b8f4cc10a3a996))
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
