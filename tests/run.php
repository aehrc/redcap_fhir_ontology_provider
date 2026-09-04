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

// --- summary --------------------------------------------------------------

$passed = $GLOBALS['tests_passed'];
$failed = $GLOBALS['tests_failed'];
if ($failed > 0) {
    echo "\nFAILED ($failed failed, $passed passed)\n";
    exit(1);
}
echo "OK ($passed assertions)\n";
exit(0);
