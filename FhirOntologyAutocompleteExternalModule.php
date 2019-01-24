<?php
/**
 * 

CSIRO Open Source Software Licence Agreement (variation of the BSD / MIT License)
Copyright (c) 2018, Commonwealth Scientific and Industrial Research Organisation (CSIRO) ABN 41 687 119 230.
All rights reserved. CSIRO is willing to grant you a licence to this FhirOntologyAutocompleteModule on the following terms, except where otherwise indicated for third party material.
Redistribution and use of this software in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
* Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
* Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
* Neither the name of CSIRO nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission of CSIRO.
EXCEPT AS EXPRESSLY STATED IN THIS AGREEMENT AND TO THE FULL EXTENT PERMITTED BY APPLICABLE LAW, THE SOFTWARE IS PROVIDED "AS-IS". CSIRO MAKES NO REPRESENTATIONS, WARRANTIES OR CONDITIONS OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO ANY REPRESENTATIONS, WARRANTIES OR CONDITIONS REGARDING THE CONTENTS OR ACCURACY OF THE SOFTWARE, OR OF TITLE, MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, NON-INFRINGEMENT, THE ABSENCE OF LATENT OR OTHER DEFECTS, OR THE PRESENCE OR ABSENCE OF ERRORS, WHETHER OR NOT DISCOVERABLE.
TO THE FULL EXTENT PERMITTED BY APPLICABLE LAW, IN NO EVENT SHALL CSIRO BE LIABLE ON ANY LEGAL THEORY (INCLUDING, WITHOUT LIMITATION, IN AN ACTION FOR BREACH OF CONTRACT, NEGLIGENCE OR OTHERWISE) FOR ANY CLAIM, LOSS, DAMAGES OR OTHER LIABILITY HOWSOEVER INCURRED.  WITHOUT LIMITING THE SCOPE OF THE PREVIOUS SENTENCE THE EXCLUSION OF LIABILITY SHALL INCLUDE: LOSS OF PRODUCTION OR OPERATION TIME, LOSS, DAMAGE OR CORRUPTION OF DATA OR RECORDS; OR LOSS OF ANTICIPATED SAVINGS, OPPORTUNITY, REVENUE, PROFIT OR GOODWILL, OR OTHER ECONOMIC LOSS; OR ANY SPECIAL, INCIDENTAL, INDIRECT, CONSEQUENTIAL, PUNITIVE OR EXEMPLARY DAMAGES, ARISING OUT OF OR IN CONNECTION WITH THIS AGREEMENT, ACCESS OF THE SOFTWARE OR ANY OTHER DEALINGS WITH THE SOFTWARE, EVEN IF CSIRO HAS BEEN ADVISED OF THE POSSIBILITY OF SUCH CLAIM, LOSS, DAMAGES OR OTHER LIABILITY.
APPLICABLE LEGISLATION SUCH AS THE AUSTRALIAN CONSUMER LAW MAY APPLY REPRESENTATIONS, WARRANTIES, OR CONDITIONS, OR IMPOSES OBLIGATIONS OR LIABILITY ON CSIRO THAT CANNOT BE EXCLUDED, RESTRICTED OR MODIFIED TO THE FULL EXTENT SET OUT IN THE EXPRESS TERMS OF THIS CLAUSE ABOVE "CONSUMER GUARANTEES".  TO THE EXTENT THAT SUCH CONSUMER GUARANTEES CONTINUE TO APPLY, THEN TO THE FULL EXTENT PERMITTED BY THE APPLICABLE LEGISLATION, THE LIABILITY OF CSIRO UNDER THE RELEVANT CONSUMER GUARANTEE IS LIMITED (WHERE PERMITTED AT CSIRO'S OPTION) TO ONE OF FOLLOWING REMEDIES OR SUBSTANTIALLY EQUIVALENT REMEDIES:
(a)               THE REPLACEMENT OF THE SOFTWARE, THE SUPPLY OF EQUIVALENT SOFTWARE, OR SUPPLYING RELEVANT SERVICES AGAIN;
(b)               THE REPAIR OF THE SOFTWARE;
(c)               THE PAYMENT OF THE COST OF REPLACING THE SOFTWARE, OF ACQUIRING EQUIVALENT SOFTWARE, HAVING THE RELEVANT SERVICES SUPPLIED AGAIN, OR HAVING THE SOFTWARE REPAIRED.
IN THIS CLAUSE, CSIRO INCLUDES ANY THIRD PARTY AUTHOR OR OWNER OF ANY PART OF THE SOFTWARE OR MATERIAL DISTRIBUTED WITH IT.  CSIRO MAY ENFORCE ANY RIGHTS ON BEHALF OF THE RELEVANT THIRD PARTY.
Third Party Components
The following third party components are distributed with the Software.  You agree to comply with the licence terms for these components as part of accessing the Software.  Other third party software may also be identified in separate files distributed with the Software.

 * 
 * 
 * 
 */

