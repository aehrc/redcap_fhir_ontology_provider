# Changelog

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
