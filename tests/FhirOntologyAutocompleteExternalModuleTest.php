<?php

namespace AEHRC\FhirOntologyAutocompleteExternalModule;

use PHPUnit\Framework\TestCase;

final class FhirOntologyAutocompleteExternalModuleTest extends TestCase
{
    private FhirOntologyAutocompleteExternalModule $module;

    protected function setUp(): void
    {
        // OntologyManager is a process-wide singleton in the real framework too;
        // reset before constructing so each test's module doesn't pile onto every
        // prior test's registration for the lifetime of the PHPUnit run.
        \OntologyManager::resetForTests();
        \REDCap::resetForTests();
        $this->module = new FhirOntologyAutocompleteExternalModule();
        FakeHttpTransport::reset();
        $_SESSION = [];
        $_GET = [];
        unset($GLOBALS['Proj']);
    }

    // --- getFhirTimeout() ---------------------------------------------------

    public function testFhirTimeoutDefaultsWhenSettingBlank(): void
    {
        $this->assertSame(FhirRequestPolicy::DEFAULT_TIMEOUT, $this->module->getFhirTimeout());
    }

    public function testFhirTimeoutUsesConfiguredValue(): void
    {
        $this->module->systemSettings['fhir_timeout'] = '25';
        $this->assertSame(25, $this->module->getFhirTimeout());
    }

    // --- circuit breaker -----------------------------------------------------

    public function testCircuitClosedByDefault(): void
    {
        $this->assertFalse($this->module->isCircuitOpen());
    }

    public function testCircuitOpensAfterThreeFailures(): void
    {
        $this->module->recordFhirFailure();
        $this->module->recordFhirFailure();
        $this->assertFalse($this->module->isCircuitOpen(), 'two failures must not open the breaker');
        $this->module->recordFhirFailure();
        $this->assertTrue($this->module->isCircuitOpen(), 'three failures must open the breaker');
    }

    public function testRecordFhirFailureIfSlowIgnoresFastFailures(): void
    {
        $this->module->systemSettings['fhir_timeout'] = '10';
        // 0.2s against a 10s timeout is nowhere near the 80% slow-call threshold.
        $this->module->recordFhirFailureIfSlow(0.2);
        $this->module->recordFhirFailureIfSlow(0.2);
        $this->module->recordFhirFailureIfSlow(0.2);
        $this->assertFalse($this->module->isCircuitOpen(), 'fast (e.g. 4xx) failures must never trip the breaker');
    }

    public function testRecordFhirFailureIfSlowCountsSlowFailures(): void
    {
        $this->module->systemSettings['fhir_timeout'] = '10';
        $this->module->recordFhirFailureIfSlow(9.0);
        $this->module->recordFhirFailureIfSlow(9.0);
        $this->module->recordFhirFailureIfSlow(9.0);
        $this->assertTrue($this->module->isCircuitOpen());
    }

    public function testRecordFhirSuccessClearsFailureCount(): void
    {
        $this->module->recordFhirFailure();
        $this->module->recordFhirFailure();
        $this->module->recordFhirSuccess();
        $this->module->recordFhirFailure();
        $this->module->recordFhirFailure();
        $this->assertFalse($this->module->isCircuitOpen(), 'a success must reset the count, not just add to it');
    }

    public function testSearchOntologyFailsFastWithoutCallingHttpWhenBreakerOpen(): void
    {
        $this->module->recordFhirFailure();
        $this->module->recordFhirFailure();
        $this->module->recordFhirFailure();
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        $this->assertSame([], $results);
        $this->assertCount(0, FakeHttpTransport::$calls, 'an open breaker must fail fast without dialing out');
    }

    // --- getClientCredentialsToken() ---
    // Regression coverage for real historical bugs fixed in ontology-provider-security-audit:
    // the expires_in seconds-vs-ms bug, and the PHP 8 TypeError on a non-JSON/failed response.

