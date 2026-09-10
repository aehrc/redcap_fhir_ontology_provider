<?php

namespace AEHRC\FhirOntologyAutocompleteExternalModule;

use PHPUnit\Framework\TestCase;

/**
 * System tests: make real network calls to a public FHIR terminology server
 * already named in this module's README, verifying we can actually fetch and
 * parse real data from it - not just that our own logic is correct in
 * isolation (that's what the unit test suite covers).
 *
 * Deliberately excluded from the default `vendor/bin/phpunit` run (see
 * phpunit.system.xml) and run on a schedule rather than per-PR/push: a public
 * server being slow or briefly down is not this module's bug, and should
 * never block someone's unrelated change.
 *
 * Run this file via `vendor/bin/phpunit -c phpunit.system.xml` - NOT by path
 * (e.g. `vendor/bin/phpunit tests/system/PublicFhirServerTest.php`), which
 * silently falls back to phpunit.xml/tests/bootstrap.php and loads the unit
 * suite's fake HTTP transport instead of the real one. setUp() below checks
 * for this and fails with a clear message instead.
 *
 * Only https://tx.ontoserver.csiro.au/fhir is covered: confirmed working by
 * hand (metadata, ValueSet/$expand for both `isa` and `name`/`codesystem`
 * search types). fhir.loinc.org needs real Basic Auth credentials this suite
 * doesn't have, so that auth code path stays untested against a real secured
 * server. snowstorm-fhir.snomedtools.org is not covered either: confirmed by
 * hand (during the sibling advanced_fhir_ontology_provider pilot) to
 * 302-redirect every request, including one with a realistic browser
 * User-Agent, to a "denied.html?reason=browser" page - it blocks
 * automated/non-browser traffic structurally, not transiently, so it would be
 * a permanently broken check rather than an occasionally-flaky one.
 */
final class PublicFhirServerTest extends TestCase
{
    private const FHIR_API_URL = 'https://tx.ontoserver.csiro.au/fhir';

    protected function setUp(): void
    {
        if (!function_exists(__NAMESPACE__ . '\\usingRealHttpTransport')) {
            $this->fail(
                'RealHttpFunctions.php was not loaded, so sameHostUrl() and curl_*() ' .
                'are still the unit suite\'s fakes rather than the real network. Run ' .
                'this suite via `vendor/bin/phpunit -c phpunit.system.xml`, not by ' .
                'file path with the default config.'
            );
        }

        \OntologyManager::resetForTests();
        $_SESSION = [];
        $_GET = [];
    }

    private function module(): FhirOntologyAutocompleteExternalModule
    {
        $module = new FhirOntologyAutocompleteExternalModule();
        $module->systemSettings['fhir_api_url'] = self::FHIR_API_URL;
        $module->systemSettings['fhir_timeout'] = '15';
        $module->systemSettings['snomed_support'] = true;
        $module->systemSettings['authentication_type'] = 'none';
        return $module;
    }

    public function testSearchOntologyFetchesRealSnomedIsaExpansion(): void
    {
        $module = $this->module();

        // The implicit "all of SNOMED CT" isa valueset, same shape searchOntology()
        // builds for the field-level autocomplete's `url` param.
        $results = $module->searchOntology('http://snomed.info/sct?fhir_vs', 'diabetes', 5);

        $this->assertNotEmpty($results, 'expected at least one real match for "diabetes" from a live SNOMED CT server');
    }

    public function testFindValueSetIsaSearchReturnsRealMatches(): void
    {
        $module = $this->module();

        $results = $module->findValueSet('isa', 'diabetes');

        $this->assertIsArray($results);
        $this->assertArrayNotHasKey('error', $results);
        $this->assertNotEmpty($results);
        $this->assertNotSame('__NMF__', $results[0]['value']);
    }

    public function testFindValueSetCodesystemSearchReturnsRealMatches(): void
    {
        $module = $this->module();

        $results = $module->findValueSet('codesystem', 'SNOMED');

        $this->assertIsArray($results);
        $this->assertArrayNotHasKey('error', $results);
        $this->assertNotEmpty($results);
    }

    public function testGetValueSetInfoFetchesRealExpansion(): void
    {
        $module = $this->module();

        $info = $module->getValueSetInfo('http://snomed.info/sct?fhir_vs=isa/73211009');

        $this->assertNotFalse($info, 'expected a real response body, not a transport failure');
        $decoded = json_decode($info, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ValueSet', $decoded['resourceType']);
    }
}
