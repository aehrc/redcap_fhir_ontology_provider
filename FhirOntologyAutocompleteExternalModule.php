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

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

require_once __DIR__ . '/FhirRequestPolicy.php';


class FhirOntologyAutocompleteExternalModule extends AbstractExternalModule implements \OntologyProvider
{
    public function __construct()
    {
        parent::__construct();
        // register with OntologyManager
        $manager = \OntologyManager::getOntologyManager();
        $manager->addProvider($this);
    }

    public function redcap_every_page_before_render($project_id)
    {
        // don't need to do anything, just trigger the constructor so the provider is available.
    }


    public function redcap_data_entry_form($project_id, $record,
                                           $instrument, $event_id, $group_id, $repeat_instance)
    {

        if ($this->getSystemSetting('add_value_tooltip')) {
            print('<script src="' . $this->getUrl('js/value-tooltip.js') . '"></script>');
        }
    }


    public function redcap_survey_page($project_id, $record,
                                       $instrument, $event_id, $group_id, $survey_hash, $response_id,
                                       $repeat_instance)
    {

        if ($this->getSystemSetting('add_value_tooltip')) {
            print('<script src="' . $this->getUrl('js/value-tooltip.js') . '"></script>');
        }
    }

    /**
     * Backs the Online Designer's ontology-picker JS (getOnlineDesignerSection()),
     * called via the JavaScript Module Object's ajax() method - see config.json's
     * auth-ajax-actions. This is the only caller for both actions; there is no
     * public REDCap-facing API contract here to keep stable beyond that.
     *
     * A returned array becomes a normal resolved payload on the JS side, not a
     * rejection - including the {error: "..."} shape, which is how a domain-level
     * failure (breaker open, transport failure, malformed FHIR response) is
     * signalled to the two .then() handlers in getOnlineDesignerSection(). A
     * thrown exception (none here) would instead reject the JS promise.
     */
    public function redcap_module_ajax($action, $payload, $project_id)
    {
        $payload = is_array($payload) ? $payload : [];

        if ($action === 'find-valueset') {
            $type = isset($payload['type']) ? $payload['type'] : null;
            $query = isset($payload['query']) ? $payload['query'] : null;
            if ($type === null || $query === null) {
                return ['error' => 'Missing required parameter "type" or "query".'];
            }
            if (!$this->isValidValuesetQueryType($type)) {
                return ['error' => "Unknown find type '{$type}'."];
            }
            return $this->findValueSet($type, $query);
        }

        if ($action === 'get-valueset-info') {
            $valueSet = isset($payload['valueSet']) ? $payload['valueSet'] : null;
            if ($valueSet === null) {
                return ['error' => 'Missing required parameter "valueSet".'];
            }
            $info = $this->getValueSetInfo($valueSet);
            if ($info === false) {
                // Breaker open, or the server did not answer.
                return ['error' => 'The terminology server is not responding. Please try again shortly.'];
            }
            $decoded = json_decode($info, true);
            if (!is_array($decoded)) {
                return ['error' => 'The terminology server returned an unparseable response.'];
            }
            return $decoded;
        }

        return ['error' => 'Unknown action.'];
    }


