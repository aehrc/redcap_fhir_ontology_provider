<?php

namespace AEHRC\FhirOntologyAutocompleteExternalModule {

    /**
     * Records every http_get()/http_post() call the module makes and returns a
     * configurable canned response - so a test can assert both what was sent
     * (e.g. the timeout or the URL actually threaded through) and control what
     * comes back. Unit-test only - see tests/system/RealHttpFunctions.php for
     * the system tests' equivalent, which makes real network calls instead.
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

    // Namespaced function fallback: PHP looks for a function in the CURRENT
    // namespace before falling back to the global one, so defining these here
    // shadows the real http_get()/http_post()/sameHostUrl() for this module's
    // code specifically, without touching production code at all.

    function http_get($url, $timeout = null, $basic_auth_user_pass = '', $headers = [], $user_agent = null)
    {
        FakeHttpTransport::$calls[] = ['method' => 'GET', 'url' => $url, 'timeout' => $timeout, 'headers' => $headers];
        return FakeHttpTransport::$response;
    }

    function http_post($url, $params = [], $timeout = null, $content_type = 'application/x-www-form-urlencoded', $basic_auth_user_pass = '', $headers = [], &$info = null)
    {
        FakeHttpTransport::$calls[] = ['method' => 'POST', 'url' => $url, 'timeout' => $timeout, 'headers' => $headers, 'params' => $params];
        return FakeHttpTransport::$response;
    }

    function sameHostUrl($url)
    {
        return true;
    }
}
