<?php

namespace AEHRC\FhirOntologyAutocompleteExternalModule;

use PHPUnit\Framework\TestCase;

/**
 * FhirRequestPolicy is deliberately free of I/O, REDCap and the External
 * Modules framework (see its own docblock), so these tests exercise it
 * directly with no fakes at all - ported one-for-one from this module's
 * original dependency-free harness (tests/run.php, added in PR #5 before
 * PHPUnit was available under this module's then-current php-version-min
 * of 5.4.0; framework 16 raised the floor to 8.0.0, making PHPUnit usable
 * here for the first time).
 */
final class FhirRequestPolicyTest extends TestCase
{
    // --- resolveTimeout ---------------------------------------------------

    public function testResolveTimeoutNumericString(): void
    {
        $this->assertSame(30, FhirRequestPolicy::resolveTimeout('30'));
    }

    public function testResolveTimeoutInteger(): void
    {
        $this->assertSame(30, FhirRequestPolicy::resolveTimeout(30));
    }

    public function testResolveTimeoutBlankFallsBack(): void
    {
        $this->assertSame(10, FhirRequestPolicy::resolveTimeout(''));
    }

    public function testResolveTimeoutNullFallsBack(): void
    {
        $this->assertSame(10, FhirRequestPolicy::resolveTimeout(null));
    }

    public function testResolveTimeoutZeroFallsBack(): void
    {
        $this->assertSame(10, FhirRequestPolicy::resolveTimeout('0'));
    }

    public function testResolveTimeoutNegativeFallsBack(): void
    {
        $this->assertSame(10, FhirRequestPolicy::resolveTimeout('-5'));
    }

    public function testResolveTimeoutNonNumericFallsBack(): void
    {
        $this->assertSame(10, FhirRequestPolicy::resolveTimeout('abc'));
    }

    public function testResolveTimeoutTruncatesToInt(): void
    {
        $this->assertSame(7, FhirRequestPolicy::resolveTimeout('7.9'));
    }

    // --- countsAsFailure ----------------------------------------------------

    public function testCountsAsFailureExactly80PercentCounts(): void
    {
        $this->assertTrue(FhirRequestPolicy::countsAsFailure(8.0, 10));
    }

    public function testCountsAsFailureOverTimeoutCounts(): void
    {
        $this->assertTrue(FhirRequestPolicy::countsAsFailure(12.0, 10));
    }

    public function testCountsAsFailureFast4xxDoesNotCount(): void
    {
        $this->assertFalse(FhirRequestPolicy::countsAsFailure(0.2, 10));
    }

    public function testCountsAsFailureJustUnderThresholdDoesNotCount(): void
    {
        $this->assertFalse(FhirRequestPolicy::countsAsFailure(7.9, 10));
    }

    // --- breaker state --------------------------------------------------------

    public function testIsOpenNeverOpenedBreakerIsClosed(): void
    {
        $this->assertFalse(FhirRequestPolicy::isOpen(0, 1000));
    }

    public function testIsOpenInsideTheWindow(): void
    {
        $this->assertTrue(FhirRequestPolicy::isOpen(1060, 1000));
    }

    public function testIsOpenAtExpiryIsNotOpen(): void
    {
        $this->assertFalse(FhirRequestPolicy::isOpen(1000, 1000));
    }

    public function testIsOpenAfterExpiryIsNotOpen(): void
    {
        $this->assertFalse(FhirRequestPolicy::isOpen(900, 1000));
    }

    public function testNeedsRearmNeverOpenedNeedsNoRearm(): void
    {
        $this->assertFalse(FhirRequestPolicy::needsRearm(0, 1000));
    }

    public function testNeedsRearmStillInsideWindow(): void
    {
        $this->assertFalse(FhirRequestPolicy::needsRearm(1060, 1000));
    }

    public function testNeedsRearmAtExpiry(): void
    {
        $this->assertTrue(FhirRequestPolicy::needsRearm(1000, 1000));
    }

    public function testNeedsRearmAfterExpiry(): void
    {
        $this->assertTrue(FhirRequestPolicy::needsRearm(900, 1000));
    }

