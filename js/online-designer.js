/**
 * Online Designer ontology-picker UI for the FHIR ontology provider: lets a
 * project designer search for a FHIR ValueSet (by name, CodeSystem title, or
 * a SNOMED CT/LOINC implicit valueset) and inspect its details before
 * assigning it to a field.
 *
 * Self-initializes from the wrapper element's data-module-object attribute -
 * the JavaScript Module Object's dotted path (from
 * getJavascriptModuleObjectName()/initializeJavascriptModuleObject()) is
 * computed server-side and can't be a template placeholder in a real .js
 * file, so it crosses the PHP/JS boundary as a data attribute instead of a
 * string-interpolated literal. Matches
 * simple_ontology_provider/js/cache-refresh.js's resolveGlobalByPath()
 * convention exactly, rather than inventing a second one.
 */
function resolveGlobalByPath(path) {
  return String(path).split('.').reduce(function (value, key) {
    return value && value[key];
  }, window);
}

/** Set once, from data-module-object, when the wrapper element is found on DOMReady. */
var fhirOntologyModuleObject;

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

function manual_valuset_update(event){
      selected_valueset = $('#fhir_value_set').val();
      if (selected_valueset){
        update_ontology_selection('FHIR', selected_valueset);
      }
}


function JSON_STRING(data){
  this.data = data;
}

JSON_STRING.prototype.toString = function(){return JSON.stringify(this.data)};

function renderValuesetError(message){
  // build via DOM - the message may echo text a project designer typed as the
  // valueset id/url, so it must never be concatenated into markup
  $('#fhirValueSet_name').text('');
  $('#fhirValueSet_version').text('');
  $('#fhirValueSet_status').text('');
  $('#fhirValueSet_expansion_count').text('');
  var $errorCell = $('<td>').addClass('data').attr('colspan', '3').text(message);
  $('#fhirValueSet_contains').append($('<tr>').addClass('error').append($errorCell));
}

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

        // redcap_module_ajax()'s 'get-valueset-info' action returns either the
        // parsed FHIR ValueSet resource, or {error: "..."} for a domain-level
        // failure (breaker open, transport failure, malformed response) -
        // that's a normal resolved payload, not a rejection (module.ajax()
        // only rejects for a framework-level failure, e.g. verification).
        fhirOntologyModuleObject.ajax('get-valueset-info', {valueSet: selected_valueset}).then(function(data){
          if (data && data.error){
            $('#fhirValueSet_url').text(selected_valueset);
            renderValuesetError(data.error);
            return;
          }
          if (data.url) $('#fhirValueSet_url').text(data.url);
          if (data.name) $('#fhirValueSet_name').text(data.name);
          if (data.version) $('#fhirValueSet_version').text(data.version);
          if (data.status) $('#fhirValueSet_status').text(data.status);
          if (data.expansion && data.expansion.total) $('#fhirValueSet_expansion_count').text(data.expansion.total);
          if (data.expansion && data.expansion.contains){
            for (v of data.expansion.contains){
              // build via DOM so server supplied text can never be parsed as markup
              var $row = $('<tr>');
              $row.append($('<td>').addClass('data').text(v.display));
              $row.append($('<td>').addClass('data').text(v.code));
              $row.append($('<td>').addClass('data').text(v.system));
              $('#fhirValueSet_contains').append($row);
            }
          }
        }).catch(function(error){
          $('#fhirValueSet_url').text(selected_valueset);
          renderValuesetError(typeof error === 'string' ? error : 'The request could not be completed.');
        });
        $('#fhir_valueset_dialog').dialog('open');
      }
      event.preventDefault();
      return false;
}



$(function () {
  var $app = $('#fhir_ontology_designer_app');
  if (!$app.length) {
    return;
  }
  fhirOntologyModuleObject = resolveGlobalByPath($app.data('module-object'));

  $("#fhir_valueset_search").autocomplete({
      source: function (request, response) {
          let search_type = $('#fhir_valueset_search_type').val();
          // findValueSet()'s success shape is a plain array of {label, value};
          // {error: "..."} (breaker open, transport failure, unknown type) and a
          // framework-level rejection are both treated as "no matches" here -
          // there's no result list UI in this widget to show an error in.
          fhirOntologyModuleObject.ajax('find-valueset', {query: request.term, type: search_type}).then(function(data){
              let result = [];
              if (Array.isArray(data)) {
                  for (let v of data) {
                      result.push({'label': v.label, 'value': v.value});
                  }
              }
              if (!result.length) {
                  result.push({'label': 'No matches found', 'value': '__NMF__'});
              }
              response(result);
          }).catch(function(){
              response([{'label': 'No matches found', 'value': '__NMF__'}]);
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
});
