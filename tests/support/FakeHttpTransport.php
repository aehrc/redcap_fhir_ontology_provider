<?php

namespace AEHRC\FhirOntologyAutocompleteExternalModule {

    /**
     * Records every outbound request the module makes and returns a
     * configurable canned response - so a test can assert both what was sent
     * (e.g. the timeout, headers, or URL actually threaded through) and control
     * what comes back. Unit-test only - see tests/system/RealHttpFunctions.php
     * for the system tests' equivalent, which makes real network calls instead.
     *
     * The module makes its own curl calls directly (curlGetWithTotalTimeout()/
     * curlPostWithTotalTimeout()) rather than delegating to REDCap core's
     * http_get()/http_post(), specifically so it can set CURLOPT_TIMEOUT - core's
     * own helpers only ever set the connect timeout. So this fakes the raw
     * curl_* functions themselves, not http_get()/http_post().
     */
    class FakeHttpTransport
    {
        /** @var list<array<string, mixed>> */
        public static array $calls = [];

        /** @var string|false */
        public static $response = false;

        public static function reset(): void
        {
            self::$calls = [];
            self::$response = false;
        }
    }

    /** A fake curl "handle" - just an object collecting every curl_setopt() call. */
    class FakeCurlHandle
    {
        /** @var array<int, mixed> */
        public array $options = [];
    }

    // Namespaced function fallback: PHP looks for a function in the CURRENT
    // namespace before falling back to the global one, so defining these here
    // shadows the real curl_*()/sameHostUrl() for this module's code
    // specifically, without touching production code at all. function_exists()
    // is unaffected by this (it always resolves against the global namespace
    // when given a plain string), so the module's own function_exists('curl_init')
    // check still sees whatever is really installed - these fakes only intercept
    // the module's own unqualified curl_init(...) etc. call sites.

    function curl_init($url = null)
    {
        $handle = new FakeCurlHandle();
        if (null !== $url) {
            $handle->options[CURLOPT_URL] = $url;
        }
        return $handle;
    }

    function curl_setopt($handle, $option, $value)
    {
        $handle->options[$option] = $value;
        return true;
    }

    function curl_exec($handle)
    {
        $isPost = array_key_exists(CURLOPT_POSTFIELDS, $handle->options);
        recordCall($handle, $isPost);
        return FakeHttpTransport::$response;
    }

    function curl_getinfo($handle, $opt = null)
    {
        // Always reports success (200) - production code's own 404/407/5xx
        // branching mirrors REDCap core's http_get()/http_post(), it isn't this
        // module's logic to test. Set FakeHttpTransport::$response = false to
        // simulate a failed call instead (matches the old http_get()/http_post()
        // fakes' behavior exactly, so no other test needed to change for this).
        return ['http_code' => 200];
    }

    function curl_close($handle)
    {
    }

    function sameHostUrl($url)
    {
        return true;
    }

    function recordCall($handle, $isPost)
    {
        FakeHttpTransport::$calls[] = [
            'method' => $isPost ? 'POST' : 'GET',
            'url' => $handle->options[CURLOPT_URL] ?? null,
            'timeout' => $handle->options[CURLOPT_CONNECTTIMEOUT] ?? null,
            'total_timeout' => $handle->options[CURLOPT_TIMEOUT] ?? null,
            'headers' => $handle->options[CURLOPT_HTTPHEADER] ?? [],
            'params' => $handle->options[CURLOPT_POSTFIELDS] ?? null,
        ];
    }
}
