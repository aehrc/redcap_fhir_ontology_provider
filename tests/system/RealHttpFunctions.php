<?php

namespace AEHRC\FhirOntologyAutocompleteExternalModule {

    /**
     * Real, curl-based http_get()/http_post()/sameHostUrl() for the system tests -
     * these make actual outbound HTTPS requests to the public FHIR servers under
     * test. Deliberately minimal (no proxy support, no file_get_contents fallback):
     * good enough to exercise this module's real integration against a real server,
     * not a faithful reimplementation of REDCap core's http_get()/http_post().
     *
     * Defined in this namespace so the module's unqualified http_get()/http_post()
     * calls resolve here via PHP's namespaced-function-fallback - the same
     * mechanism the unit tests' FakeHttpTransport relies on, just with a real
     * implementation instead of a fake one.
     */

    /**
     * Dedicated marker for the "was the right bootstrap loaded" guard -
     * deliberately not piggybacking on realHttpTimeoutSeconds() or any other
     * helper below, so refactoring this file's internals can never silently
     * break that guard.
     */
    function usingRealHttpTransport()
    {
        return true;
    }

    function realHttpTimeoutSeconds($timeout)
    {
        $seconds = is_numeric($timeout) ? (int)$timeout : 30;
        // 0 (or negative) reaching curl means "never time out" - the opposite of
        // what a bounded, non-blocking system-test job needs.
        return $seconds > 0 ? $seconds : 30;
    }

    function http_get($url, $timeout = null, $basic_auth_user_pass = '', $headers = [], $user_agent = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $seconds = realHttpTimeoutSeconds($timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $seconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $seconds);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($basic_auth_user_pass !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $basic_auth_user_pass);
        }
        if ($user_agent !== null) {
            curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
        }
        $response = curl_exec($ch);
        // A non-2xx response (error page, OperationOutcome, rate-limiting) is not
        // a curl-level failure - curl_errno stays 0 - but it's not usable data
        // either. Treating it as success would let searchOntology()/findValueSet()
        // silently return an empty/failed result with no signal of *why*,
        // indistinguishable from a real regression or a genuinely empty match set.
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = curl_errno($ch) !== 0 || $httpCode < 200 || $httpCode >= 300;
        curl_close($ch);
        return $failed ? false : $response;
    }

    function http_post($url, $params = [], $timeout = null, $content_type = 'application/x-www-form-urlencoded', $basic_auth_user_pass = '', $headers = [], &$info = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($params)) {
            $body = $content_type === 'application/json' ? json_encode($params) : http_build_query($params);
        } else {
            $body = $params;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $seconds = realHttpTimeoutSeconds($timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $seconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $seconds);
        $fullHeaders = $headers;
        $hasContentType = false;
        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType) {
            $fullHeaders[] = 'Content-Type: ' . $content_type;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $fullHeaders);
        if ($basic_auth_user_pass !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $basic_auth_user_pass);
        }
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $httpCode = (int)($info['http_code'] ?? 0);
        $failed = curl_errno($ch) !== 0 || $httpCode < 200 || $httpCode >= 300;
        curl_close($ch);
        return $failed ? false : $response;
    }

    function sameHostUrl($url)
    {
        return true;
    }
}
