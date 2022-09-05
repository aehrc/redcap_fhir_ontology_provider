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
            // this is a bit of a hack, if redcap change their code it will break
            // it looks for all input fields tagged as autosug-ont-field
            // which should mean they are an ontology lookup and adds
            // a hover function which will set the fields title to match
            // its value. This should give a popup with the full value
            // text shown instead of being restricted by the size of
            // the input field.

            $dataEntryHtml = <<<EOD
<script type="text/javascript">
      // IIFE - Immediately Invoked Function Expression
      (function($, window, document) {
          // The $ is now locally scoped
          $('input.autosug-ont-field').each(function(){
              $( this ).hover(function(){
                  $( this ).attr('title', $( this ).val());
                  return true;
              });
          });
          
      }(window.jQuery, window, document));
      // The global jQuery object is passed as a parameter
</script>
EOD;
            print($dataEntryHtml);
        }
    }


    public function redcap_survey_page($project_id, $record,
                                       $instrument, $event_id, $group_id, $survey_hash, $response_id,
                                       $repeat_instance)
    {

        if ($this->getSystemSetting('add_value_tooltip')) {
            // this is a bit of a hack, if redcap change their code it will break
            // it looks for all input fields tagged as autosug-ont-field
            // which should mean they are an ontology lookup and adds
            // a hover function which will set the fields title to match
            // its value. This should give a popup with the full value
            // text shown instead of being restricted by the size of
            // the input field.
            $surveyHtml = <<<EOD
<script type="text/javascript">
      // IIFE - Immediately Invoked Function Expression
      (function($, window, document) {
          // The $ is now locally scoped
          $('input.autosug-ont-field').each(function(){
              $( this ).hover(function(){
                  $( this ).attr('title', $( this ).val());
                  return true;
              });
          });
          
      }(window.jQuery, window, document));
      // The global jQuery object is passed as a parameter
</script>
EOD;
            print($surveyHtml);
        }
    }


    public function validateSettings($settings)
    {
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
        $metadata = $this->httpGet($fhirUrl . '/metadata', $headers);
        if ($metadata === FALSE) {
            $errors .= "Failed to get metadata for fhir server at '" . $fhirUrl . "'\n";
        }
        $authType = $settings['authentication_type'];
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
                $response = $this->httpPost($authEndpoint, $params, 'application/x-www-form-urlencoded', $headers);
                if ($response === false) {
                    $r = implode("", $http_response_header);
                    $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "' response = false, r='" . $r . "'\n";
                } else {
                    $responseJson = json_decode($response, true);
                    if (!array_key_exists('access_token', $responseJson)) {
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

    /**
     * Search API with a search term for a given ontology
     * Returns array of results with Notation as key and PrefLabel as value.
     */
    public function searchOntology($valueset_id, $search_term, $result_limit)
    {
        $fhir_server_uri = $this->getFhirServerUri();
        // Set 20 as default limit
        $result_limit = (is_numeric($result_limit) ? $result_limit : 20);

        // Build URL to call
        $headers = ['User-Agent: Redcap'];
        $authToken = $this->getAuthToken();
        if ($authToken !== false) {
            $headers[] = 'Authorization: Bearer ' . $this->getAuthToken();
        }
        //  Base URL + “/ValueSet/$expand?identifier=VS_ID&filter=SEARCH_TERM”
        // need to escape the $expand in the url! 
        $url = $fhir_server_uri . "/ValueSet/\$expand?" . http_build_query(array(
                'url' => $valueset_id,
                'filter' => $search_term,
                'count' => $result_limit
            ));
        // Call the URL

        $json = $this->httpGet($url, $headers);
        // Parse the JSON into an array
        $list = json_decode($json, true);
        $expansion = $list['expansion'];
        $results = array();
        if (is_array($list) && isset($expansion['contains'])) {
            // Loop through results
            $hideChoice = $this->getHideChoice();
            foreach ($expansion['contains'] as $this_item) {

                if (in_array($this_item['code'], $hideChoice)){
                    // in hide choice list
                    continue;
                }
                // Determine the value
                // need to add the system as codes are not unique in SCT
                $this_value = $this_item['code'] . "|" . $this_item['display'] . "|" . $this_item['system'];

                // Add to array
                $results[$this_value] = $this_item['display'];
            }
        }

        if (!$results) {
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

    function getHideChoice()
    {
        $codesToHide=[];
        if (isset($_GET['field'])){
            $field = $_GET['field'];
            if (isset($Proj->metadata[$_GET['field']])) {
                $annotations = $Proj->metadata[$field]['field_annotation'];
            }
            else if (isset($_GET['pid'])){
                $project_id = $_GET['pid'];
                $dd_array = \REDCap::getDataDictionary($project_id, 'array', false, array($field));
                $annotations = $dd_array[$field]['field_annotation'];
            }
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
        }

        return $codesToHide;
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

        $findValueSetService_url = $this->getUrl('FindValueSetService.php', false, true);
        $onlineDesignerHtml = <<<EOD
<script type="text/javascript">

  function FHIR_ontology_changed(service, category){
    if ('FHIR' !== service){
      $('#fhir_valueset_search_type').val('');
      $('#fhir_valueset_search').val('');
      $('#fhir_valueset_search_code').text('');
      $('#fhir_value_set').val('');
    }
    else {
      $('#fhir_value_set').val(category);
    }
  }

  function fhir_update_search_selection(selectedValue){
        $('#fhir_valueset_search').val('');
        $('#fhir_valueset_search_code').text('');
  }

  function move_selected_valueset(event){
        selected_valueset = $('#fhir_valueset_search_code').text();
        if (selected_valueset){
          $('#fhir_value_set').val(selected_valueset);
          update_ontology_selection('FHIR', selected_valueset);
        }
        event.preventDefault();
        return false;
  }

  function JSON_STRING(data){
    this.data = data;
  }

  JSON_STRING.prototype.toString = function(){return JSON.stringify(this.data)};

  function show_selected_valueset(event){
        selected_valueset = $('#fhir_valueset_search_code').text();
        if (selected_valueset === ''){
          selected_valueset = $('#fhir_value_set').val();
        }
        if (selected_valueset){
          $('#fhirValueSet_url').text('');
          $('#fhirValueSet_name').text('');
          $('#fhirValueSet_version').text('');
          $('#fhirValueSet_status').text('');
          $('#fhirValueSet_expansion_count').text('');
          $('#fhirValueSet_contains').empty();

          $.ajax( {
            type: "POST",
            url: '{$findValueSetService_url}',
            processData: true,
            data: {action: 'info', valueSet: selected_valueset},
            contentType: 'application/x-www-form-urlencoded',
            dataType: "json",
            success: function(data){
              if (data.url) $('#fhirValueSet_url').text(data.url);
              if (data.name) $('#fhirValueSet_name').text(data.name);
              if (data.version) $('#fhirValueSet_version').text(data.version);
              if (data.status) $('#fhirValueSet_status').text(data.status);
              if (data.expansion && data.expansion.total) $('#fhirValueSet_expansion_count').text(data.expansion.total);
              if (data.expansion && data.expansion.contains){
                for (v of data.expansion.contains){
                  r = "<tr><td class='data'>" + v.display + "</td><td class='data'>" + v.code + "</td><td class='data'>"+v.system+"</td></tr>"
                  $('#fhirValueSet_contains').append(r);
                }
              }
            },
            error: function (xhr, status, errorThrown) {
              $('#fhirValueSet_url').text(selected_valueset);
              $('#fhirValueSet_name').text('');
              $('#fhirValueSet_version').text('');
              $('#fhirValueSet_status').text('');
              $('#fhirValueSet_expansion_count').text('');

              // we are expecting a json response
              var errorObject;
              try {
                errorObject = JSON.parse(xhr.responseText);
              }
              catch (e){
                // not json
              }
              var errorMessage = "Failed to load Valueset - Status : " + xhr.status + "<br>\\n"
              if (errorObject && errorObject.issue){
                for (issue of errorObject.issue){
                  errorMessage += issue.severity + " : " + issue.diagnostics + "<br>\\n";
                }
              }
              $('#fhirValueSet_contains').append("<tr class='error'><td class='data' colspan='3'>" + errorMessage + "</td></tr>");
            }
          } );
          $('#fhir_valueset_dialog').dialog('open');
        }
        event.preventDefault();
        return false;
  }



 $(function () {
    $("#fhir_valueset_search").autocomplete({
        source: function (request, response) {
            let search_type = $('#fhir_valueset_search_type').val();
            let params = {action: 'find', query: request.term, type: search_type};
            let processFunction = function (data) {
                let result = [];
                for (let v of data) {
                    result.push({'label': v.label, 'value': v.value});
                }
                if (!result.length) {
                    result.push({'label': 'No matches found', 'value': '__NMF__'});
                }

                response(result);
            };

            $.ajax({
                type: 'POST',
                url: '{$findValueSetService_url}',
                processData: true,
                data: params,
                contentType: 'application/x-www-form-urlencoded',
                dataType: "json",
                success: processFunction
            });
        },
        select: function (event, ui) {
            event.preventDefault();
            if (ui.item.value !== '__NMF__') {
                $('#fhir_valueset_search_code').text(ui.item.value);
                $(this).val(ui.item.label);
                return true;
            } else {
                return false;
            }
        },
        focus: function (event, ui) {
            event.preventDefault();
            if (ui.item.value !== '__NMF__') {
                $(this).val(ui.item.label);
            }

            return false;
        },
        minLength: 2
    });
    $("#fhir_valueset_dialog").dialog({
        autoOpen: false,
        modal: true,
        width: 'auto'
    });
});</script>
<div style='margin:0 0 2px;'>
  <div style='margin:3px 0 8px;color:#888;'>Search For valuset using:</div>
  <select id='fhir_valueset_search_type' name='fhir_valueset_search_type' 
          onchange='fhir_update_search_selection(this.options[this.selectedIndex].value)'
          class='x-form-text x-form-field' style='padding-right:0;height:22px;width:330px;max-width:330px;'>
    <option value=""> -- choose search criteria -- </option>
    <option value="name">ValueSet Name</option>
    <option value="codesystem">By CodeSystem(Name)</option>
    <option value="refset">SnomedCT Refset</option>
    <option value="isa">SnomedCT isa implicit valueset</option>
    <option value="loinc_answer">LOINC implicit answer set</option>
  </select><br>
  <div class="ui-front">
   <input  id="fhir_valueset_search" class="x-form-text x-form-field" size="25">
   <span id="fhir_valueset_search_code" style="display:none"></span>
   <a class="ui-button ui-widget ui-corner-all" href="#" onclick="move_selected_valueset(event)">Select</a>
   </div>

  <input id="fhir_value_set" class="x-form-text x-form-field" size="25" type="text" readonly="readonly">
   <a class="ui-button ui-widget ui-corner-all" href="#" onclick="show_selected_valueset(event)">Show Details</a>
  
   <div id="fhir_valueset_dialog" title="ValueSet Details">
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
EOD;
        return $onlineDesignerHtml;
    }


    public function getLabelForValue($category, $value)
    {
        return $value;
    }

    public function isValidValuesetQueryType($type)
    {
        if ('name' === $type ||
            'codesystem' === $type ||
            'refset' === $type ||
            'isa' === $type ||
            'loinc_answer' === $type) {
            return true;
        }
        return false;
    }

    public function findValueSet($type, $query)
    {
        $contentType = 'application/x-www-form-urlencoded';
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
            $params = ['name' => $query, '_elements' => 'name,valueSet', '_count' => 20];
            $url = "/CodeSystem";
            $processFunction = function ($data) {
                $result = [];
                if (isset($data['entry'])) {
                    foreach ($data['entry'] as $this_entry) {
                        if (isset($this_entry['resource']['valueSet'])) {
                            $result[] = ['label' => $this_entry['resource']['name'], 'value' => $this_entry['resource']['valueSet']];
                        }
                    }
                }
                if (empty($result)) {
                    $result[] = ['label' => 'No matches found', 'value' => '__NMF__'];
                }

                return $result;
            };
        } elseif ($type === 'refset') {
            $method = "GET";
            $params = ['filter' => $query, 'url' => 'http://snomed.info/sct?fhir_vs=refset', '_count' => 20];
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
            $method = "GET";
            $params = ['filter' => $query, 'url' => 'http://snomed.info/sct?fhir_vs', '_count' => 20];
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
            $method = "POST";
            $contentType = "application/json";
            $postData = [
                "resourceType" => "Parameters",
                "parameter" => [
                    ["name" => "filter", "valueString" => $query],
                    ["name" => "_count", "valueInteger" => 20],
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
        } else {
            return ['error' => "Unknown search type $type"];
        }
        $headers = ['User-Agent: Redcap'];
        $authToken = $this->getAuthToken();
        if ($authToken !== false) {
            $headers[] = 'Authorization: Bearer ' . $this->getAuthToken();
        }
        if ('GET' === $method) {
            $fullUrl = $this->getFhirServerUri() . $url . '?' . http_build_query($params);
            $result_json = $this->httpGet($fullUrl, $headers);
        } else {
            $fullUrl = $this->getFhirServerUri() . $url;
            $result_json = $this->httpPost($fullUrl, $postData, $contentType, $headers);
        }
        if ($result_json === false) {
            return [];
        }
        return $processFunction(json_decode($result_json, true));
    }

    public function getValueSetInfo($valueSet)
    {
        $params = ["url" => $valueSet, "count" => 10];
        $fullUrl = $this->getFhirServerUri() . '/ValueSet/$expand?' . http_build_query($params);
        $headers = ['User-Agent: Redcap'];
        $authToken = $this->getAuthToken();
        if ($authToken !== false) {
            $headers[] = 'Authorization: Bearer ' . $this->getAuthToken();
        }
        return $this->httpGet($fullUrl, $headers);
    }


    public function httpGet($fullUrl, $headers)
    {
        // if curl isn't install the default version of http_get in init_functions doesn't include the headers.
        if (function_exists('curl_init') || empty($headers)) {
            return http_get($fullUrl, null, '', $headers, null);
        }
        if (ini_get('allow_url_fopen')) {
            // Set http array for file_get_contents
            $headerText = '';
            foreach ($headers as $hvalue) {
                $headerText .= $hvalue . "\r\n";
            }
            $http_array = array('method' => 'GET', 'header' => $headerText);
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

    public function httpPost($fullUrl, $postData, $contentType, $headers)
    {
        // if curl isn't install the default version of http_post in init_functions doesn't include the headers.
        // but the curl version will overwrite the content type header if other headers are included.
        if (function_exists('curl_init') && !empty($headers)
                 && $contentType && $contentType != 'application/x-www-form-urlencoded'){
            $fullHeaders = $headers;
            $fullHeaders[] = 'Content-type: '.$contentType;
            return http_post($fullUrl, $postData, null, $contentType, '', $fullHeaders);
        }
        else if (function_exists('curl_init') || empty($headers)) {
            return http_post($fullUrl, $postData, null, $contentType, '', $headers);
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
                'content' => $param_string
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


    public function getAuthToken()
    {
        $authType = $this->getSystemSetting('authentication_type');
        if ($authType === 'cc') {
            $authEndpoint = $this->getSystemSetting('cc_token_endpoint');
            $clientId = $this->getSystemSetting('cc_client_id');
            $clientSecret = $this->getSystemSetting('cc_client_secret');

            return $this->getClientCredentialsToken($authEndpoint, $clientId, $clientSecret);
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
            $responseJson = json_decode($response, true);
            if (array_key_exists('access_token', $responseJson)) {
                $clear = false;
                $_SESSION['FHIR_ONTOLOGY_TOKEN'] = $responseJson['access_token'];
                if (array_key_exists('expires_in', $responseJson)) {
                    $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + ($responseJson['expires_in'] * 1000);
                } else {
                    $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + (60 * 60 * 1000);
                }
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

