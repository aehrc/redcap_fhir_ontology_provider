<?php
/**
 *
 *
 * CSIRO Open Source Software Licence Agreement (variation of the BSD / MIT License)
 * Copyright (c) 2018, Commonwealth Scientific and Industrial Research Organisation (CSIRO) ABN 41 687 119 230.
 * All rights reserved. CSIRO is willing to grant you a licence to this FhirOntologyAutocompleteModule on the following terms, except where otherwise indicated for third party material.
 * Redistribution and use of this software in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of CSIRO nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission of CSIRO.
 * EXCEPT AS EXPRESSLY STATED IN THIS AGREEMENT AND TO THE FULL EXTENT PERMITTED BY APPLICABLE LAW, THE SOFTWARE IS PROVIDED "AS-IS". CSIRO MAKES NO REPRESENTATIONS, WARRANTIES OR CONDITIONS OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO ANY REPRESENTATIONS, WARRANTIES OR CONDITIONS REGARDING THE CONTENTS OR ACCURACY OF THE SOFTWARE, OR OF TITLE, MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, NON-INFRINGEMENT, THE ABSENCE OF LATENT OR OTHER DEFECTS, OR THE PRESENCE OR ABSENCE OF ERRORS, WHETHER OR NOT DISCOVERABLE.
 * TO THE FULL EXTENT PERMITTED BY APPLICABLE LAW, IN NO EVENT SHALL CSIRO BE LIABLE ON ANY LEGAL THEORY (INCLUDING, WITHOUT LIMITATION, IN AN ACTION FOR BREACH OF CONTRACT, NEGLIGENCE OR OTHERWISE) FOR ANY CLAIM, LOSS, DAMAGES OR OTHER LIABILITY HOWSOEVER INCURRED.  WITHOUT LIMITING THE SCOPE OF THE PREVIOUS SENTENCE THE EXCLUSION OF LIABILITY SHALL INCLUDE: LOSS OF PRODUCTION OR OPERATION TIME, LOSS, DAMAGE OR CORRUPTION OF DATA OR RECORDS; OR LOSS OF ANTICIPATED SAVINGS, OPPORTUNITY, REVENUE, PROFIT OR GOODWILL, OR OTHER ECONOMIC LOSS; OR ANY SPECIAL, INCIDENTAL, INDIRECT, CONSEQUENTIAL, PUNITIVE OR EXEMPLARY DAMAGES, ARISING OUT OF OR IN CONNECTION WITH THIS AGREEMENT, ACCESS OF THE SOFTWARE OR ANY OTHER DEALINGS WITH THE SOFTWARE, EVEN IF CSIRO HAS BEEN ADVISED OF THE POSSIBILITY OF SUCH CLAIM, LOSS, DAMAGES OR OTHER LIABILITY.
 * APPLICABLE LEGISLATION SUCH AS THE AUSTRALIAN CONSUMER LAW MAY APPLY REPRESENTATIONS, WARRANTIES, OR CONDITIONS, OR IMPOSES OBLIGATIONS OR LIABILITY ON CSIRO THAT CANNOT BE EXCLUDED, RESTRICTED OR MODIFIED TO THE FULL EXTENT SET OUT IN THE EXPRESS TERMS OF THIS CLAUSE ABOVE "CONSUMER GUARANTEES".  TO THE EXTENT THAT SUCH CONSUMER GUARANTEES CONTINUE TO APPLY, THEN TO THE FULL EXTENT PERMITTED BY THE APPLICABLE LEGISLATION, THE LIABILITY OF CSIRO UNDER THE RELEVANT CONSUMER GUARANTEE IS LIMITED (WHERE PERMITTED AT CSIRO'S OPTION) TO ONE OF FOLLOWING REMEDIES OR SUBSTANTIALLY EQUIVALENT REMEDIES:
 * (a)               THE REPLACEMENT OF THE SOFTWARE, THE SUPPLY OF EQUIVALENT SOFTWARE, OR SUPPLYING RELEVANT SERVICES AGAIN;
 * (b)               THE REPAIR OF THE SOFTWARE;
 * (c)               THE PAYMENT OF THE COST OF REPLACING THE SOFTWARE, OF ACQUIRING EQUIVALENT SOFTWARE, HAVING THE RELEVANT SERVICES SUPPLIED AGAIN, OR HAVING THE SOFTWARE REPAIRED.
 * IN THIS CLAUSE, CSIRO INCLUDES ANY THIRD PARTY AUTHOR OR OWNER OF ANY PART OF THE SOFTWARE OR MATERIAL DISTRIBUTED WITH IT.  CSIRO MAY ENFORCE ANY RIGHTS ON BEHALF OF THE RELEVANT THIRD PARTY.
 * Third Party Components
 * The following third party components are distributed with the Software.  You agree to comply with the licence terms for these components as part of accessing the Software.  Other third party software may also be identified in separate files distributed with the Software.
 *
 *
 *
 */

namespace AEHRC\FhirOntologyAutocompleteExternalModule;

/**
 * Pure decision logic for outbound FHIR requests. Deliberately free of I/O,
 * REDCap and the External Modules framework, so it can be exercised by
 * tests/run.php without a REDCap instance - the module has no other
 * automated verification.
 *
 * The module keeps the settings reads and writes; this class only decides.
 */
class FhirRequestPolicy
{
    /** Fallback timeout (seconds) used when the 'fhir_timeout' setting is blank or invalid. */
    const DEFAULT_TIMEOUT = 10;
    /** Consecutive failures required before the circuit breaker opens. */
    const BREAKER_FAILURE_THRESHOLD = 3;
    /** How long (seconds) the breaker stays open before allowing a trial request. */
    const BREAKER_OPEN_SECONDS = 60;
    /**
     * Fraction of the timeout a call must consume before it is treated as evidence
     * of server health. A fast rejection (e.g. a malformed valueset url returning
     * 4xx) must not trip a system-wide breaker.
     */
    const SLOW_CALL_RATIO = 0.8;