    public function testTokenIsFetchedAndCachedWithExpiryInSecondsNotMilliseconds(): void
    {
        FakeHttpTransport::$response = json_encode(['access_token' => 'tok-1', 'expires_in' => 3600]);
        $this->module->systemSettings['authentication_type'] = 'cc';
        $this->module->systemSettings['cc_token_endpoint'] = 'https://example.test/token';
        $this->module->systemSettings['cc_client_id'] = 'id';
        $this->module->systemSettings['cc_client_secret'] = 'secret';

        $before = time();
        $header = $this->module->getAuthHeader();
        $after = time();

        $this->assertSame('Authorization: Bearer tok-1', $header);
        $this->assertCount(1, FakeHttpTransport::$calls, 'should fetch a token on first call');

        // Regression: expires_in (seconds) was previously multiplied by 1000,
        // caching a 3600s token for ~41 days instead of ~1 hour. The real fix
        // also renews slightly early (margin = min(60, floor(lifetime/2))).
        $this->assertGreaterThanOrEqual($before + 3600 - 60, $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES']);
        $this->assertLessThan($after + 3600, $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES']);
    }

    public function testCachedUnexpiredTokenIsReusedWithoutRefetching(): void
    {
        // getClientCredentialsToken()'s own httpPost() call has no way to pass a
        // $baseOverride, so it's only allowed through when it exactly matches the
        // configured cc_token_endpoint (see httpPost()'s SSRF containment check) -
        // without this, the call is silently refused before ever reaching the
        // fake transport, and the assertions below would pass for the wrong
        // reason (a rejected call also returns false-ish/no token).
        $this->module->systemSettings['cc_token_endpoint'] = 'https://example.test/token';
        FakeHttpTransport::$response = json_encode(['access_token' => 'tok-1', 'expires_in' => 3600]);
        $token1 = $this->module->getClientCredentialsToken('https://example.test/token', 'id', 'secret');
        $this->assertCount(1, FakeHttpTransport::$calls);

        $token2 = $this->module->getClientCredentialsToken('https://example.test/token', 'id', 'secret');

        $this->assertSame('tok-1', $token2);
        $this->assertSame($token1, $token2);
        $this->assertCount(1, FakeHttpTransport::$calls, 'a cached, unexpired token must not trigger a second fetch');
    }

    public function testMalformedTokenResponseDoesNotCrashAndReturnsFalse(): void
    {
        // Not valid JSON - decodes to null, and array_key_exists(null) is a
        // fatal TypeError on PHP 8 if the is_array() guard regresses.
        $this->module->systemSettings['cc_token_endpoint'] = 'https://example.test/token';
        FakeHttpTransport::$response = 'not json';

        $token = $this->module->getClientCredentialsToken('https://example.test/token', 'id', 'secret');

        $this->assertFalse($token);
        $this->assertCount(1, FakeHttpTransport::$calls, 'must exercise the fake transport, not be rejected before reaching it');
    }

    public function testFailedTokenHttpCallDoesNotCrashAndReturnsFalse(): void
    {
        $this->module->systemSettings['cc_token_endpoint'] = 'https://example.test/token';
        FakeHttpTransport::$response = false;

        $token = $this->module->getClientCredentialsToken('https://example.test/token', 'id', 'secret');

        $this->assertFalse($token);
        $this->assertCount(1, FakeHttpTransport::$calls, 'must exercise the fake transport, not be rejected before reaching it');
    }

    public function testGetAuthHeaderReturnsFalseWhenTokenFetchFails(): void
    {
        FakeHttpTransport::$response = false;
        $this->module->systemSettings['authentication_type'] = 'cc';
        $this->module->systemSettings['cc_token_endpoint'] = 'https://example.test/token';

        // Regression: this used to return a malformed "Authorization: Bearer "
        // header (with token === false stringified to '') instead of false.
        $this->assertFalse($this->module->getAuthHeader());
    }

    // --- searchOntology() ---------------------------------------------------

    public function testSearchOntologySkipsEntriesWithNoCodeAndDefaultsMissingDisplayToCode(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['system' => 'http://example.test', 'display' => 'Has Everything'],
                    ['code' => 'C1', 'system' => 'http://example.test'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        // The entry with no 'code' at all must be skipped, not templated in as "|system".
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('C1|http://example.test', $results);
        // Missing 'display' falls back to the code, per the null-safety fix.
        $this->assertSame('C1', $results['C1|http://example.test']);
    }

    public function testSearchOntologyThreadsConfiguredTimeoutIntoTheHttpCall(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['fhir_timeout'] = '7';
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => []]]);

        $this->module->searchOntology('http://example.test/vs', 'term', 20);

        $this->assertCount(1, FakeHttpTransport::$calls);
        $this->assertSame(7, FakeHttpTransport::$calls[0]['timeout']);
    }

    // --- true end-to-end request timeout ---
    // REDCap core's http_get()/http_post() only ever set curl's *connect*
    // timeout (confirmed by reading Config/init_functions.php) - a server that
    // accepts the connection and then stalls could still hold a web server
    // process open indefinitely. This module now makes its own curl calls
    // instead of delegating to core's, specifically to also set CURLOPT_TIMEOUT.

    public function testHttpGetSetsBothConnectAndTotalTimeout(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['fhir_timeout'] = '7';
        FakeHttpTransport::$response = 'ok';

        $this->module->httpGet('https://ts.example.test/fhir/metadata', ['User-Agent: Redcap']);

        $this->assertCount(1, FakeHttpTransport::$calls);
        $this->assertSame(7, FakeHttpTransport::$calls[0]['timeout'], 'connect timeout');
        $this->assertSame(7, FakeHttpTransport::$calls[0]['total_timeout'], 'the new end-to-end timeout');
    }

    public function testHttpPostSetsBothConnectAndTotalTimeout(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['fhir_timeout'] = '7';
        FakeHttpTransport::$response = 'ok';

        $this->module->httpPost(
            'https://ts.example.test/fhir/ValueSet/$expand',
            '{}',
            'application/json',
            ['User-Agent: Redcap'],
            'https://ts.example.test/fhir'
        );

        $this->assertCount(1, FakeHttpTransport::$calls);
        $this->assertSame(7, FakeHttpTransport::$calls[0]['timeout'], 'connect timeout');
        $this->assertSame(7, FakeHttpTransport::$calls[0]['total_timeout'], 'the new end-to-end timeout');
    }

