<?php
/**
 * Plain-PHP test runner. No composer, no PHPUnit - the module must stay
 * installable by copying a directory, so tests carry no dependencies.
 *
 * Usage: php tests/run.php
 */

require_once __DIR__ . '/../FhirRequestPolicy.php';

use AEHRC\FhirOntologyAutocompleteExternalModule\FhirRequestPolicy;

$GLOBALS['tests_passed'] = 0;
$GLOBALS['tests_failed'] = 0;

function fail_test($label, $detail)
{
    $GLOBALS['tests_failed']++;
    echo "FAIL  $label\n      $detail\n";
}

function pass_test()
{
    $GLOBALS['tests_passed']++;
}

function assertSame($expected, $actual, $label)
{
    if ($expected === $actual) {
        pass_test();
        return;
    }
    fail_test($label, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function assertTrue($actual, $label)
{
    assertSame(true, $actual, $label);
}

function assertFalse($actual, $label)
{
    assertSame(false, $actual, $label);
}

// --- resolveTimeout -------------------------------------------------------

assertSame(30, FhirRequestPolicy::resolveTimeout('30'), 'resolveTimeout: numeric string');
assertSame(30, FhirRequestPolicy::resolveTimeout(30), 'resolveTimeout: integer');
assertSame(10, FhirRequestPolicy::resolveTimeout(''), 'resolveTimeout: blank falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout(null), 'resolveTimeout: null falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout('0'), 'resolveTimeout: zero falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout('-5'), 'resolveTimeout: negative falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout('abc'), 'resolveTimeout: non-numeric falls back');
assertSame(7, FhirRequestPolicy::resolveTimeout('7.9'), 'resolveTimeout: truncates to int');

// --- countsAsFailure ------------------------------------------------------

assertTrue(FhirRequestPolicy::countsAsFailure(8.0, 10), 'countsAsFailure: exactly 80% counts');
assertTrue(FhirRequestPolicy::countsAsFailure(12.0, 10), 'countsAsFailure: over timeout counts');
assertFalse(FhirRequestPolicy::countsAsFailure(0.2, 10), 'countsAsFailure: fast 4xx does not count');
assertFalse(FhirRequestPolicy::countsAsFailure(7.9, 10), 'countsAsFailure: just under threshold');

// --- breaker state --------------------------------------------------------

assertFalse(FhirRequestPolicy::isOpen(0, 1000), 'isOpen: never-opened breaker is closed');
assertTrue(FhirRequestPolicy::isOpen(1060, 1000), 'isOpen: inside the window');
assertFalse(FhirRequestPolicy::isOpen(1000, 1000), 'isOpen: at expiry is not open');
assertFalse(FhirRequestPolicy::isOpen(900, 1000), 'isOpen: after expiry is not open');

assertFalse(FhirRequestPolicy::needsRearm(0, 1000), 'needsRearm: never-opened needs no re-arm');
assertFalse(FhirRequestPolicy::needsRearm(1060, 1000), 'needsRearm: still inside window');
assertTrue(FhirRequestPolicy::needsRearm(1000, 1000), 'needsRearm: at expiry');
assertTrue(FhirRequestPolicy::needsRearm(900, 1000), 'needsRearm: after expiry');

assertSame(1, FhirRequestPolicy::nextFailureCount(0), 'nextFailureCount: from zero');
assertSame(1, FhirRequestPolicy::nextFailureCount(''), 'nextFailureCount: from blank setting');
assertSame(4, FhirRequestPolicy::nextFailureCount(3), 'nextFailureCount: increments');

assertFalse(FhirRequestPolicy::opensBreaker(2), 'opensBreaker: below threshold');
assertTrue(FhirRequestPolicy::opensBreaker(3), 'opensBreaker: at threshold');
assertTrue(FhirRequestPolicy::opensBreaker(4), 'opensBreaker: above threshold');

// --- isWithinBase ---------------------------------------------------------

$base = 'https://ts.example.org/fhir';

assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/ValueSet/$expand?url=x', $base),
    'isWithinBase: expand under base');
assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir', $base),
    'isWithinBase: the base itself');
assertTrue(FhirRequestPolicy::isWithinBase('https://TS.EXAMPLE.ORG/fhir/metadata', $base),
    'isWithinBase: host comparison is case-insensitive');

assertFalse(FhirRequestPolicy::isWithinBase('https://evil.example.com/fhir/metadata', $base),
    'isWithinBase: different host rejected');
assertFalse(FhirRequestPolicy::isWithinBase('http://ts.example.org/fhir/metadata', $base),
    'isWithinBase: scheme downgrade rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org:8443/fhir/metadata', $base),
    'isWithinBase: differing explicit port rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://user:pass@ts.example.org/fhir/metadata', $base),
    'isWithinBase: embedded credentials rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/other/metadata', $base),
    'isWithinBase: path outside base rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhirX/metadata', $base),
    'isWithinBase: sibling prefix rejected');
assertFalse(FhirRequestPolicy::isWithinBase('', $base), 'isWithinBase: empty url rejected');
assertFalse(FhirRequestPolicy::isWithinBase(null, $base), 'isWithinBase: null url rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/metadata', ''),
    'isWithinBase: empty base rejected');
assertFalse(FhirRequestPolicy::isWithinBase('not a url', $base), 'isWithinBase: garbage rejected');

assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org:443/fhir/metadata', $base),
    'isWithinBase: explicit default port matches implicit');

// --- dot segments (literal and percent-encoded) ---------------------------

assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/../admin', $base),
    'isWithinBase: dot-dot segment rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/../../etc', $base),
    'isWithinBase: multiple dot-dot segments rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2e%2e/admin', $base),
    'isWithinBase: percent-encoded dot-dot lowercase rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2E%2E/admin', $base),
    'isWithinBase: percent-encoded dot-dot uppercase rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/..%2fadmin', $base),
    'isWithinBase: mixed literal and encoded dot-dot with encoded slash rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2e%2e%2fadmin', $base),
    'isWithinBase: fully percent-encoded dot-dot-slash rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%252e%252e/admin', $base),
    'isWithinBase: double-encoded dot-dot rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/.', $base),
    'isWithinBase: single dot segment rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/./metadata', $base),
    'isWithinBase: dot segment before path rejected');

assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/a.b/c', $base),
    'isWithinBase: dot within segment name allowed');

// --- path evasion techniques (round 2) ------------------------------------

assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%25%32%65%25%32%65/x', $base),
    'isWithinBase: double-encoded dots %25%32%65 rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/..;/admin', $base),
    'isWithinBase: path parameter stripping attack rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/....//admin', $base),
    'isWithinBase: four-dot segment rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2e%2e%5cadmin', $base),
    'isWithinBase: backslash as separator rejected');

// --- query strings must not block legitimate queries ----------------------

assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/CodeSystem/$lookup?code=1..5', $base),
    'isWithinBase: dots in query string allowed');
assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/x?q=../../etc', $base),
    'isWithinBase: traversal in query string allowed');

// --- summary --------------------------------------------------------------

$passed = $GLOBALS['tests_passed'];
$failed = $GLOBALS['tests_failed'];
if ($failed > 0) {
    echo "\nFAILED ($failed failed, $passed passed)\n";
    exit(1);
}
echo "OK ($passed assertions)\n";
exit(0);