    public function testNextFailureCountFromZero(): void
    {
        $this->assertSame(1, FhirRequestPolicy::nextFailureCount(0));
    }

    public function testNextFailureCountFromBlankSetting(): void
    {
        $this->assertSame(1, FhirRequestPolicy::nextFailureCount(''));
    }

    public function testNextFailureCountIncrements(): void
    {
        $this->assertSame(4, FhirRequestPolicy::nextFailureCount(3));
    }

    public function testOpensBreakerBelowThreshold(): void
    {
        $this->assertFalse(FhirRequestPolicy::opensBreaker(2));
    }

    public function testOpensBreakerAtThreshold(): void
    {
        $this->assertTrue(FhirRequestPolicy::opensBreaker(3));
    }

    public function testOpensBreakerAboveThreshold(): void
    {
        $this->assertTrue(FhirRequestPolicy::opensBreaker(4));
    }

    // --- isWithinBase -----------------------------------------------------

    private const BASE = 'https://ts.example.org/fhir';

    public function testIsWithinBaseExpandUnderBase(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/ValueSet/$expand?url=x', self::BASE));
    }

    public function testIsWithinBaseTheBaseItself(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir', self::BASE));
    }

    public function testIsWithinBaseHostComparisonIsCaseInsensitive(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://TS.EXAMPLE.ORG/fhir/metadata', self::BASE));
    }

    public function testIsWithinBaseDifferentHostRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://evil.example.com/fhir/metadata', self::BASE));
    }

    public function testIsWithinBaseSchemeDowngradeRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('http://ts.example.org/fhir/metadata', self::BASE));
    }

    public function testIsWithinBaseDifferingExplicitPortRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org:8443/fhir/metadata', self::BASE));
    }

    public function testIsWithinBaseEmbeddedCredentialsRejectedWhenBaseHasNone(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://user:pass@ts.example.org/fhir/metadata', self::BASE));
    }

    private const BASE_WITH_CREDS = 'https://admin:s3cret@ts.example.org/fhir';

    public function testIsWithinBaseCredentialsMatchingTheBaseExactlyAreAllowed(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://admin:s3cret@ts.example.org/fhir/metadata', self::BASE_WITH_CREDS));
    }

    public function testIsWithinBaseCredentialsDifferingFromTheBaseAreRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://admin:wrong@ts.example.org/fhir/metadata', self::BASE_WITH_CREDS));
    }

    public function testIsWithinBaseNoCredentialsOnTheUrlIsAllowedWhenTheBaseHasItsOwn(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/metadata', self::BASE_WITH_CREDS));
    }

    public function testIsWithinBasePathOutsideBaseRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/other/metadata', self::BASE));
    }

    public function testIsWithinBaseSiblingPrefixRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhirX/metadata', self::BASE));
    }

    public function testIsWithinBaseEmptyUrlRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('', self::BASE));
    }

    public function testIsWithinBaseNullUrlRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase(null, self::BASE));
    }

    public function testIsWithinBaseEmptyBaseRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/metadata', ''));
    }

    public function testIsWithinBaseGarbageRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('not a url', self::BASE));
    }

    public function testIsWithinBaseExplicitDefaultPortMatchesImplicit(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org:443/fhir/metadata', self::BASE));
    }

    // --- dot segments (literal and percent-encoded) ------------------------

    public function testIsWithinBaseDotDotSegmentRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/../admin', self::BASE));
    }

    public function testIsWithinBaseMultipleDotDotSegmentsRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/../../etc', self::BASE));
    }

    public function testIsWithinBasePercentEncodedDotDotLowercaseRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2e%2e/admin', self::BASE));
    }

    public function testIsWithinBasePercentEncodedDotDotUppercaseRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2E%2E/admin', self::BASE));
    }

    public function testIsWithinBaseMixedLiteralAndEncodedDotDotWithEncodedSlashRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/..%2fadmin', self::BASE));
    }

    public function testIsWithinBaseFullyPercentEncodedDotDotSlashRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2e%2e%2fadmin', self::BASE));
    }

    public function testIsWithinBaseDoubleEncodedDotDotRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%252e%252e/admin', self::BASE));
    }

    public function testIsWithinBaseSingleDotSegmentRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/.', self::BASE));
    }

    public function testIsWithinBaseDotSegmentBeforePathRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/./metadata', self::BASE));
    }

    public function testIsWithinBaseDotWithinSegmentNameAllowed(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/a.b/c', self::BASE));
    }

    // --- path evasion techniques (round 2) ---------------------------------

    public function testIsWithinBaseDoubleEncodedDotsRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%25%32%65%25%32%65/x', self::BASE));
    }

    public function testIsWithinBasePathParameterStrippingAttackRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/..;/admin', self::BASE));
    }

    public function testIsWithinBaseFourDotSegmentRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/....//admin', self::BASE));
    }

    public function testIsWithinBaseBackslashAsSeparatorRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/%2e%2e%5cadmin', self::BASE));
    }

    // --- query strings must not block legitimate queries -------------------

    public function testIsWithinBaseDotsInQueryStringAllowed(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/CodeSystem/$lookup?code=1..5', self::BASE));
    }

    public function testIsWithinBaseTraversalInQueryStringAllowed(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/x?q=../../etc', self::BASE));
    }

    // --- scheme must be http/https ------------------------------------------
    // validateSettings()'s self-check path uses isWithinBase($x, $x), where
    // scheme equality alone would let anything through.

    public function testIsWithinBaseGopherSchemeRejectedEvenWhenUrlEqualsBase(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('gopher://internal:70/', 'gopher://internal:70/'));
    }

    public function testIsWithinBaseFtpSchemeRejectedEvenWhenUrlEqualsBase(): void
    {
        $this->assertFalse(FhirRequestPolicy::isWithinBase('ftp://internal/fhir', 'ftp://internal/fhir'));
    }

    public function testIsWithinBaseHttpSchemeStillAllowedWhenUrlEqualsBase(): void
    {
        $this->assertTrue(FhirRequestPolicy::isWithinBase('http://ts.example.org/fhir', 'http://ts.example.org/fhir'));
    }

    // --- bearerToken(): a token endpoint's access_token goes into an Authorization header ---

    public function testBearerTokenAcceptsTokensThatCanGoInAHeader(): void
    {
        // (RFC 6750 b64tokens, and real-world ones outside it - e.g. Salesforce's contain !)
        foreach (['tok-1', 'abc.def.ghi', 'eyJhbGciOiJIUzI1NiJ9.e30.sig_-~+/', 'YWJj==', 'A',
                  '00Dxx0000001gPL!AQ4AQFO.x', 'tok;x=1', 'a#b|c^d'] as $token) {
            $this->assertSame($token, FhirRequestPolicy::bearerToken($token), $token);
        }
    }

    public function testBearerTokenRefusesAnythingThatCouldInjectAHeader(): void
    {
        foreach (["tok\r\nX-Injected: 1", "tok\n", "tok\r", "tok\t1", 'tok with space', "tok\x7f", "t\u{00F6}k", '', null, 4.2, true, ['tok']] as $value) {
            $this->assertNull(FhirRequestPolicy::bearerToken($value), var_export($value, true));
        }
    }

    public function testBearerTokenAcceptsAJsonNumberTokenAsItsDigits(): void
    {
        $this->assertSame('123456789', FhirRequestPolicy::bearerToken(123456789));
    }

    // --- isRedirectResponse(): the file_get_contents fallback doesn't follow redirects ---

    public function testIsRedirectResponse(): void
    {
        foreach (['HTTP/1.1 302 Found', 'HTTP/1.0 301 Moved Permanently', 'HTTP/2 307'] as $status) {
            $this->assertTrue(FhirRequestPolicy::isRedirectResponse([$status, 'Location: https://elsewhere.test/']), $status);
        }
        foreach ([['HTTP/1.1 200 OK'], ['HTTP/1.1 404 Not Found'], ['HTTP/1.1 3000 Odd'], [], ['Location: x']] as $headers) {
            $this->assertFalse(FhirRequestPolicy::isRedirectResponse($headers), var_export($headers, true));
        }
    }
}
