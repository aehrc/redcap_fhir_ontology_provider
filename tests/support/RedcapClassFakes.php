<?php

/**
 * Test-only fakes for the REDCap External Modules framework and core classes
 * this module actually calls, with real (if minimal) behavior - unlike
 * stubs/redcap-em-framework.phpstub, which is signatures-only for Psalm.
 * These must never be loaded outside tests: they're deliberately simplistic
 * (in-memory arrays, no validation) and would be actively wrong as production
 * code.
 *
 * Only models what FhirOntologyAutocompleteExternalModule.php actually uses:
 * getSystemSetting()/setSystemSetting() (no project- or sub-settings - this
 * module declares none), getUrl(), REDCap::getDataDictionary() (with a call
 * counter so getHideChoice()'s $Proj fast path can be told apart from the
 * full-dictionary-reload fallback), OntologyManager, OntologyProvider, and
 * Project (the global $Proj).
 */

namespace ExternalModules {
    abstract class AbstractExternalModule
    {
        /** @var array<string, mixed> system-scoped module settings, keyed by setting key */
        public array $systemSettings = [];

        public function __construct() {}

        public function getSystemSetting($key)
        {
            return $this->systemSettings[$key] ?? null;
        }

        public function setSystemSetting($key, $value)
        {
            $this->systemSettings[$key] = $value;
        }

        /** Real getCSRFToken() (from the Framework class) issues/returns a real
         *  double-submit-cookie CSRF token - this fake just needs to be a fixed,
         *  distinctive string tests can assert on. */
        public function getCSRFToken()
        {
            return 'FAKE_CSRF_TOKEN';
        }

        /** Real getUrl() returns a webroot path plus a cache-busting
         *  ?filemtime for a resource file - this fake just echoes the path
         *  distinctively enough for tests to assert on. */
        public function getUrl($path, $noAuth = false, $abs = false)
        {
            return 'FAKE_MODULE_URL/' . $path;
        }
    }

    class ExternalModules {}
}

namespace {

    class REDCap
    {
        /** @var int Call counter so tests can assert getHideChoice()'s $Proj
         *  fast path avoided this full-dictionary-reload path. */
        public static int $getDataDictionaryCallCount = 0;

        /** @var array<string, array<string, mixed>> canned per-field metadata this
         *  fake returns, keyed by field name - set by a test before calling. */
        public static array $dataDictionary = [];

        public static function getDataDictionary($project_id, $format = 'array', $numeric = false, $fields = null, $forms = null)
        {
            self::$getDataDictionaryCallCount++;
            return self::$dataDictionary;
        }

        public static function resetForTests(): void
        {
            self::$getDataDictionaryCallCount = 0;
            self::$dataDictionary = [];
        }
    }

    interface OntologyProvider
    {
        public function searchOntology($category, $search_term, $result_limit);
        public function getServicePrefix();
        public function getProviderName();
        public function getOnlineDesignerSection();
        public function getLabelForValue($category, $value);
    }

    class OntologyManager
    {
        private static ?OntologyManager $instance = null;

        /** @var list<object> */
        public array $providers = [];

        public static function getOntologyManager(): self
        {
            return self::$instance ??= new self();
        }

        public function addProvider($provider): void
        {
            $this->providers[] = $provider;
        }

        /** Test-only: the real framework has no equivalent - this is a process-wide
         *  singleton, so without a reset every test's module registers into the
         *  same $providers list for the lifetime of the PHPUnit run. */
        public static function resetForTests(): void
        {
            self::$instance = null;
        }
    }

    class Project
    {
        public $project_id;
        public $metadata = [];
    }
}