    public function validateSettings($settings)
    {
        // Every setting this module declares is system-level (config.json has no
        // "project-settings" key at all), so a project-scope Configure dialog save
        // calls this with none of them present in $settings. Previously this fell
        // through to an unconditional httpGet($settings['fhir_api_url'] . '/metadata', ...)
        // with an undefined/empty fhir_api_url, which always failed and surfaced
        // "Failed to get metadata for fhir server at ''" on every project-level
        // save - there is nothing to validate at that scope, so return early.
        if (!array_key_exists('fhir_api_url', $settings)) {
            return '';
        }

        $errors = '';

        $rnr = $settings['return_no_result'];
        $label = trim($settings['no_result_label']);
        $code = trim($settings['no_result_code']);

        if ($rnr) {
            // check we have a code and label
            if ($label === '') {
                $errors .= "No Result Label is required\n";
            } else if ($label != strip_tags($label)) {
                $errors .= "No Results Label has illegal characters - " . $label . "\n";
            }

            if ($code === '') {
                $errors .= "No Result Code is required\n";
            } else if ($code != strip_tags($code)
                || strpos($code, "'") !== false
                || strpos($code, '"') !== false
            ) {
                $errors .= "No Results Code has illegal characters - " . $code . "\n";
            }
        }

        $fhirUrl = $settings['fhir_api_url'];
        if ($fhirUrl) {
            $strlen = strlen($fhirUrl);
            if ('/' === $fhirUrl[$strlen - 1]) {
                // remove trailing /
                $fhirUrl = substr($fhirUrl, 0, $strlen - 1);
            }
        }
        $headers = ['User-Agent: Redcap'];
        $authType = $settings['authentication_type'];
        if ($authType === 'basic') {
            $authUser = $settings['basic_user_id'];
            $authPassword = $settings['basic_user_password'];
            $headers[] = 'Authorization: Basic ' . base64_encode($authUser . ':' . $authPassword);
        }
        $metadata = $this->httpGet($fhirUrl . '/metadata', $headers, $fhirUrl);
        if ($metadata === FALSE) {
            $errors .= "Failed to get metadata for fhir server at '" . $fhirUrl . "'\n";
        }
        if ($authType === 'cc') {
            $authEndpoint = $settings['cc_token_endpoint'];
            $clientId = $settings['cc_client_id'];
            $clientSecret = $settings['cc_client_secret'];

            // get the access token
            $params = array(
                'grant_type' => 'client_credentials'
            );
            $headers[] = 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret);

            try {
                // $authEndpoint is passed as both the URL and its own base, so
                // isWithinBase($authEndpoint, $authEndpoint) is trivially true here.
                // That is intentional: it still validates well-formedness (scheme
                // and host present, no dot-segment traversal, no stray credentials)
                // on a value an admin just typed, but it is not a containment
                // boundary check the way the other call sites use $baseOverride.
                $response = $this->httpPost($authEndpoint, $params, 'application/x-www-form-urlencoded', $headers, $authEndpoint);
                if ($response === false) {
                    // $http_response_header is populated inside httpPost()'s own scope, not
                    // here, so it is never available at this call site - do not promise a
                    // response body this diagnostic can never show.
                    $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "' - request failed or was refused\n";
                } else {
                    // a false or unparseable response decodes to null, and array_key_exists(null)
                    // is a fatal TypeError on PHP 8
                    $responseJson = is_string($response) ? json_decode($response, true) : null;
                    if (!is_array($responseJson)) {
                        $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "' - no parseable response\n";
                    } else if (!array_key_exists('access_token', $responseJson)) {
                        $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "'$response\n";
                    }
                }
            } catch (\Exception $e) {
                $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "' got exception $e\n";
            }
        }
        return $errors;
    }


    /**
     * return the name of the ontology service as it will be display on the service selection
     * drop down.
     */
    public function getProviderName()
    {
        return 'FHIR Ontologies';
    }


    /**
     * return the prefex used to denote ontologies provided by this provider.
     */
    public function getServicePrefix()
    {
        return 'FHIR';
    }

    public function getFhirServerUri()
    {
        $ontologyServer = $this->getSystemSetting('fhir_api_url');
        if ($ontologyServer) {
            $strlen = strlen($ontologyServer);
            if ('/' === $ontologyServer[$strlen - 1]) {
                // remove trailing /
                $ontologyServer = substr($ontologyServer, 0, $strlen - 1);
            }
        }
        return $ontologyServer;
    }

    public function hasSnomedSupport()
    {
        return $this->getSystemSetting('snomed_support');
    }

    public function hasLoincSupport()
    {
        return $this->getSystemSetting('loinc_support');
    }


    /**
     * Search API with a search term for a given ontology
     * Returns array of results with Notation as key and PrefLabel as value.
     */
    public function searchOntology($valueset_id, $search_term, $result_limit)
    {
        $fhir_server_uri = $this->getFhirServerUri();
        // Set 20 as default limit
        $result_limit = (is_numeric($result_limit) ? $result_limit : 20);

        $searchOptions = $this->getSearchOptions();
        $priorityCodes = $searchOptions['priority-codes'];

        $fetchLimit = $result_limit;
        if (!empty($priorityCodes)) {
            // Extra headroom so every listed priority code has a chance to appear
            // in the response before final truncation - mirrors
            // advanced_fhir_ontology_provider's priority-max-fetch, but sized
            // automatically from the priority list's own length rather than as
            // a second, separately-tunable option.
            $fetchLimit += count($priorityCodes);
        }

        // Build URL to call
        //  Base URL + “/ValueSet/$expand?identifier=VS_ID&filter=SEARCH_TERM”
        // need to escape the $expand in the url!
        $expandParams = array(
            'url' => $valueset_id,
            'count' => $fetchLimit
        );
        if (!$searchOptions['return-all']) {
            $expandParams['filter'] = $search_term;
        }
        $url = $fhir_server_uri . "/ValueSet/\$expand?" . http_build_query($expandParams);
        // Call the URL

        $fhirFailed = false;
        if ($this->isCircuitOpen()) {
            // Server has failed repeatedly - fail fast rather than tying up a web
            // server process on a request we already expect to time out.
            $json = false;
            $fhirFailed = true;
        }
        else {
            $headers = ['User-Agent: Redcap'];
            $authHeader = $this->getAuthHeader();
            if ($authHeader !== false) {
                $headers[] = $authHeader;
            }
            $startedAt = microtime(true);
            $json = $this->httpGet($url, $headers);
            if ($json === false) {
                $fhirFailed = true;
                $this->recordFhirFailureIfSlow(microtime(true) - $startedAt);
            }
            else {
                $this->recordFhirSuccess();
            }
        }
        // Parse the JSON into an array
        $list = is_string($json) ? json_decode($json, true) : null;
        $entries = array();
        if (is_array($list) && isset($list['expansion']['contains'])) {
            $expansion = $list['expansion'];
            // Loop through results
            $hideChoice = $this->getHideChoice();
            foreach ($expansion['contains'] as $this_item) {

                // code and system are not guaranteed present by FHIR
                $code = isset($this_item['code']) ? $this_item['code'] : '';
                $system = isset($this_item['system']) ? $this_item['system'] : '';
                if ('' === $code) {
                    // nothing storable without a code - skip rather than emitting "|system"
                    continue;
                }
                if (in_array($code, $hideChoice)){
                    // in hide choice list
                    continue;
                }
                $display = isset($this_item['display']) ? $this_item['display'] : $code;
                $entries[] = array('code' => $code, 'system' => $system, 'display' => $display);
            }
        }

        if (!empty($entries) && ($searchOptions['return-all'] || !empty($priorityCodes))) {
            $entries = $this->rankSearchEntries($entries, $search_term, $searchOptions);
        }

        // need to add the system as codes are not unique in SCT - ${CODE}|${SYSTEM}
        // is the default when no @ONTOLOGY-OPTIONS code-template is set, exactly
        // matching this method's previous unconditional $code . "|" . $system.
        $codeTemplate = $searchOptions['code-template'] !== null ? $searchOptions['code-template'] : '${CODE}|${SYSTEM}';
        $results = array();
        foreach ($entries as $entry) {
            $this_value = str_replace(['${CODE}', '${SYSTEM}'], [$entry['code'], $entry['system']], $codeTemplate);
            $results[$this_value] = $entry['display'];
        }

        if (!$results && !$fhirFailed) {
            // no results found
            $return_no_result = $this->getSystemSetting('return_no_result');
            if ($return_no_result) {
                $no_result_label = $this->getSystemSetting('no_result_label');
                $no_result_code = $this->getSystemSetting('no_result_code');
                $results[$no_result_code] = $no_result_label;
            }
        }
        // Return array of results
        return array_slice($results, 0, $result_limit, true);
    }

    /**
     * Reorders $entries (each ['code'=>..., 'system'=>..., 'display'=>...])
     * per the field's @ONTOLOGY-OPTIONS: priority-codes sorts first (in the
     * order listed, code only - not system, matching
     * advanced_fhir_ontology_provider's exact precedent), then, among the
     * rest, return-all's match-ranking (code/display case-insensitively
     * containing $search_term sorts before non-matches). Both keys use a
     * stable sort (array_multisort, guaranteed stable since PHP 8.0), so ties
     * keep their original relative (server-returned) order.
     *
     * Match-ranking is skipped when $search_term is empty (nothing to rank
     * by) - this only affects the match key, not priority ranking, so
     * priority-codes still works even if this method is ever called with an
     * empty term.
     */
    private function rankSearchEntries($entries, $search_term, $searchOptions)
    {
        $priorityCodes = $searchOptions['priority-codes'];
        $priorityCount = count($priorityCodes);
        $applyMatchRanking = $searchOptions['return-all'] && '' !== trim((string)$search_term);

        $priorityKeys = array();
        $matchKeys = array();
        foreach ($entries as $entry) {
            $priorityRank = array_search($entry['code'], $priorityCodes, true);
            $priorityKeys[] = ($priorityRank === false) ? $priorityCount : $priorityRank;

            if ($applyMatchRanking) {
                $isMatch = (false !== stripos($entry['code'], $search_term))
                    || (false !== stripos($entry['display'], $search_term));
                $matchKeys[] = $isMatch ? 0 : 1;
            } else {
                $matchKeys[] = 0;
            }
        }

        array_multisort($priorityKeys, SORT_ASC, $matchKeys, SORT_ASC, $entries);
        return $entries;
    }

    /**
     * Returns the field currently being searched's raw field_annotation string,
     * or null if there isn't one (or no field is being searched at all). Shared
     * by getHideChoice() and getSearchOptions() - both need "what does this
     * field's annotation say", just looking for different tags within it.
     */
    private function getFieldAnnotation()
    {
        // $Proj must be pulled in explicitly. Without this it is always null inside
        // the method, so the in-memory fast path below never runs and every single
        // keystroke falls through to a full getDataDictionary() call.
        global $Proj;

        if (!isset($_GET['field'])) {
            return null;
        }
        $field = $_GET['field'];
        $project_id = isset($_GET['pid']) ? $_GET['pid'] : null;

        if (($project_id === null || (isset($Proj->project_id) && (string)$Proj->project_id === (string)$project_id))
                && isset($Proj->metadata[$field])) {
            // field_annotation is NULL for un-annotated fields, which is the common
            // case - take the in-memory path on field presence, not on the annotation
            // existing, or every un-annotated field falls back to a full dictionary load
            return isset($Proj->metadata[$field]['field_annotation'])
                ? $Proj->metadata[$field]['field_annotation']
                : null;
        }
        if ($project_id !== null){
            $dd_array = \REDCap::getDataDictionary($project_id, 'array', false, array($field));
            return isset($dd_array[$field]['field_annotation'])
                ? $dd_array[$field]['field_annotation']
                : null;
        }
        return null;
    }

    function getHideChoice()
    {
        $codesToHide=[];
        $annotations = $this->getFieldAnnotation();
        if ($annotations) {
            $offset = 0;
            while (preg_match("/@HIDECHOICE='([^']*)'/", $annotations, $matches, PREG_OFFSET_CAPTURE, $offset) === 1){
                $listedCodesStr = $matches[1][0];
                $listedCodes = explode(',', $listedCodesStr);
                foreach($listedCodes as $code){
                    array_push($codesToHide, trim($code));
                }
                $offset = $matches[0][1] + strlen($matches[0][0]);
            }
        }

        return $codesToHide;
    }

    /**
     * Parses @ONTOLOGY-OPTIONS='...' from the current field's annotation into
     * ['return-all' => bool, 'code-template' => string|null, 'priority-codes' => string[]].
     *
     * Options are semicolon-separated, not comma-separated like @HIDECHOICE -
     * priority-codes needs its own comma-separated list of codes, and a plain
     * comma-separated option list would make "priority-codes=123,456" and
     * "return-all,priority-codes=123,456" ambiguous to split. Unrecognized
     * tokens (and unrecognized keys) are ignored, so a malformed tag degrades
     * to "no options applied" rather than an error, and a future option name
     * added here is forward-compatible with older deployments that don't
     * understand it yet.
     */
    function getSearchOptions()
    {
        $options = ['return-all' => false, 'code-template' => null, 'priority-codes' => []];
        $annotations = $this->getFieldAnnotation();
        if (!$annotations) {
            return $options;
        }
        $offset = 0;
        while (preg_match("/@ONTOLOGY-OPTIONS='([^']*)'/", $annotations, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $this->applySearchOptionTokens($matches[1][0], $options);
            $offset = $matches[0][1] + strlen($matches[0][0]);
        }
        return $options;
    }

    private function applySearchOptionTokens($tokenString, array &$options)
    {
        foreach (explode(';', $tokenString) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if ($token === 'return-all') {
                $options['return-all'] = true;
                continue;
            }
            $eqPos = strpos($token, '=');
            if ($eqPos === false) {
                continue; // unrecognized bare token - ignored
            }
            $key = trim(substr($token, 0, $eqPos));
            $value = substr($token, $eqPos + 1);
            if ($key === 'code-template') {
                $options['code-template'] = $value;
            } else if ($key === 'priority-codes') {
                foreach (explode(',', $value) as $code) {
                    $trimmed = trim($code);
                    if ($trimmed !== '') {
                        $options['priority-codes'][] = $trimmed;
                    }
                }
            }
            // unrecognized key - ignored
        }
    }


    /**
     * Return a string which will be placed in the online designer for
     * selecting an ontology for the service.
     * When an ontology is selected it should make a javascript call to
     * update_ontology_selection($service, $category)
     *
     * The provider may include a javascript function
     * <service>_ontology_changed(service, category)
     * which will be called when the ontology selection is changed. This function
     * would update any UI elements is the service matches or clear the UI elemements
     * if they do not.
     */
    public function getOnlineDesignerSection()
    {

        // initializeJavascriptModuleObject() prints (does not return) a script
        // block that sets up window.<jsObjectName>.ajax(), which POSTs to the
        // framework's own module-ajax endpoint with CSRF/verification handled
        // internally - this replaces both the hand-rolled $.ajax() calls this
        // method used to make directly to FindValueSetService.php (a plain
        // module page, which is why framework 16's CSRF requirement had to be
        // solved by hand here previously) and that file entirely; the same
        // logic now lives in redcap_module_ajax() below, reached via the
        // 'find-valueset'/'get-valueset-info' actions declared in config.json's
        // auth-ajax-actions. Captured via output buffering so it can be
        // returned as part of this method's own HTML fragment rather than
        // printed immediately (out of order relative to the rest of the page).
        ob_start();
        $this->initializeJavascriptModuleObject();
        $moduleObjectScript = ob_get_clean();
        // Crosses into js/online-designer.js via the wrapper div's data-module-object
        // attribute below, not string-interpolated into a script body - see that
        // file's own docblock for why (it's a real file now, not a PHP template).
        $jsObjectNameAttr = \REDCap::escapeHtml($this->getJavascriptModuleObjectName());

        $loincSupport = $this->hasLoincSupport();
        $implicitSearchOptions = '';
        if ($this->hasSnomedSupport()){
            $implicitSearchOptions = $implicitSearchOptions .
                '<option value="refset">SnomedCT Refset</option>\n' .
                '<option value="isa">SnomedCT isa implicit valueset</option>\n';
        }
        if ($loincSupport === "ontoserver" || $loincSupport === "filter"){
            $implicitSearchOptions = $implicitSearchOptions .
                '<option value="loinc_answer">LOINC implicit answer set</option>';
        }

        $onlineDesignerHtml = <<<EOD
<script src="{$this->getUrl('js/online-designer.js')}"></script>
<div id="fhir_ontology_designer_app" data-module-object="{$jsObjectNameAttr}" style='margin:0 0 2px;'>
  <input type="hidden" id="fhir_selected_valueset" value="">
  <span id="fhir_selected_valueset_label" style="color:#888;">No ValueSet selected</span>
  <a id="fhir_valueset_change" class="ui-button ui-widget ui-corner-all" href="#">Change...</a>

  <div id="fhir_valueset_dialog" title="Select FHIR ValueSet">
    <div>
      <label for="fhir_valueset_search_type">Search for ValueSet using:</label><br>
      <select id='fhir_valueset_search_type' name='fhir_valueset_search_type' class='x-form-text x-form-field'>
        <option value=""> -- choose search criteria -- </option>
        <option value="name">ValueSet Name</option>
        <option value="codesystem">By CodeSystem(Title)</option>
        {$implicitSearchOptions}
      </select>
      <div class="ui-front">
        <input id="fhir_valueset_search" class="x-form-text x-form-field" size="40">
      </div>
    </div>

    <hr style="border:none;border-top:1px solid #bbb;margin:14px 0;">

    <div>
      <label for="fhir_value_set_url">Or enter a ValueSet URL directly:</label><br>
      <input id="fhir_value_set_url" class="x-form-text x-form-field" size="40" type="text">
    </div>

    <hr style="border:none;border-top:1px solid #bbb;margin:14px 0;">

    <div>
      <div>
	    <label for="fhirValueSet_url">URL:</label>
	    <span id="fhirValueSet_url"></span>
	   </div>
      <div>
	    <label for="fhirValueSet_name">Name:</label>
	    <span id="fhirValueSet_name"></span>
	   </div>
      <div>
	    <label for="fhirValueSet_version">Version:</label>
	    <span id="fhirValueSet_version"></span>
	   </div>
      <div>
	    <label for="fhirValueSet_status">Status:</label>
	    <span id="fhirValueSet_status"></span>
	   </div>
      <div>
	    <label for="fhirValueSet_expansion_count">Expansion Count:</label>
	    <span id="fhirValueSet_expansion_count"></span>
	   </div>
      <div style="max-height:220px;overflow-y:auto;">
        <table class="table table-stripped">
			<thead>
			  <tr>
					<th class="col-sm-8">Display</th>
					<th class="col-sm-2">Code</th>
					<th class="col-sm-2">System</th>
			  </tr>
			</thead>
			<tbody id="fhirValueSet_contains">
			</tbody>
		 </table>
      </div>
    </div>

    <hr style="border:none;border-top:1px solid #bbb;margin:14px 0;">

    <div style="text-align:right;">
      <button type="button" id="fhir_valueset_apply" class="ui-button ui-widget ui-corner-all">Use this ValueSet</button>
      <button type="button" id="fhir_valueset_cancel" class="ui-button ui-widget ui-corner-all">Cancel</button>
    </div>
  </div>
</div>
EOD;
        return $moduleObjectScript . $onlineDesignerHtml;
    }


    public function getLabelForValue($category, $value)
    {
        return $value;
    }

    public function isValidValuesetQueryType($type)
    {
        $loincSupport = $this->hasLoincSupport();
        if ('name' === $type ||
            'codesystem' === $type ||
            (('refset' === $type || 'isa' === $type) && $this->hasSnomedSupport()) ||
            (('loinc_answer' === $type) && ('ontoserver' === $loincSupport || 'filter' == $loincSupport))) {
            return true;
        }
        return false;
    }

    public function findValueSet($type, $query)
    {
        $contentType = 'application/x-www-form-urlencoded';
        $snomedSupport = $this->hasSnomedSupport();
        $loincSupport = $this->hasLoincSupport();
        if ($type === 'name') {
            $method = "GET";
            $params = ['name' => $query, '_summary' => true, '_count' => 20];
            $url = "/ValueSet";
            $processFunction = function ($data) {
                $result = [];
                if (isset($data['entry'])) {
                    foreach ($data['entry'] as $this_entry) {
                        $result[] = ['label' => $this_entry['resource']['name'], 'value' => $this_entry['resource']['url']];
                    }
                }
                if (empty($result)) {
                    $result[] = ['label' => 'No matches found', 'value' => '__NMF__'];
                }

                return $result;
            };
        } elseif ($type === 'codesystem') {
            $method = "GET";
            $params = ['title' => $query, '_elements' => 'title,valueSet', '_count' => 20];
            $url = "/CodeSystem";
            $processFunction = function ($data) {
                $result = [];
                if (isset($data['entry'])) {
                    foreach ($data['entry'] as $this_entry) {
                        if (isset($this_entry['resource']['valueSet'])) {
                            $result[] = ['label' => $this_entry['resource']['title'], 'value' => $this_entry['resource']['valueSet']];
                        }
                    }
                }
                if (empty($result)) {
                    $result[] = ['label' => 'No matches found', 'value' => '__NMF__'];
                }

                return $result;
            };
        } elseif ($type === 'refset') {
            if (!$snomedSupport){
                return []; // snomed implicit valuesets not supported
            }
            $method = "GET";
            // $expand is a FHIR *operation*, not a plain resource search - its count
            // parameter is 'count', not the REST search modifier '_count'. Ontoserver
            // silently ignores an unrecognized parameter rather than rejecting the
            // request, so '_count' here had no effect at all (confirmed live: same
            // request with 'count' instead correctly limits the response).
            $params = ['filter' => $query, 'url' => 'http://snomed.info/sct?fhir_vs=refset', 'count' => 20];
            $url = '/ValueSet/$expand';
            $processFunction = function ($data) {
                $result = [];
                if (isset($data['expansion']) && isset($data['expansion']['contains'])) {
                    foreach ($data['expansion']['contains'] as $this_entry) {
                        $result[] = ['label' => $this_entry['display'], 'value' => 'http://snomed.info/sct?fhir_vs=refset/' . $this_entry['code']];
                    }
                }
                if (empty($result)) {
                    $result[] = ['label' => 'No matches found', 'value' => '__NMF__'];
                }

                return $result;
            };
        } elseif ($type === 'isa') {
            if (!$snomedSupport){
                return []; // snomed implicit valuesets not supported
            }
            $method = "GET";
            // 'count', not '_count' - see the 'refset' branch above for why.
            $params = ['filter' => $query, 'url' => 'http://snomed.info/sct?fhir_vs', 'count' => 20];
            $url = '/ValueSet/$expand';
            $processFunction = function ($data) {
                $result = [];
                if (isset($data['expansion']) && isset($data['expansion']['contains'])) {
                    foreach ($data['expansion']['contains'] as $this_entry) {
                        $result[] = ['label' => $this_entry['display'], 'value' => 'http://snomed.info/sct?fhir_vs=isa/' . $this_entry['code']];
                    }
                }
                if (empty($result)) {
                    $result[] = ['label' => 'No matches found', 'value' => '__NMF__'];
                }

                return $result;
            };
        } elseif ($type === 'loinc_answer') {
            if ('ontoserver' === $loincSupport){
                $method = "POST";
                $contentType = "application/json";
                $postData = [
                    "resourceType" => "Parameters",
                    "parameter" => [
                        ["name" => "filter", "valueString" => $query],
                        // 'count', not '_count' - see the 'refset' branch above for why.
                        ["name" => "count", "valueInteger" => 20],
                        ["name" => "valueSet",
                            "resource" => [
                                "resourceType" => "ValueSet",
                                "compose" => [
                                    "include" => [[
                                        "system" => "http://loinc.org",
                                        "filter" => [
                                            ["property" => "parent",
                                                "op" => "=",
                                                "value" => "LL"]
                                        ]
                                    ]]
                                ]
                            ]
                        ]
                    ]
                ];
                $postData = json_encode($postData, JSON_UNESCAPED_SLASHES);

                $url = '/ValueSet/$expand';
                $processFunction = function ($data) {
                    $result = [];
                    if (isset($data['expansion']) && isset($data['expansion']['contains'])) {
                        foreach ($data['expansion']['contains'] as $this_entry) {
                            $result[] = ['label' => $this_entry['display'], 'value' => 'http://loinc.org/vs/' . $this_entry['code']];
                        }
                    }
                    if (empty($result)) {
                        $result[] = ['label' => 'No matches found', 'value' => '__NMF__'];
                    }

                    return $result;
                };
            }
            elseif ('filter' === $loincSupport){
                $method = "GET";
                // 'count', not '_count' - see the 'refset' branch above for why. 100
                // rather than 20 because this sub-case still filters client-side for
                // LL-prefixed (answer-list) codes afterwards - see the loop below.
                $params = ['filter' => $query, 'url' => 'http://loinc.org/vs', 'count' => 100];
                $url = '/ValueSet/$expand';
                $processFunction = function ($data) {
                    $result = [];
                    if (isset($data['expansion']) && isset($data['expansion']['contains'])) {
                        foreach ($data['expansion']['contains'] as $this_entry) {
                            if (substr($this_entry['code'], 0, 2 ) === 'LL'){
                                $result[] = ['label' => $this_entry['display'], 'value' => 'http://loinc.org/vs/' . $this_entry['code']];
                            }
                            if (count($result) >= 20){
                                break;
                            }
                        }
                    }
                    if (empty($result)) {
                        $result[] = ['label' => 'No matches found', 'value' => '__NMF__'];
                    }
                    return $result;
                };
            }
            else {
                return []; // loinc not supported
            }
        } else {
            return ['error' => "Unknown search type $type"];
        }
        if ($this->isCircuitOpen()) {
            // Server has failed repeatedly - fail fast. Use the same ['error' => ...]
            // shape as the "unknown search type" case above, rather than an empty
            // array, so FindValueSetService.php can tell a genuine failure apart
            // from "no matches" instead of returning a misleadingly successful
            // empty result (see getValueSetInfo(), which does the equivalent with
            // a false return).
            return ['error' => 'The terminology server is not responding. Please try again shortly.'];
        }
        $headers = ['User-Agent: Redcap'];
        $authHeader = $this->getAuthHeader();
        if ($authHeader !== false) {
            $headers[] = $authHeader;
        }
        $startedAt = microtime(true);
        if ('GET' === $method) {
            $fullUrl = $this->getFhirServerUri() . $url . '?' . http_build_query($params);
            $result_json = $this->httpGet($fullUrl, $headers);
        } else {
            $fullUrl = $this->getFhirServerUri() . $url;
            $result_json = $this->httpPost($fullUrl, $postData, $contentType, $headers);
        }
        if ($result_json === false) {
            $this->recordFhirFailureIfSlow(microtime(true) - $startedAt);
            return ['error' => 'The terminology server is not responding. Please try again shortly.'];
        }
        $this->recordFhirSuccess();
        $result = $processFunction(json_decode($result_json, true));
        // Defensive cap, kept even now that every $expand call above sends the
        // correctly-named 'count' parameter (an earlier version of this method
        // sent '_count', the REST search-result modifier, which a $expand
        // *operation* silently ignores - confirmed live: a SNOMED CT "isa"
        // search for a common term, e.g. "neopl", returned 8,300+ matches with
        // '_count=20' but exactly 20 with 'count=20'). A server is still free
        // to ignore 'count' too, or a future edit could reintroduce the same
        // parameter-name mistake, so this stays as a second line of defense.
        // Every branch above already only ever intends ~20 (loinc_answer's
        // 'filter' sub-case already self-limits this way, inline) - the
        // Online Designer's autocomplete widget locks up the browser tab for
        // tens of seconds trying to render a dropdown that large, so this must
        // hold regardless of server behavior, not just be a request hint.
        return array_slice($result, 0, 20);
    }

    public function getValueSetInfo($valueSet)
    {
        $params = ["url" => $valueSet, "count" => 10];
        $fullUrl = $this->getFhirServerUri() . '/ValueSet/$expand?' . http_build_query($params);
        if ($this->isCircuitOpen()) {
            // Server has failed repeatedly - fail fast. false tells the service
            // layer to emit a 502 rather than a misleading empty success.
            return false;
        }
        $headers = ['User-Agent: Redcap'];
        $authHeader = $this->getAuthHeader();
        if ($authHeader !== false) {
            $headers[] = $authHeader;
        }
        $startedAt = microtime(true);
        $response = $this->httpGet($fullUrl, $headers);
        if ($response === false) {
            $this->recordFhirFailureIfSlow(microtime(true) - $startedAt);
            return false;
        }
        $this->recordFhirSuccess();
        return $response;
    }


    /**
     * Maximum number of seconds allowed to *connect* to the FHIR server; it bounds
     * an unreachable or refusing host. REDCap core's http_get()/http_post() helpers
     * set curl's connect timeout but not its total-time timeout, so this does not
     * currently bound a server that accepts the connection and then stalls - that
     * call can still hold a web server process open indefinitely. The exception is
     * the file_get_contents fallback used when curl is unavailable, where the
     * stream context 'timeout' option is a true end-to-end limit. Closing this gap
     * on the curl path requires the module to issue its own curl requests with an
     * explicit CURLOPT_TIMEOUT, which is planned follow-up work. The circuit breaker
     * (see isCircuitOpen()/recordFhirFailureIfSlow()) helps for the common case where
     * a slow or erroring server eventually returns - a slow response, a connection
     * reset, a timeout enforced at the OS or proxy layer - since those calls do
     * return and get counted. It does not help against a true indefinite hang:
     * recordFhirFailureIfSlow() only runs after httpGet()/httpPost() returns, and a
     * call that never returns is killed by PHP's own execution time limit first, so
     * it is never recorded and never trips the breaker. Once the breaker does open,
     * though, it stops all further calls outright, which is real protection against
     * repeat failures of either kind.
     */
    public function getFhirTimeout()
    {
        return FhirRequestPolicy::resolveTimeout($this->getSystemSetting('fhir_timeout'));
    }

    /**
     * True while the breaker is open, i.e. the FHIR server has failed repeatedly and
     * we should fail fast instead of dialing out again.
     *
     * Once the open window elapses a caller that observes it re-arms the window before
     * returning false, so that callers arriving behind it keep failing fast while it
     * probes the server.
     *
     * This is best-effort, not a guarantee. The read and the write are separate
     * round trips to the settings table with no lock between them, so two requests
     * arriving together at the window boundary can both observe the window as expired
     * and both probe. In practice that means normally one probe per window and
     * occasionally a few - which is sufficient, because the breaker exists to prevent
     * a stampede of every worker dialing a dead server, not to serialise probes.
     */
    public function isCircuitOpen()
    {
        $openUntil = $this->getSystemSetting('fhir_breaker_open_until');
        $now = time();
        if (FhirRequestPolicy::isOpen($openUntil, $now)) {
            return true;
        }
        if (FhirRequestPolicy::needsRearm($openUntil, $now)) {
            // Window elapsed. Re-arm before returning so callers behind this one keep
            // failing fast while it probes. recordFhirSuccess() clears both keys when
            // the probe succeeds, so the re-arm costs nothing on recovery.
            $this->setSystemSetting('fhir_breaker_open_until', $now + FhirRequestPolicy::BREAKER_OPEN_SECONDS);
        }
        return false;
    }

    /**
     * Counts a failure and opens the breaker once enough have accumulated.
     *
     * The increment is a read followed by a write with no lock between them, so
     * simultaneous failures can overwrite one another and the count can lag the
     * true number of failures. The effect is that the breaker may open after
     * slightly more than BREAKER_FAILURE_THRESHOLD failures rather than exactly
     * that many. It self-corrects as further failures arrive.
     */
    public function recordFhirFailure()
    {
        $failures = FhirRequestPolicy::nextFailureCount($this->getSystemSetting('fhir_breaker_failures'));
        $this->setSystemSetting('fhir_breaker_failures', $failures);
        if (FhirRequestPolicy::opensBreaker($failures)) {
            $this->setSystemSetting('fhir_breaker_open_until', time() + FhirRequestPolicy::BREAKER_OPEN_SECONDS);
        }
    }

    /**
     * A failure only indicates server health if the call actually hung. A fast
     * rejection (e.g. a malformed valueset url returning 4xx) must not trip the
     * breaker for every other project on the system.
     */
    public function recordFhirFailureIfSlow($elapsedSeconds)
    {
        if (FhirRequestPolicy::countsAsFailure($elapsedSeconds, $this->getFhirTimeout())) {
            $this->recordFhirFailure();
        }
    }

    public function recordFhirSuccess()
    {
        // only write when there is state to clear, so a healthy server costs no writes
        if ($this->getSystemSetting('fhir_breaker_failures')) {
            $this->setSystemSetting('fhir_breaker_failures', 0);
            $this->setSystemSetting('fhir_breaker_open_until', 0);
        }
    }


    /**
     * Returns $url with any embedded userinfo (user:pass@) stripped, for safe
     * inclusion in log messages. Falls back to the original value if it cannot
     * be parsed as a URL.
     */
    private function urlForLogging($url)
    {
        if (!is_string($url) || '' === $url) {
            return (string)$url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || (!isset($parts['user']) && !isset($parts['pass']))) {
            return $url;
        }
        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        if (isset($parts['path'])) {
            $result .= $parts['path'];
        }
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }
        return $result;
    }

    public function httpGet($fullUrl, $headers, $baseOverride = null)
    {
        // getFhirServerUri() strips any trailing slash from the configured setting.
        // Callers validating a candidate URL that has not been saved yet (e.g.
        // validateSettings()) pass $baseOverride so the check runs against that
        // candidate rather than the already-persisted setting. Passing the same
        // value as both $fullUrl and $baseOverride validates well-formedness only
        // (scheme/host present, no dot-segment traversal, no stray credentials) -
        // it is not a containment boundary check, since isWithinBase(x, x) is
        // trivially true.
        $base = (null === $baseOverride) ? $this->getFhirServerUri() : $baseOverride;
        if (!FhirRequestPolicy::isWithinBase($fullUrl, $base)) {
            error_log('FhirOntologyAutocompleteExternalModule: httpGet refused URL outside configured FHIR server - url='
                . $this->urlForLogging($fullUrl) . ' base=' . $this->urlForLogging($base));
            return false;
        }
        $timeout = $this->getFhirTimeout();
        // if curl isn't install the default version of http_get in init_functions doesn't include the headers.
        if (function_exists('curl_init') || empty($headers)) {
            return http_get($fullUrl, $timeout, '', $headers, null);
        }
        if (ini_get('allow_url_fopen')) {
            // Set http array for file_get_contents
            $headerText = '';
            foreach ($headers as $hvalue) {
                $headerText .= $hvalue . "\r\n";
            }
            $http_array = array('method' => 'GET', 'header' => $headerText, 'timeout' => $timeout);
            // If using a proxy
            if (!sameHostUrl($fullUrl) && PROXY_HOSTNAME != '') {
                $http_array['proxy'] = str_replace(array('http://', 'https://'), array('tcp://', 'tcp://'), PROXY_HOSTNAME);
                $http_array['request_fulluri'] = true;
                if (PROXY_USERNAME_PASSWORD != '') {
                    $proxy_auth = "Proxy-Authorization: Basic " . base64_encode(PROXY_USERNAME_PASSWORD);
                    if (isset($http_array['header'])) {
                        $http_array['header'] .= $proxy_auth . "\r\n";
                    } else {
                        $http_array['header'] = $proxy_auth . "\r\n";
                    }
                }
            }
            // Use file_get_contents
            $content = @file_get_contents($fullUrl, false, stream_context_create(array('http' => $http_array)));
        } else {
            $content = false;
        }
        // Return the response
        return $content;
    }

    public function httpPost($fullUrl, $postData, $contentType, $headers, $baseOverride = null)
    {
        // The OAuth2 token endpoint is a different origin from the FHIR server by
        // design, so it is allowed on an exact match against its own setting.
        // Callers validating a candidate URL that has not been saved yet (e.g.
        // validateSettings()) pass $baseOverride so the check runs against that
        // candidate rather than the already-persisted settings. Passing the same
        // value as both $fullUrl and $baseOverride validates well-formedness only
        // (scheme/host present, no dot-segment traversal, no stray credentials) -
        // it is not a containment boundary check, since isWithinBase(x, x) is
        // trivially true.
        if (null !== $baseOverride) {
            $allowed = FhirRequestPolicy::isWithinBase($fullUrl, $baseOverride);
            $baseForLog = $baseOverride;
        } else {
            $tokenEndpoint = $this->getSystemSetting('cc_token_endpoint');
            $baseForLog = $this->getFhirServerUri();
            $allowed = ($tokenEndpoint && $fullUrl === $tokenEndpoint)
                || FhirRequestPolicy::isWithinBase($fullUrl, $baseForLog);
        }
        if (!$allowed) {
            error_log('FhirOntologyAutocompleteExternalModule: httpPost refused URL outside configured FHIR server - url='
                . $this->urlForLogging($fullUrl) . ' base=' . $this->urlForLogging($baseForLog));
            return false;
        }
        $timeout = $this->getFhirTimeout();
        // if curl isn't install the default version of http_post in init_functions doesn't include the headers.
        // but the curl version will overwrite the content type header if other headers are included.
        if (function_exists('curl_init') && !empty($headers)
                 && $contentType && $contentType != 'application/x-www-form-urlencoded'){
            $fullHeaders = $headers;
            $fullHeaders[] = 'Content-type: '.$contentType;
            return http_post($fullUrl, $postData, $timeout, $contentType, '', $fullHeaders);
        }
        else if (function_exists('curl_init') || empty($headers)) {
            return http_post($fullUrl, $postData, $timeout, $contentType, '', $headers);
        }
        // If params are given as an array, then convert to query string format, else leave as is
        if ($contentType == 'application/json') {
            // Send as JSON data
            $param_string = (is_array($postData)) ? json_encode($postData) : $postData;
        } elseif ($contentType == 'application/x-www-form-urlencoded') {
            // Send as Form encoded data
            $param_string = (is_array($postData)) ? http_build_query($postData, '', '&') : $postData;
        } else {
            // Send params as is (e.g., Soap XML string)
            $param_string = $postData;
        }
        if (ini_get('allow_url_fopen')) {
            // Set http array for file_get_contents
            // Set http array for file_get_contents
            $headerText = '';
            foreach ($headers as $hvalue) {
                $headerText .= $hvalue . "\r\n";
            }

            $http_array = array('method' => 'POST',
                'header' => "Content-type: $contentType" . "\r\n" . $headerText . "Content-Length: " . strlen($param_string) . "\r\n",
                'content' => $param_string,
                'timeout' => $timeout
            );
            // If using a proxy
            if (!sameHostUrl($fullUrl) && PROXY_HOSTNAME != '') {
                $http_array['proxy'] = str_replace(array('http://', 'https://'), array('tcp://', 'tcp://'), PROXY_HOSTNAME);
                $http_array['request_fulluri'] = true;
                if (PROXY_USERNAME_PASSWORD != '') {
                    $http_array['header'] .= "Proxy-Authorization: Basic " . base64_encode(PROXY_USERNAME_PASSWORD) . "\r\n";
                }
            }

            // Use file_get_contents
            $content = @file_get_contents($fullUrl, false, stream_context_create(array('http' => $http_array)));

            // Return the content
            if ($content !== false) {
                return $content;
            } // If no content, check the headers to see if it's hiding there (why? not sure, but it happens)
            else {
                $content = implode("", $http_response_header);
                //  If header is a true header, then return false, else return the content found in the header
                return (substr($content, 0, 5) == 'HTTP/') ? false : $content;
            }
        }
        return false;
    }


    public function getAuthHeader()
    {
        $authType = $this->getSystemSetting('authentication_type');
        if ($authType === 'cc') {
            $authEndpoint = $this->getSystemSetting('cc_token_endpoint');
            $clientId = $this->getSystemSetting('cc_client_id');
            $clientSecret = $this->getSystemSetting('cc_client_secret');

            $authToken = $this->getClientCredentialsToken($authEndpoint, $clientId, $clientSecret);
            if ($authToken === false) {
                return false;
            }
            return 'Authorization: Bearer ' . $authToken;
        }
        elseif ($authType === 'basic') {
            $userId = $this->getSystemSetting('basic_user_id');
            $userPassword = $this->getSystemSetting('basic_user_password');
            return 'Authorization: Basic ' . base64_encode($userId . ':' . $userPassword);
        }
        return false;
    }

    public function getClientCredentialsToken($tokenEndpoint, $clientId, $clientSecret)
    {
        $now = time();
        if (array_key_exists('FHIR_ONTOLOGY_TOKEN_EXPIRES', $_SESSION) &&
            array_key_exists('FHIR_ONTOLOGY_TOKEN', $_SESSION)) {
            $expire = $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES'];
            if ($now < $expire) {
                // not expired.
                return $_SESSION['FHIR_ONTOLOGY_TOKEN'];
            }
        }

        // get the access token
        $params = array(
            'grant_type' => 'client_credentials'
        );
        $headers = ['User-Agent: Redcap',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)];

        $clear = true;
        try {
            $response = $this->httpPost($tokenEndpoint, $params, 'application/x-www-form-urlencoded', $headers);
            // a false or unparseable response decodes to null, and array_key_exists(null)
            // is a fatal TypeError on PHP 8
            $responseJson = is_string($response) ? json_decode($response, true) : null;
            if (!is_array($responseJson)) {
                error_log("Failed to negotiate auth token : no parseable response from " . $tokenEndpoint);
            } elseif (array_key_exists('access_token', $responseJson)) {
                $clear = false;
                $_SESSION['FHIR_ONTOLOGY_TOKEN'] = $responseJson['access_token'];
                // expires_in is SECONDS (RFC 6749) and $now is seconds - the previous
                // * 1000 cached a 3600s token for roughly 41 days. Renew early by
                // margin = min(60, floor(lifetime / 2)): a minute early for normal
                // lifetimes, halfway through for very short ones, and never an expiry
                // beyond the real one.
                $lifetime = array_key_exists('expires_in', $responseJson)
                    ? (int)$responseJson['expires_in']
                    : 3600;
                if ($lifetime < 1) {
                    $lifetime = 1;
                }
                $margin = (int)min(60, floor($lifetime / 2));
                $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + $lifetime - $margin;
            } elseif (array_key_exists('error', $responseJson)) {
                error_log("Failed to negotiate auth token : " . $responseJson['error'] . " - " . $responseJson['error_description']);
            } else {
                error_log("Failed to negotiate auth token : " . $response);
            }
        } catch (\Exception $e) {
            $error_code = $e->getCode();
            $error_message = $e->getMessage();
            error_log("Failed to negotiate auth token : {$error_code} - {$error_message}");
        }
        if ($clear) {
            unset($_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES']);
            unset($_SESSION['FHIR_ONTOLOGY_TOKEN']);
            return false;
        }
        return $_SESSION['FHIR_ONTOLOGY_TOKEN'];
    }
}

