<?php

// Separate from tests/bootstrap.php deliberately: PHPUnit's <bootstrap> is one
// script for the whole run, and unit vs system tests need different curl_*()/
// sameHostUrl() (faked vs real) - hence also the separate phpunit.system.xml.

$_SESSION = $_SESSION ?? [];

require_once __DIR__ . '/../support/RedcapClassFakes.php';
require_once __DIR__ . '/RealHttpFunctions.php';
require_once __DIR__ . '/../../FhirRequestPolicy.php';
require_once __DIR__ . '/../../FhirOntologyAutocompleteExternalModule.php';
