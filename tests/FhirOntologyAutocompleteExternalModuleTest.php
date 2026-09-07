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
        $GLOBALS['Proj']->metadata['some_field'] = ['field_annotation' => "@HIDECHOICE='C1'"];
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
        $GLOBALS['Proj']->metadata['my_field'] = ['field_annotation' => "@HIDECHOICE='A,B'"];

        $hidden = $this->module->getHideChoice();

        $this->assertSame(['A', 'B'], $hidden);
        $this->assertSame(0, \REDCap::$getDataDictionaryCallCount, 'the in-memory fast path must not fall through to getDataDictionary()');
    }

    public function testGetHideChoiceFallsBackToDataDictionaryWhenProjMismatchesRequestedPid(): void
    {
        $_GET['field'] = 'my_field';
        $_GET['pid'] = '99';
        $GLOBALS['Proj'] = new \Project();
        $GLOBALS['Proj']->project_id = '17'; // a different project than requested
        $GLOBALS['Proj']->metadata['my_field'] = ['field_annotation' => "@HIDECHOICE='WRONG'"];
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
        $GLOBALS['Proj']->metadata['plain_field'] = ['field_annotation' => null];

        $hidden = $this->module->getHideChoice();

        $this->assertSame([], $hidden);
        $this->assertSame(0, \REDCap::$getDataDictionaryCallCount);
    }

    public function testGetHideChoiceReturnsEmptyWhenNoFieldRequested(): void
    {
        $this->assertSame([], $this->module->getHideChoice());
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
        $html = ob_get_clean();

        $this->assertSame('', $html);
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