    public static function resolveTimeout($setting, $default = self::DEFAULT_TIMEOUT)
    {
        if (is_numeric($setting) && (int)$setting > 0) {
            return (int)$setting;
        }
        return $default;
    }

    public static function countsAsFailure($elapsedSeconds, $timeout)
    {
        return $elapsedSeconds >= (self::SLOW_CALL_RATIO * $timeout);
    }

    /** True while the breaker is open and callers should fail fast. */
    public static function isOpen($openUntil, $now)
    {
        return (int)$openUntil > 0 && $now < (int)$openUntil;
    }

    /** True when a window was set but has elapsed, so the caller should re-arm and probe. */
    public static function needsRearm($openUntil, $now)
    {
        return (int)$openUntil > 0 && $now >= (int)$openUntil;
    }

    public static function nextFailureCount($current)
    {
        return (int)$current + 1;
    }

    public static function opensBreaker($failureCount, $threshold = self::BREAKER_FAILURE_THRESHOLD)
    {
        return (int)$failureCount >= $threshold;
    }

    /**
     * True when $url addresses the same origin as $baseUri, sits at or below its
     * path, carries no embedded credentials other than a copy of the base's own,
     * and contains no dot-segment traversal. Every outbound request is checked
     * against the configured FHIR server so that a malformed or hostile setting
     * cannot turn the module into a proxy for arbitrary hosts on the REDCap
     * server's network.
     *
     * Specifically: scheme, host and port must match; the path must be the base
     * or a descendant; and dot-segment traversal (., .., etc.) is rejected in
     * literal, percent-encoded, double-encoded, backslash-separated, and
     * ;-parameter-stripped forms. The path is inspected after iterative percent-
     * decoding (up to 5 iterations), backslash normalisation, and parameter
     * stripping, to catch multiple encoding layers and obfuscation techniques.
     *
     * The guarantee is specific to dot-segment traversal and the path component.
     * Query strings are not inspected. Encoding techniques that modern servers
     * reject per the Unicode spec (e.g. overlong UTF-8 sequences) are not blocked
     * — the module constructs every outbound path from fixed components so such
     * sequences never appear in practice.
     */
    public static function isWithinBase($url, $baseUri)
    {
        if (!is_string($url) || !is_string($baseUri) || '' === $url || '' === $baseUri) {
            return false;
        }
        $u = parse_url($url);
        $b = parse_url($baseUri);
        if (!is_array($u) || !is_array($b)) {
            return false;
        }
        // Credentials in the URL can disguise the host, so they are rejected unless
        // they exactly match the userinfo already configured on the base itself -
        // otherwise a site that legitimately configures fhir_api_url as
        // https://user:pass@host/fhir would have every request rejected, failing
        // silently with an empty result rather than the useful error this PR is
        // trying to surface elsewhere.
        $uUser = isset($u['user']) ? $u['user'] : null;
        $uPass = isset($u['pass']) ? $u['pass'] : null;
        if (null !== $uUser || null !== $uPass) {
            $bUser = isset($b['user']) ? $b['user'] : null;
            $bPass = isset($b['pass']) ? $b['pass'] : null;
            if ($uUser !== $bUser || $uPass !== $bPass) {
                return false;
            }
        }
        foreach (array('scheme', 'host') as $part) {
            if (!isset($u[$part]) || !isset($b[$part])) {
                return false;
            }
            if (strtolower($u[$part]) !== strtolower($b[$part])) {
                return false;
            }
        }
        if (self::port($u) !== self::port($b)) {
            return false;
        }
        $urlPath = isset($u['path']) ? $u['path'] : '/';
        $basePath = isset($b['path']) ? rtrim($b['path'], '/') : '';

        // Reject paths containing dot segments (literal or percent-encoded).
        if (self::containsDotSegment($urlPath)) {
            return false;
        }

        if ('' === $basePath) {
            return true;
        }
        // Exact match, or a descendant - "/fhirX" must not pass against base "/fhir".
        return $urlPath === $basePath || 0 === strpos($urlPath, $basePath . '/');
    }

    private static function port($parts)
    {
        if (isset($parts['port'])) {
            return (int)$parts['port'];
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ('https' === $scheme) {
            return 443;
        }
        if ('http' === $scheme) {
            return 80;
        }
        return 0;
    }

    /**
     * Returns true if $path contains any dot segment (. or .. or longer all-dot
     * sequences) either literal, percent-encoded, or hidden behind path parameters
     * and backslash separators. The check decodes all percent escapes iteratively
     * (up to 5 times to prevent loops), normalizes backslashes to forward slashes,
     * and strips path parameters (;-delimited) before checking segment names.
     */
    private static function containsDotSegment($path)
    {
        // Iteratively decode all percent escapes to handle multi-encoded forms,
        // up to 5 iterations to prevent infinite loops.
        for ($i = 0; $i < 5; $i++) {
            $before = $path;
            $path = rawurldecode($path);
            if ($path === $before) {
                break; // No more decoding possible.
            }
        }

        // Normalize backslashes to forward slashes so they are treated as path separators.
        $path = str_replace('\\', '/', $path);

        // Split by / and check each segment.
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            // Strip path parameters (everything after the first ;).
            $paramPos = strpos($segment, ';');
            if (false !== $paramPos) {
                $segment = substr($segment, 0, $paramPos);
            }

            // Reject segments that consist entirely of dots (., .., ..., etc).
            if ('' !== $segment && strlen($segment) === substr_count($segment, '.')) {
                return true;
            }
        }
        return false;
    }
}
