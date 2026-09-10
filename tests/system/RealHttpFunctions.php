<?php

namespace AEHRC\FhirOntologyAutocompleteExternalModule {

    /**
     * Support functions for the system tests, which make real outbound HTTPS
     * requests to the public FHIR servers under test rather than going through
     * the unit suite's FakeHttpTransport.
     *
     * The module's own httpGet()/httpPost() make their outbound requests via the
     * raw curl_*() functions directly (see curlGetWithTotalTimeout()/
     * curlPostWithTotalTimeout() - added so the module can set CURLOPT_TIMEOUT,
     * which REDCap core's own http_get()/http_post() never do), not via calls to
     * a function named http_get()/http_post(). Because curl_*() itself is never
     * shadowed here, those calls already reach the real network unchanged - this
     * file has nothing to intercept for them and defines no http_get()/
     * http_post() of its own. What it still provides:
     *  - usingRealHttpTransport(): the "was the right bootstrap loaded" marker
     *    PublicFhirServerTest's setUp() checks for.
     *  - sameHostUrl(): shadows core's real implementation (which the module's
     *    curl helpers call to decide whether to route through a proxy) so the
     *    system tests never depend on PROXY_HOSTNAME/PROXY_USERNAME_PASSWORD
     *    being configured.
     */
    function usingRealHttpTransport()
    {
        return true;
    }

    function sameHostUrl($url)
    {
        return true;
    }
}