namespace AEHRC\FhirOntologyAutocompleteExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class FhirOntologyAutocompleteExternalModule extends AbstractExternalModule  implements \OntologyProvider{

  public function __construct() {
      parent::__construct();
      // register with OntologyManager
      $manager = \OntologyManager::getOntologyManager();
      $manager->addProvider($this);
  }

  public function redcap_every_page_before_render (int $project_id ){
    // don't need to do anything, just trigger the constructor so the provider is available.
  }
  
  public function redcap_data_entry_form ( int $project_id, string $record, 
      string $instrument, int $event_id, int $group_id, int $repeat_instance){
  
          if ($this->getSystemSetting('add_value_tooltip')){
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
      
  
  public function redcap_survey_page ( int $project_id, string $record, 
      string $instrument, int $event_id, int $group_id, string $survey_hash, int $response_id, 
      int $repeat_instance){

          if ($this->getSystemSetting('add_value_tooltip')){
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
  
  
  public function validateSettings($settings){
      $errors='';
      
      $rnr = $settings['return_no_result'];
      $label = trim($settings['no_result_label']);
      $code = trim($settings['no_result_code']);
      
      if ($rnr){
          // check we have a code and label
          if ($label === ''){
              $errors .= "No Result Label is required\n";
          }
          else if ($label != strip_tags($label)){
              $errors .= "No Results Label has illegal characters - ".$label."\n";
          }
          
          if ($code === ''){
              $errors .= "No Result Code is required\n";
          }
          else if ($code != strip_tags($code)
              || strpos($code, "'") !== false
              || strpos($code, '"') !== false
              ){
                  $errors .= "No Results Code has illegal characters - ".$code."\n";
          }
      }
      
      $fhirUrl = $settings['fhir_api_url'];
      $metadata = http_get($fhirUrl . '/metadata');
      if ($metadata == FALSE){
        $errors .= "Failed to get metadata for fhir server at '" . $fhirUrl . "'\n";
      }
      return $errors;
  }
  
  
  /**
    * return the name of the ontology service as it will be display on the service selection
    * drop down.
    */
  public function getProviderName(){
    return 'FHIR Ontologies';
  }
    

  /**
    return the prefex used to denote ontologies provided by this provider.
   */
  public function getServicePrefix(){
    return 'FHIR';
  }

  /** 
   * Search API with a search term for a given ontology 
   * Returns array of results with Notation as key and PrefLabel as value. 
   */ 
  public function searchOntology($valueset_id, $search_term, $result_limit) 
  { 
		$fhir_server_uri = $this->getSystemSetting('fhir_api_url');
    // Set 20 as default limit 
    $result_limit = (is_numeric($result_limit) ? $result_limit : 20); 
     
    // Build URL to call 
    //  Base URL + “/ValueSet/$expand?identifier=VS_ID&filter=SEARCH_TERM” 
        // need to escape the $expand in the url! 
        $url = $fhir_server_uri . "/ValueSet/\$expand?" . http_build_query(array( 
                    'url'=> $valueset_id, 
                    'filter' => $search_term, 
                    'count' => $result_limit 
               )); 
    // Call the URL 
    $json = http_get($url); 
    // Parse the JSON into an array 
    $list = json_decode($json, true); 
    $expansion = $list['expansion']; 
    $results = array();
    if (is_array($list) && isset($expansion['contains'])){
        // Loop through results
        
        foreach ($expansion['contains'] as $this_item) {
            
            // Determine the value
            // need to add the system as codes are not unique in SCT
            $this_value = $this_item['code'] . "|" . $this_item['display'] ."|" . $this_item['system'] ;
            
            // Add to array
            $results[$this_value] = $this_item['display'];
        }
    }

    if (!$results){
        // no results found
        $return_no_result = $this->getSystemSetting('return_no_result');
        if ($return_no_result){
            $no_result_label = $this->getSystemSetting('no_result_label');
            $no_result_code = $this->getSystemSetting('no_result_code');
            $results[$no_result_code] = $no_result_label; 
        }
    }
    // Return array of results 
    return array_slice($results, 0, $result_limit, true); 
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
  public function getOnlineDesignerSection(){
		$fhir_server_uri = $this->getSystemSetting('fhir_api_url');

    $categories = [];
    foreach ($systemCategories as $cat){
      $categories[$cat['category']] = $cat;
    }
    foreach ($projectCategories as $cat){
      $categories[$cat['category']] = $cat;
    }

    $categoryList = '';
    foreach ($categories as $cat){
      $category = $cat['category'];
      $name = $cat['name'];
      $categoryList .= "<option value='{$category}'>{$name}</option>\n";
    }

   $onlineDesignerHtml = <<<EOD
<script type="text/javascript">

  function FHIR_ontology_changed(service, category){
    if ('FHIR' != service){
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

  function move_selected_valueset(){
        selected_valueset = $('#fhir_valueset_search_code').text();
        if (selected_valueset){
          $('#fhir_value_set').val(selected_valueset);
          update_ontology_selection('FHIR', selected_valueset);
        }
  }

  function show_selected_valueset(){
        selected_valueset = $('#fhir_valueset_search_code').text();
        if (selected_valueset == ''){
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
            url: "{$fhir_server_uri}/ValueSet/\$expand",
            data: JSON.stringify({
              "resourceType": "Parameters",
              "parameter": [
                {
                  "name": "url",
                  "valueUri": selected_valueset 
                },
                {
                  "name": "count",
                "valueInteger": "10"
                }
              ]
              }),
            contentType: "application/json; charset=utf-8",
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
  }



  $( function() {
    $( "#fhir_valueset_search" ).autocomplete({
      source: function( request, response ) {
        search_type = $('#fhir_valueset_search_type').val();

        if (search_type === 'name'){
          type="GET";
          url="{$fhir_server_uri}/ValueSet";
          postData={ 'name': request.term, '_summary': 'true', '_count': '20'};
          processFunction = function( data ){
            result = [];
            if (data.entry){
              for (v of data.entry){
                
                result.push({ 'label' : v.resource.name, 'value' : v.resource.url});
              }
            }
            if (!result.length){
              result.push({ 'label' : 'No matches found', 'value' : '__NMF__' });
            }
            
            response( result );
          };
        }
        else if (search_type === 'codesystem') {
          type="GET";
          url="{$fhir_server_uri}/CodeSystem";
          postData={ 'name': request.term, '_elements': 'name,valueSet', '_count': '20'};
          processFunction = function( data ){
            result = [];
            if (data.entry){
              for (v of data.entry){
                if (v.resource.valueSet){
                  result.push({ 'label' : v.resource.name, 'value' : v.resource.valueSet});
                }
              }
            }
            if (!result.length){
              result.push({ 'label' : 'No matches found', 'value' : '__NMF__' });
            }
            response( result );
          };
        }
        else if (search_type === 'refset') {
          type="POST";
          url="{$fhir_server_uri}/ValueSet/\$expand";
          postData = JSON.stringify({
            "resourceType": "Parameters",
            "parameter": [
              {
                "name": "filter",
                "valueString": request.term
              },
              {
                "name": "url",
                "valueUri": "http://snomed.info/sct?fhir_vs=refset"
              },
              {
                "name": "count",
                "valueInteger": "20"
              }
            ]
          });
          processFunction = function( data ){
            result = [];
            if (data.expansion && data.expansion.contains){
              for (v of data.expansion.contains){
                result.push({ 'label' : v.display, 'value' : 'http://snomed.info/sct?fhir_vs=refset/' + v.code});
              }
            }
            if (!result.length){
              result.push({ 'label' : 'No matches found', 'value' : '__NMF__' });
            }
            response( result );
          };
        }
        else if (search_type === 'isa') {
          type="POST";
          url="{$fhir_server_uri}/ValueSet/\$expand";
          postData = JSON.stringify({
            "resourceType": "Parameters",
            "parameter": [
              {
                "name": "filter",
                "valueString": request.term
              },
              {
                "name": "url",
                "valueUri": "http://snomed.info/sct?fhir_vs"
              },
              {
                "name": "count",
                "valueInteger": "20"
              }
            ]
          });
          processFunction = function( data ){
            result = [];
            if (data.expansion && data.expansion.contains){
              for (v of data.expansion.contains){
                result.push({ 'label' : v.display, 'value' : 'http://snomed.info/sct?fhir_vs=isa/' + v.code});
              }
            }
            if (!result.length){
              result.push({ 'label' : 'No matches found', 'value' : '__NMF__' });
            }
            response( result );
          };
        }
        else if (search_type === 'loinc_answer') {
          type="POST";
          url="{$fhir_server_uri}/ValueSet/\$expand";
          postData = JSON.stringify({
            "resourceType": "Parameters",
            "parameter": [
              {
                "name": "filter",
                "valueString": request.term
              },
              {
                "name": "_count",
                "valueInteger": "20"
              },
              {
                "name": "valueSet",
                "resource": 
                {
                    "resourceType": "ValueSet",
                    "compose": {
                        "include": [
                            {
                                "system": "http://loinc.org",
                                "filter": [
                                    {
                                        "property": "parent",
                                        "op": "=",
                                        "value": "LL"
                                    }
                                    ]
                            }]
                    }
                }
              }
            ]
          });
          processFunction = function( data ){
            result = [];
            if (data.expansion && data.expansion.contains){
              for (v of data.expansion.contains){
                result.push({ 'label' : v.display, 'value' : 'http://loinc.org/vs/' + v.code});
              }
            }
            if (!result.length){
              result.push({ 'label' : 'No matches found', 'value' : '__NMF__' });
            }
            response( result );
          };
        }
        else {
          return [];
        }

        $.ajax( {
          type: type,
          url: url,
          data: postData,
          contentType: "application/json; charset=utf-8",
          dataType: "json",
          success: processFunction
        } );
      },
      select: function( event, ui ) {
        event.preventDefault();
        if (ui.item.value !== '__NMF__'){
          $('#fhir_valueset_search_code').text(ui.item.value);
          $(this).val(ui.item.label);
          return true;
        }
        else {
          return false;
        }
      },
      focus: function(event, ui) {
          event.preventDefault();
          if (ui.item.value !== '__NMF__'){
            $(this).val(ui.item.label);
          }

          return false;
      },
      minLength: 2
    } );
    $( "#fhir_valueset_dialog" ).dialog({
      autoOpen: false,
      modal: true,
      width: 'auto'
    });
  } );</script>
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
   <a class="ui-button ui-widget ui-corner-all" href="#" onclick="move_selected_valueset()">Select</a>
   </div>

  <input id="fhir_value_set" class="x-form-text x-form-field" size="25" type="text" readonly="readonly">
   <a class="ui-button ui-widget ui-corner-all" href="#" onclick="show_selected_valueset()">Show Details</a>
  
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


  public function getLabelForValue($category, $value){
    return $value;
  }
}