    public function testHttpPostSendsContentTypeHeaderAlongsideCustomHeaders(): void
    {
        // Regression: REDCap core's own http_post() sets CURLOPT_HTTPHEADER for
        // the content-type header, then - if custom headers are also present -
        // overwrites it entirely with just those (curl_setopt() replaces, not
        // merges), silently dropping the content-type header. This module's
        // curlPostWithTotalTimeout() builds the header list once instead.
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = 'ok';

        $this->module->httpPost(
            'https://ts.example.test/fhir/ValueSet/$expand',
            '{"resourceType":"Parameters"}',
            'application/json',
            ['User-Agent: Redcap'],
            'https://ts.example.test/fhir'
        );

        $sentHeaders = FakeHttpTransport::$calls[0]['headers'];
        $this->assertContains('Content-Type: application/json', $sentHeaders);
        $this->assertContains('User-Agent: Redcap', $sentHeaders);
    }

    public function testSearchOntologyReturnsConfiguredNoResultFallbackOnGenuineEmptyResult(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['return_no_result'] = true;
        $this->module->systemSettings['no_result_label'] = 'No Results Found';
        $this->module->systemSettings['no_result_code'] = '_NRF_';
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => []]]);

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        $this->assertSame(['_NRF_' => 'No Results Found'], $results);
    }

    public function testSearchOntologyDoesNotFabricateNoResultFallbackWhenTheHttpCallFailed(): void
    {
        // Distinguishes a real "the server was unreachable" failure from a
        // genuine empty result - fabricating a storable "No Results Found"
        // entry on transport failure would corrupt data on save.
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['return_no_result'] = true;
        $this->module->systemSettings['no_result_label'] = 'No Results Found';
        $this->module->systemSettings['no_result_code'] = '_NRF_';
        FakeHttpTransport::$response = false;

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        $this->assertSame([], $results);
    }

    public function testSearchOntologyFiltersHideChoiceCodes(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'some_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['some_field'] = ['misc' => "@HIDECHOICE='C1'"];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'Hidden'],
                    ['code' => 'C2', 'system' => 'sys', 'display' => 'Shown'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        $this->assertArrayNotHasKey('C1|sys', $results);
        $this->assertArrayHasKey('C2|sys', $results);
    }

    // --- searchOntology() @FHIR-ONTOLOGY-OPTIONS ---------------------------------
    // Existing searchOntology() tests above (e.g.
    // testSearchOntologySkipsEntriesWithNoCodeAndDefaultsMissingDisplayToCode)
    // run with no @FHIR-ONTOLOGY-OPTIONS tag and already assert the exact
    // "code|system" stored format - they double as the regression guard that
    // the code-template default is byte-identical to the old hardcoded
    // $code . "|" . $system.

    public function testSearchOntologyReturnAllOmitsFilterAndRanksMatchesFirst(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'chips_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['chips_field'] = ['misc' => "@FHIR-ONTOLOGY-OPTIONS='return-all'"];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'Never'],
                    ['code' => 'C2', 'system' => 'sys', 'display' => 'Rarely'],
                    ['code' => 'C3', 'system' => 'sys', 'display' => 'Weekly'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'week', 20);

        $this->assertStringNotContainsString('filter=', FakeHttpTransport::$calls[0]['url']);
        // 'week' only matches "Weekly" - it must sort first despite not being
        // the first entry the server returned, with the rest keeping their
        // original relative order after it.
        $this->assertSame(['C3|sys', 'C1|sys', 'C2|sys'], array_keys($results));
    }

    public function testSearchOntologyReturnAllWithEmptySearchTermKeepsServerOrder(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'chips_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['chips_field'] = ['misc' => "@FHIR-ONTOLOGY-OPTIONS='return-all'"];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'Never'],
                    ['code' => 'C2', 'system' => 'sys', 'display' => 'Rarely'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', '', 20);

        $this->assertSame(['C1|sys', 'C2|sys'], array_keys($results));
    }

    public function testSearchOntologyCodeTemplateOverridesStoredFormat(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'bare_code_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['bare_code_field'] = ['misc' => '@FHIR-ONTOLOGY-OPTIONS=\'code-template=${CODE}\''];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'Never'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'never', 20);

        // Bare code, no system suffix - and the displayed label is unaffected.
        $this->assertSame(['C1' => 'Never'], $results);
    }

    public function testSearchOntologyPriorityCodesRequestsExtraHeadroomAndSortsPriorityFirst(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'priority_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['priority_field'] = ['misc' => "@FHIR-ONTOLOGY-OPTIONS='priority-codes=C3,C1'"];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'One'],
                    ['code' => 'C2', 'system' => 'sys', 'display' => 'Two'],
                    ['code' => 'C3', 'system' => 'sys', 'display' => 'Three'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        $this->assertStringContainsString('count=22', FakeHttpTransport::$calls[0]['url'], '20 + 2 priority codes');
        // Listed order is C3 then C1 - priority entries sort in that order, non-priority C2 last.
        $this->assertSame(['C3|sys', 'C1|sys', 'C2|sys'], array_keys($results));
    }

    public function testSearchOntologyReturnAllAndPriorityCodesCombinedPriorityWins(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'combo_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['combo_field'] = ['misc' => "@FHIR-ONTOLOGY-OPTIONS='return-all;priority-codes=C2'"];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'Weekly'],
                    ['code' => 'C2', 'system' => 'sys', 'display' => 'Monthly'],
                ],
            ],
        ]);

        // Search term matches C1's display ("Weekly") but C2 is the priority code.
        $results = $this->module->searchOntology('http://example.test/vs', 'week', 20);

        // Priority (C2) must still sort first, even though it doesn't match the term.
        $this->assertSame(['C2|sys', 'C1|sys'], array_keys($results));
    }

    public function testSearchOntologyHideChoiceExcludesEvenWhenReturnAllSet(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'hide_and_options_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['hide_and_options_field'] = [
            'misc' => "@HIDECHOICE='C1' @FHIR-ONTOLOGY-OPTIONS='return-all'",
        ];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'Hidden'],
                    ['code' => 'C2', 'system' => 'sys', 'display' => 'Shown'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'nomatch', 20);

        $this->assertArrayNotHasKey('C1|sys', $results);
        $this->assertArrayHasKey('C2|sys', $results);
    }

    public function testSearchOntologyFetchesFieldAnnotationOnlyOnceEvenOnSlowPath(): void
    {
        // Regression: searchOntology() needs the field's annotation for both
        // getSearchOptions() and getHideChoice() - it must fetch it once itself
        // and pass it to both, not let each independently call
        // getFieldAnnotation() again. On the fast (in-memory $Proj) path that's
        // just a duplicated array lookup, but on this slow path (project_id
        // mismatch) getFieldAnnotation() falls back to a full
        // REDCap::getDataDictionary() reload - calling it twice per search
        // would double that cost on every autocomplete keystroke.
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'my_field';
        $_GET['pid'] = '99';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17'; // a different project than requested
        \REDCap::$dataDictionary = [
            'my_field' => ['field_annotation' => "@HIDECHOICE='C1' @FHIR-ONTOLOGY-OPTIONS='return-all'"],
        ];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'Hidden'],
                    ['code' => 'C2', 'system' => 'sys', 'display' => 'Shown'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'nomatch', 20);

        $this->assertArrayNotHasKey('C1|sys', $results, 'sanity check: @HIDECHOICE and @FHIR-ONTOLOGY-OPTIONS were both actually read');
        $this->assertArrayHasKey('C2|sys', $results);
        $this->assertSame(1, \REDCap::$getDataDictionaryCallCount);
    }

    public function testSearchOntologyHideChoiceExcludesEvenWhenAlsoListedAsPriority(): void
    {
        // A code that is both hidden and priority-listed is excluded entirely -
        // the hide-choice check runs before an entry is even a ranking
        // candidate, so priority-codes never gets a chance to act on it.
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'hide_and_priority_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['hide_and_priority_field'] = [
            'misc' => "@HIDECHOICE='X' @FHIR-ONTOLOGY-OPTIONS='priority-codes=X,Y'",
        ];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'X', 'system' => 'sys', 'display' => 'Hidden and priority'],
                    ['code' => 'Y', 'system' => 'sys', 'display' => 'Priority only'],
                    ['code' => 'Z', 'system' => 'sys', 'display' => 'Neither'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        $this->assertArrayNotHasKey('X|sys', $results, 'hidden even though also listed as priority');
        // Y is still prioritized normally, ahead of non-priority Z.
        $this->assertSame(['Y|sys', 'Z|sys'], array_keys($results));
    }

    public function testSearchOntologyMalformedOntologyOptionsIgnoredWithoutError(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $_GET['field'] = 'malformed_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['malformed_field'] = [
            'misc' => "@FHIR-ONTOLOGY-OPTIONS='not-a-real-option;;also-bogus=1'",
        ];
        FakeHttpTransport::$response = json_encode([
            'expansion' => [
                'contains' => [
                    ['code' => 'C1', 'system' => 'sys', 'display' => 'One'],
                ],
            ],
        ]);

        $results = $this->module->searchOntology('http://example.test/vs', 'term', 20);

        // No crash, and default (filter sent, ${CODE}|${SYSTEM} format) behavior.
        $this->assertStringContainsString('filter=term', FakeHttpTransport::$calls[0]['url']);
        $this->assertSame(['C1|sys' => 'One'], $results);
    }

    // --- getSearchOptions() / @FHIR-ONTOLOGY-OPTIONS parsing ---------------------

    public function testGetSearchOptionsDefaultsWhenNoTag(): void
    {
        $_GET['field'] = 'plain_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->metadata['plain_field'] = ['misc' => null];

        $options = $this->module->getSearchOptions();

        $this->assertSame(['return-all' => false, 'code-template' => null, 'priority-codes' => []], $options);
    }

    public function testGetSearchOptionsParsesAllThreeOptions(): void
    {
        $_GET['field'] = 'my_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->metadata['my_field'] = [
            'misc' => '@FHIR-ONTOLOGY-OPTIONS=\'return-all;code-template=${CODE};priority-codes=A, B\'',
        ];

        $options = $this->module->getSearchOptions();

        $this->assertTrue($options['return-all']);
        $this->assertSame('${CODE}', $options['code-template']);
        $this->assertSame(['A', 'B'], $options['priority-codes']);
    }

    public function testGetSearchOptionsIgnoresUnrecognizedTokensAndKeys(): void
    {
        $_GET['field'] = 'my_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->metadata['my_field'] = [
            'misc' => "@FHIR-ONTOLOGY-OPTIONS='bogus-flag;unknown-key=value;return-all'",
        ];

        $options = $this->module->getSearchOptions();

        $this->assertTrue($options['return-all']);
        $this->assertNull($options['code-template']);
        $this->assertSame([], $options['priority-codes']);
    }

    public function testGetSearchOptionsMergesMultipleTagOccurrences(): void
    {
        // Mirrors @HIDECHOICE's own tolerance for appearing more than once in
        // an annotation (see its while-loop over PREG_OFFSET_CAPTURE matches).
        $_GET['field'] = 'my_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->metadata['my_field'] = [
            'misc' => "@FHIR-ONTOLOGY-OPTIONS='return-all' @FHIR-ONTOLOGY-OPTIONS='priority-codes=A'",
        ];

        $options = $this->module->getSearchOptions();

        $this->assertTrue($options['return-all']);
        $this->assertSame(['A'], $options['priority-codes']);
    }

    // --- getHideChoice() ---
    // Regression coverage: $Proj must be pulled in via `global $Proj` or the
    // in-memory fast path never runs and every keystroke falls through to a
    // full getDataDictionary() call.

    public function testGetHideChoiceUsesInMemoryProjectMetadataFastPath(): void
    {
        $_GET['field'] = 'my_field';
        $_GET['pid'] = '17';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['my_field'] = ['misc' => "@HIDECHOICE='A,B'"];

        $hidden = $this->module->getHideChoice();

        $this->assertSame(['A', 'B'], $hidden);
        $this->assertSame(0, \REDCap::$getDataDictionaryCallCount, 'the in-memory fast path must not fall through to getDataDictionary()');
    }

    public function testGetFieldAnnotationFastPathReadsMiscKeyNotFieldAnnotationKey(): void
    {
        // Regression: $Proj->metadata[$field] stores the annotation under the
        // raw DB column name 'misc', unlike getDataDictionary()'s array (which
        // normalises it to 'field_annotation'). An earlier version of the fast
        // path read 'field_annotation' here too, so it silently returned null
        // for every real request instead of falling through to the (correct)
        // getDataDictionary() branch - confirmed live against project 16's
        // 'loinc' field, where @FHIR-ONTOLOGY-OPTIONS was saved but never applied.
        $_GET['field'] = 'my_field';
        $_GET['pid'] = '17';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['my_field'] = ['misc' => "@FHIR-ONTOLOGY-OPTIONS='return-all'"];

        $options = $this->module->getSearchOptions();

        $this->assertTrue($options['return-all']);
        $this->assertSame(0, \REDCap::$getDataDictionaryCallCount, 'the in-memory fast path must not fall through to getDataDictionary()');
    }

    public function testGetHideChoiceFallsBackToDataDictionaryWhenProjMismatchesRequestedPid(): void
    {
        $_GET['field'] = 'my_field';
        $_GET['pid'] = '99';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17'; // a different project than requested
        $GLOBALS['Proj']->metadata['my_field'] = ['misc' => "@HIDECHOICE='WRONG'"];
        \REDCap::$dataDictionary = ['my_field' => ['field_annotation' => "@HIDECHOICE='A'"]];

        $hidden = $this->module->getHideChoice();

        $this->assertSame(['A'], $hidden);
        $this->assertSame(1, \REDCap::$getDataDictionaryCallCount);
    }

    public function testGetHideChoiceUnannotatedFieldTakesFastPathNotFullDictionaryReload(): void
    {
        // Regression: an earlier fix checked isset(field_annotation) rather than
        // isset($Proj->metadata[$field]) for the fast-path condition, which is
        // NULL for the common case of a field with no annotation at all - every
        // un-annotated field fell through to a full dictionary load on every
        // keystroke.
        $_GET['field'] = 'plain_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->metadata['plain_field'] = ['misc' => null];

        $hidden = $this->module->getHideChoice();

        $this->assertSame([], $hidden);
        $this->assertSame(0, \REDCap::$getDataDictionaryCallCount);
    }

    public function testGetHideChoiceReturnsEmptyWhenNoFieldRequested(): void
    {
        $this->assertSame([], $this->module->getHideChoice());
    }

    public function testGetHideChoiceFastPathReadsMiscKeyNotFieldAnnotationKey(): void
    {
        // Regression: $Proj->metadata[$field] stores the annotation under the
        // raw DB column name 'misc', unlike getDataDictionary()'s array (which
        // normalises it to 'field_annotation'). An earlier version of the fast
        // path read 'field_annotation' here too, so it silently returned no
        // annotation - and therefore no hidden codes - for every real request,
        // despite the annotation genuinely being present in $Proj->metadata.
        $_GET['field'] = 'my_field';
        $_GET['pid'] = '17';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17';
        $GLOBALS['Proj']->metadata['my_field'] = ['misc' => "@HIDECHOICE='A'"];

        $hidden = $this->module->getHideChoice();

        $this->assertSame(['A'], $hidden);
        $this->assertSame(0, \REDCap::$getDataDictionaryCallCount, 'the in-memory fast path must not fall through to getDataDictionary()');
    }

    // --- @FHIR-ONTOLOGY-HIDECHOICE ---
    // A second, non-colliding tag name for the same purpose as @HIDECHOICE -
    // see the "@HIDECHOICE never actually worked..." README section for why.

    public function testGetHideChoiceRecognizesFhirOntologyHideChoiceTag(): void
    {
        $_GET['field'] = 'my_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->metadata['my_field'] = ['misc' => "@FHIR-ONTOLOGY-HIDECHOICE='X,Y'"];

        $hidden = $this->module->getHideChoice();

        $this->assertSame(['X', 'Y'], $hidden);
    }

    public function testGetHideChoiceMergesBothTagNamesWhenBothPresent(): void
    {
        $_GET['field'] = 'my_field';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->metadata['my_field'] = [
            'misc' => "@HIDECHOICE='A' @FHIR-ONTOLOGY-HIDECHOICE='B'",
        ];

        $hidden = $this->module->getHideChoice();

        $this->assertSame(['A', 'B'], $hidden);
    }

    // --- validateSettings() ---

    public function testValidateSettingsReturnsNoErrorsAtProjectScopeWhereThisModuleHasNoSettings(): void
    {
        // Regression: this module declares no project-level settings at all, so
        // a project-scope Configure dialog save previously called this with an
        // undefined fhir_api_url and unconditionally probed it anyway, always
        // failing with "Failed to get metadata for fhir server at ''" - even
        // though there is nothing to validate at that scope. Confirmed present
        // on `main` too (not introduced by PR #5) before this fix.
        $errors = $this->module->validateSettings([]);

        $this->assertSame('', $errors);
        $this->assertCount(0, FakeHttpTransport::$calls, 'must not probe any URL when there is nothing to validate');
    }

    public function testValidateSettingsChecksFhirServerMetadataAtSystemScope(): void
    {
        FakeHttpTransport::$response = '{"resourceType":"CapabilityStatement"}';

        $errors = $this->module->validateSettings([
            'return_no_result' => false,
            'no_result_label' => '',
            'no_result_code' => '',
            'fhir_api_url' => 'https://ts.example.test/fhir',
            'authentication_type' => 'none',
        ]);

        $this->assertSame('', $errors);
        $this->assertCount(1, FakeHttpTransport::$calls);
        $this->assertSame('https://ts.example.test/fhir/metadata', FakeHttpTransport::$calls[0]['url']);
    }

    public function testValidateSettingsReportsUnreachableFhirServer(): void
    {
        FakeHttpTransport::$response = false;

        $errors = $this->module->validateSettings([
            'return_no_result' => false,
            'no_result_label' => '',
            'no_result_code' => '',
            'fhir_api_url' => 'https://ts.example.test/fhir',
            'authentication_type' => 'none',
        ]);

        $this->assertStringContainsString('Failed to get metadata', $errors);
    }

    public function testValidateSettingsRequiresNoResultLabelAndCodeWhenReturnNoResultIsChecked(): void
    {
        FakeHttpTransport::$response = '{}';

        $errors = $this->module->validateSettings([
            'return_no_result' => true,
            'no_result_label' => '',
            'no_result_code' => '',
            'fhir_api_url' => 'https://ts.example.test/fhir',
            'authentication_type' => 'none',
        ]);

        $this->assertStringContainsString('No Result Label is required', $errors);
        $this->assertStringContainsString('No Result Code is required', $errors);
    }

    // --- httpGet()/httpPost() SSRF containment wiring ---
    // FhirRequestPolicyTest covers isWithinBase()'s pure logic exhaustively;
    // these confirm it is actually wired into the module's own HTTP helpers.

    public function testHttpGetRefusesUrlOutsideConfiguredFhirServer(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = 'should never be reached';

        $result = $this->module->httpGet('https://evil.example.test/steal', ['User-Agent: Redcap']);

        $this->assertFalse($result);
        $this->assertCount(0, FakeHttpTransport::$calls, 'a disallowed URL must never reach the transport');
    }

    // --- getOnlineDesignerSection() ---
    // Regression coverage for the module.ajax() migration: the Online Designer's
    // ajax calls used to hand-roll $.ajax() + a manually embedded CSRF token
    // against a standalone FindValueSetService.php page - both replaced by
    // REDCap's own JavaScript Module Object, which handles CSRF/verification
    // internally. PHPUnit can't render or execute this JS, but it can lock in
    // that the JSMO is actually initialized and referenced correctly.

    public function testOnlineDesignerSectionInitializesTheJavascriptModuleObject(): void
    {
        $html = $this->module->getOnlineDesignerSection();

        $this->assertStringContainsString('<!-- FAKE_JSMO_INIT -->', $html);
        // Not an assertion this method can make about the .ajax() call sites
        // themselves anymore (those moved to js/online-designer.js, a real
        // file PHPUnit can't execute) - just that the wrapper carries the
        // module object path for that file to pick up at init time.
        $this->assertStringContainsString('data-module-object="FAKE.Js.ModuleObject"', $html);
        // The old page-based approach, and the old inline-script approach it
        // was replaced with, are both fully gone, not just unused.
        $this->assertStringNotContainsString('FindValueSetService', $html);
        $this->assertStringNotContainsString('redcap_csrf_token', $html);
        $this->assertStringNotContainsString('$.ajax', $html);
        $this->assertStringNotContainsString('function show_selected_valueset', $html);
        $this->assertStringNotContainsString('.ajax(', $html);
    }

    public function testOnlineDesignerSectionLoadsExtractedJsFileNotInlineScript(): void
    {
        $html = $this->module->getOnlineDesignerSection();

        $this->assertStringContainsString('<script src="FAKE_MODULE_URL/js/online-designer.js"></script>', $html);
        $this->assertStringNotContainsString('<script type="text/javascript">', $html);
    }

    public function testOnlineDesignerSectionEscapesTheModuleObjectNameInTheDataAttribute(): void
    {
        // getJavascriptModuleObjectName() is a REDCap-core-computed value, not
        // attacker input - but the data-module-object attribute must still be
        // safe against a value containing HTML metacharacters, since the
        // escapeHtml() call is what actually guarantees that, not the value's
        // real-world shape.
        $this->module->jsModuleObjectName = 'FAKE"><script>alert(1)</script>';

        $html = $this->module->getOnlineDesignerSection();

        $this->assertStringContainsString(
            'data-module-object="FAKE&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"',
            $html
        );
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testTooltipScriptsLoadExtractedJsFileNotInlineScript(): void
    {
        $this->module->systemSettings['add_value_tooltip'] = true;

        ob_start();
        $this->module->redcap_data_entry_form(1, 1, 'instrument', 1, null, 1);
        $dataEntryHtml = ob_get_clean();

        ob_start();
        $this->module->redcap_survey_page(1, 1, 'instrument', 1, null, 'hash', 1, 1);
        $surveyHtml = ob_get_clean();

        foreach ([$dataEntryHtml, $surveyHtml] as $html) {
            $this->assertStringContainsString('<script src="FAKE_MODULE_URL/js/value-tooltip.js"></script>', $html);
            $this->assertStringNotContainsString('autosug-ont-field', $html);
        }
    }

    public function testTooltipScriptsAreOmittedWhenSettingDisabled(): void
    {
        $this->module->systemSettings['add_value_tooltip'] = false;

        ob_start();
        $this->module->redcap_data_entry_form(1, 1, 'instrument', 1, null, 1);
        $dataEntryHtml = ob_get_clean();

        ob_start();
        $this->module->redcap_survey_page(1, 1, 'instrument', 1, null, 'hash', 1, 1);
        $surveyHtml = ob_get_clean();

        $this->assertSame('', $dataEntryHtml);
        $this->assertSame('', $surveyHtml);
    }

    // --- findValueSet() result cap ---
    // Confirmed live against the real configured Ontoserver: an 'isa' search
    // for a common term (e.g. "neopl", a full-text search across all of
    // SNOMED CT) returned 8,300+ matches despite requesting _count=20 - the
    // server does not always honor that for this query shape. Handing an
    // uncapped list that large to the Online Designer's jQuery UI autocomplete
    // widget locked up the browser tab for tens of seconds trying to render
    // it (confirmed via a live CPU profile: main-thread time dominated by
    // jQuery/Sizzle selector-engine internals processing the giant list).
    // This cap must hold regardless of what the server actually returns, for
    // every search type - not rely on _count being honored.

    public function testFindValueSetCapsIsaResultsEvenWhenServerIgnoresCount(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['snomed_support'] = true;
        $contains = [];
        for ($i = 0; $i < 50; $i++) {
            $contains[] = ['code' => "C$i", 'system' => 'sys', 'display' => "Match $i"];
        }
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => $contains]]);

        $result = $this->module->findValueSet('isa', 'term');

        $this->assertCount(20, $result);
    }

    public function testFindValueSetCapAppliesToNameSearchToo(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $entries = [];
        for ($i = 0; $i < 50; $i++) {
            $entries[] = ['resource' => ['name' => "Name $i", 'url' => "http://example.test/vs$i"]];
        }
        FakeHttpTransport::$response = json_encode(['entry' => $entries]);

        $result = $this->module->findValueSet('name', 'term');

        $this->assertCount(20, $result);
    }

    // --- findValueSet() $expand count parameter ---
    // Regression coverage: ValueSet/$expand is a FHIR *operation*, whose count
    // parameter is 'count' - not '_count', the REST search-result modifier
    // used by plain resource searches (the 'name'/'codesystem' branches,
    // which correctly use '_count' against /ValueSet and /CodeSystem
    // respectively). Confirmed live against the real configured Ontoserver:
    // '_count=20' against $expand is silently ignored (the server returns
    // its full, unbounded result instead), while 'count=20' is honored
    // exactly. This was the actual root cause of the "isa" lockup bug -
    // array_slice() above only trims the response after an unbounded
    // expansion has already been computed and transmitted; sending the
    // right parameter avoids that expansion (and its cost to the FHIR
    // server) in the first place.

    public function testFindValueSetRefsetSendsCountNotUnderscoreCount(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['snomed_support'] = true;
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => []]]);

        $this->module->findValueSet('refset', 'term');

        // parse_str(), not assertStringContainsString('count=20', ...) - '_count=20'
        // itself contains the substring 'count=20', so a substring check alone
        // passes whether the bug is present or fixed; only actually parsing the
        // query string can tell 'count' and '_count' apart as distinct parameters.
        parse_str(parse_url(FakeHttpTransport::$calls[0]['url'], PHP_URL_QUERY), $sentParams);
        $this->assertArrayHasKey('count', $sentParams);
        $this->assertSame('20', $sentParams['count']);
        $this->assertArrayNotHasKey('_count', $sentParams);
    }

    public function testFindValueSetIsaSendsCountNotUnderscoreCount(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['snomed_support'] = true;
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => []]]);

        $this->module->findValueSet('isa', 'term');

        parse_str(parse_url(FakeHttpTransport::$calls[0]['url'], PHP_URL_QUERY), $sentParams);
        $this->assertArrayHasKey('count', $sentParams);
        $this->assertSame('20', $sentParams['count']);
        $this->assertArrayNotHasKey('_count', $sentParams);
    }

    public function testFindValueSetLoincAnswerOntoserverSendsCountNotUnderscoreCount(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['loinc_support'] = 'ontoserver';
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => []]]);

        $this->module->findValueSet('loinc_answer', 'term');

        $sentParams = json_decode(FakeHttpTransport::$calls[0]['params'], true);
        $names = array_column($sentParams['parameter'], 'name');
        $this->assertContains('count', $names);
        $this->assertNotContains('_count', $names);
    }

    public function testFindValueSetLoincAnswerFilterSendsCountNotUnderscoreCount(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['loinc_support'] = 'filter';
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => []]]);

        $this->module->findValueSet('loinc_answer', 'term');

        parse_str(parse_url(FakeHttpTransport::$calls[0]['url'], PHP_URL_QUERY), $sentParams);
        $this->assertArrayHasKey('count', $sentParams);
        $this->assertSame('100', $sentParams['count']);
        $this->assertArrayNotHasKey('_count', $sentParams);
    }

    // --- redcap_module_ajax() ---
    // Backs getOnlineDesignerSection()'s two ajax calls - see config.json's
    // auth-ajax-actions. Replaces the old FindValueSetService.php page's logic
    // one-for-one (same delegation to findValueSet()/getValueSetInfo(), same
    // validation), just reached via a different transport.

    public function testRedcapModuleAjaxFindValuesetDelegatesToFindValueSet(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $this->module->systemSettings['snomed_support'] = true;
        FakeHttpTransport::$response = json_encode(['expansion' => ['contains' => [
            ['code' => 'C1', 'system' => 'sys', 'display' => 'Match'],
        ]]]);

        $result = $this->module->redcap_module_ajax('find-valueset', ['type' => 'isa', 'query' => 'term'], null);

        $this->assertSame([['label' => 'Match', 'value' => 'http://snomed.info/sct?fhir_vs=isa/C1']], $result);
    }

    public function testRedcapModuleAjaxFindValuesetRejectsMissingParams(): void
    {
        $result = $this->module->redcap_module_ajax('find-valueset', ['type' => 'isa'], null);

        $this->assertArrayHasKey('error', $result);
    }

    public function testRedcapModuleAjaxFindValuesetRejectsUnknownType(): void
    {
        $result = $this->module->redcap_module_ajax('find-valueset', ['type' => 'bogus', 'query' => 'x'], null);

        $this->assertArrayHasKey('error', $result);
    }

    public function testRedcapModuleAjaxGetValuesetInfoDelegatesToGetValueSetInfo(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = '{"resourceType":"ValueSet","status":"active"}';

        $result = $this->module->redcap_module_ajax('get-valueset-info', ['valueSet' => 'http://example.test/vs'], null);

        $this->assertSame(['resourceType' => 'ValueSet', 'status' => 'active'], $result);
    }

    public function testRedcapModuleAjaxGetValuesetInfoReportsTransportFailure(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = false;

        $result = $this->module->redcap_module_ajax('get-valueset-info', ['valueSet' => 'http://example.test/vs'], null);

        $this->assertArrayHasKey('error', $result);
        $this->assertCount(1, FakeHttpTransport::$calls, 'must exercise the fake transport, not be rejected before reaching it');
    }

    public function testRedcapModuleAjaxGetValuesetInfoReportsUnparseableResponse(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = 'not json';

        $result = $this->module->redcap_module_ajax('get-valueset-info', ['valueSet' => 'http://example.test/vs'], null);

        $this->assertArrayHasKey('error', $result);
        $this->assertCount(1, FakeHttpTransport::$calls, 'must exercise the fake transport, not be rejected before reaching it');
    }

    public function testRedcapModuleAjaxGetValuesetInfoRejectsMissingValueSet(): void
    {
        $result = $this->module->redcap_module_ajax('get-valueset-info', [], null);

        $this->assertArrayHasKey('error', $result);
    }

    public function testRedcapModuleAjaxRejectsUnknownAction(): void
    {
        $result = $this->module->redcap_module_ajax('not-a-real-action', [], null);

        $this->assertArrayHasKey('error', $result);
    }

    public function testHttpGetAllowsUrlWithinConfiguredFhirServer(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = 'ok';

        $result = $this->module->httpGet('https://ts.example.test/fhir/metadata', ['User-Agent: Redcap']);

        $this->assertSame('ok', $result);
        $this->assertCount(1, FakeHttpTransport::$calls);
    }
}
