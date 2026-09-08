/**
 * Online Designer ontology-picker UI for the FHIR ontology provider: shows a
 * compact summary of the field's currently selected FHIR ValueSet, with a
 * "Change..." control that opens a single popup dialog for searching (by
 * name, CodeSystem title, or a SNOMED CT/LOINC implicit valueset), entering a
 * ValueSet URL directly, inspecting its details, and applying it to the
 * field.
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

/**
 * Caches a resolved display name per ValueSet URL for this page's lifetime,
 * so applying a just-previewed selection (showValuesetDetails() already
 * fetched and rendered its name) doesn't re-fetch get-valueset-info a second
 * time just for FHIR_ontology_changed()'s own label resolution.
 */
var valuesetNameCache = {};

/**
 * Non-null exactly while a get-valueset-info preview is in flight for this
 * URL (set synchronously when the request starts, cleared once it settles -
 * see showValuesetDetails()) - lets applyValuesetSelection() refuse to
 * commit a value whose preview hasn't actually come back yet, not just one
 * that's already known to have failed.
 */
var pendingPreviewUrl = null;

/**
 * Required callback REDCap core looks up by name (OntologyManager's
 * notifyOntologyProviders(), called from its own update_ontology_selection())
 * both when this provider's own selection is applied, and - critically - when
 * the field editor first opens for an already-configured field and core
 * seeds #ontology_auto_suggest from the field's saved element_enum. That
 * second case is the only hook this provider gets for "here's what's already
 * saved", so this must stay a plain global function core can find by name.
 */
function FHIR_ontology_changed(service, category) {
  if ('FHIR' !== service) {
    $('#fhir_selected_valueset').val('');
    $('#fhir_selected_valueset_label').text('No ValueSet selected');
    return;
  }
  $('#fhir_selected_valueset').val(category);
  $('#fhir_selected_valueset_label').text(category || 'No ValueSet selected');
  if (!category || !fhirOntologyModuleObject) {
    return;
  }
  if (valuesetNameCache[category]) {
    // Already resolved (typically by showValuesetDetails() previewing this
    // exact URL moments ago, just before "Use this ValueSet" was clicked) -
    // no need to ask the FHIR server again for a name we already have.
    $('#fhir_selected_valueset_label').text(valuesetNameCache[category]);
    return;
  }
  // Best-effort: resolve a human-readable name for display. A failure here is
  // silent - the raw URL already shown above is a valid, if less friendly, label.
  // Guard against the selection having moved on by the time this resolves.
  fhirOntologyModuleObject.ajax('get-valueset-info', {valueSet: category}).then(function (data) {
    if (data && !data.error && data.name && $('#fhir_selected_valueset').val() === category) {
      $('#fhir_selected_valueset_label').text(data.name);
      valuesetNameCache[category] = data.name;
    }
  }).catch(function () {});
}

function clearValuesetDetailFields() {
  $('#fhirValueSet_name').text('');
  $('#fhirValueSet_version').text('');
  $('#fhirValueSet_status').text('');
  $('#fhirValueSet_expansion_count').text('');
  $('#fhirValueSet_contains').empty();
}

function renderValuesetError(message) {
  clearValuesetDetailFields();
  // build via DOM - the message may echo text a project designer typed as the
  // valueset id/url, so it must never be concatenated into markup
  var $errorCell = $('<td>').addClass('data').attr('colspan', '3').text(message);
  $('#fhirValueSet_contains').append($('<tr>').addClass('error').append($errorCell));
}

function renderValuesetDetails(data) {
  $('#fhirValueSet_name').text(data.name || '');
  $('#fhirValueSet_version').text(data.version || '');
  $('#fhirValueSet_status').text(data.status || '');
  // expansion.total is a legitimate 0 for a genuinely empty expansion, which
  // `(data.expansion && data.expansion.total) || ''` would wrongly blank out.
  var total = data.expansion ? data.expansion.total : undefined;
  $('#fhirValueSet_expansion_count').text(typeof total === 'number' ? total : '');
  $('#fhirValueSet_contains').empty();
  if (data.expansion && data.expansion.contains) {
    for (var v of data.expansion.contains) {
      // build via DOM so server supplied text can never be parsed as markup
      var $row = $('<tr>');
      $row.append($('<td>').addClass('data').text(v.display));
      $row.append($('<td>').addClass('data').text(v.code));
      $row.append($('<td>').addClass('data').text(v.system));
      $('#fhirValueSet_contains').append($row);
    }
  }
}

/**
 * Fetches and displays details for valueSetUrl inside the dialog, without
 * touching the committed selection (#fhir_selected_valueset). Backs both
 * "pick a search result" and "type a URL directly", neither of which commits
 * anything until "Use this ValueSet" is clicked.
 *
 * get-valueset-info's success shape is either the parsed FHIR ValueSet
 * resource, or {error: "..."} for a domain-level failure (breaker open,
 * transport failure, malformed response) - that's a normal resolved payload,
 * not a rejection (module.ajax() only rejects for a framework-level failure,
 * e.g. verification).
 */
function showValuesetDetails(valueSetUrl) {
  $('#fhirValueSet_url').text(valueSetUrl || '');
  clearValuesetDetailFields();

  if (!valueSetUrl) {
    pendingPreviewUrl = null;
    return;
  }

  // Set synchronously, before the request goes out, so applyValuesetSelection()
  // can never observe a moment where this URL's preview looks neither pending
  // nor failed just because the response hasn't arrived yet.
  pendingPreviewUrl = valueSetUrl;

  fhirOntologyModuleObject.ajax('get-valueset-info', {valueSet: valueSetUrl}).then(function (data) {
    if (pendingPreviewUrl === valueSetUrl) {
      pendingPreviewUrl = null;
    }
    // Guard against a stale response: the user may have already picked a
    // different result or typed a different URL by the time this resolves,
    // in which case this response is no longer about what's on screen.
    if ($('#fhir_value_set_url').val() !== valueSetUrl) {
      return;
    }
    if (data && data.error) {
      renderValuesetError(data.error);
      return;
    }
    if (data.url) $('#fhirValueSet_url').text(data.url);
    renderValuesetDetails(data);
    if (data.name) {
      valuesetNameCache[valueSetUrl] = data.name;
    }
  }).catch(function (error) {
    if (pendingPreviewUrl === valueSetUrl) {
      pendingPreviewUrl = null;
    }
    if ($('#fhir_value_set_url').val() !== valueSetUrl) {
      return;
    }
    renderValuesetError(typeof error === 'string' ? error : 'The request could not be completed.');
  });
}

function openChangeDialog(event) {
  var current = $('#fhir_selected_valueset').val();
  $('#fhir_valueset_search_type').val('');
  $('#fhir_valueset_search').val('');
  $('#fhir_value_set_url').val(current);
  showValuesetDetails(current);
  $('#fhir_valueset_dialog').dialog('open');
  if (event) {
    event.preventDefault();
  }
  return false;
}

/**
 * The only place this module calls update_ontology_selection() - REDCap
 * core's own function, which sets #ontology_auto_suggest (the value actually
 * saved with the field) and, via notifyOntologyProviders(), immediately calls
 * FHIR_ontology_changed('FHIR', selected) back on this provider. That in turn
 * is what updates #fhir_selected_valueset and the summary label - there is no
 * need to set them here too.
 */
function applyValuesetSelection(event) {
  if (event) {
    event.preventDefault();
  }
  var selected = $.trim($('#fhir_value_set_url').val());
  // #fhirValueSet_url only reflects a non-stale preview (see the guard in
  // showValuesetDetails()), so this comparison is reliable: if the preview
  // for this exact text errored, the error is already visible in the dialog -
  // don't silently commit it anyway. Leave the dialog open so that error
  // stays visible, rather than closing over it.
  var previewFailed = $('#fhirValueSet_url').text() === selected && $('#fhirValueSet_contains tr.error').length > 0;
  // Also refuse while that same preview is still in flight (e.g. the user
  // typed a URL and clicked Apply before its get-valueset-info request even
  // returned) - otherwise an unverified value could slip through simply
  // because no error row exists *yet*.
  var previewPending = selected === pendingPreviewUrl;
  if (!selected || previewFailed || previewPending) {
    return false;
  }
  update_ontology_selection('FHIR', selected);
  $('#fhir_valueset_dialog').dialog('close');
  return false;
}

function cancelValuesetDialog(event) {
  $('#fhir_valueset_dialog').dialog('close');
  if (event) {
    event.preventDefault();
  }
  return false;
}

$(function () {
  var $app = $('#fhir_ontology_designer_app');
  if (!$app.length) {
    return;
  }
  fhirOntologyModuleObject = resolveGlobalByPath($app.data('module-object'));

  $('#fhir_valueset_change').on('click', openChangeDialog);
  $('#fhir_valueset_apply').on('click', applyValuesetSelection);
  $('#fhir_valueset_cancel').on('click', cancelValuesetDialog);

  $('#fhir_valueset_search_type').on('change', function () {
    $('#fhir_valueset_search').val('');
  });

  $('#fhir_value_set_url').on('change', function () {
    showValuesetDetails($.trim($(this).val()));
  });

  $('#fhir_valueset_search').autocomplete({
    source: function (request, response) {
      var search_type = $('#fhir_valueset_search_type').val();
      // findValueSet()'s success shape is a plain array of {label, value};
      // {error: "..."} (breaker open, transport failure, unknown type) and a
      // framework-level rejection are both treated as "no matches" here -
      // there's no result list UI in this widget to show an error in.
      fhirOntologyModuleObject.ajax('find-valueset', {query: request.term, type: search_type}).then(function (data) {
        var result = [];
        if (Array.isArray(data)) {
          for (var v of data) {
            result.push({label: v.label, value: v.value});
          }
        }
        if (!result.length) {
          result.push({label: 'No matches found', value: '__NMF__'});
        }
        response(result);
      }).catch(function () {
        response([{label: 'No matches found', value: '__NMF__'}]);
      });
    },
    select: function (event, ui) {
      event.preventDefault();
      if (ui.item.value !== '__NMF__') {
        $(this).val(ui.item.label);
        $('#fhir_value_set_url').val(ui.item.value);
        showValuesetDetails(ui.item.value);
        return true;
      }
      return false;
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

  $('#fhir_valueset_dialog').dialog({
    autoOpen: false,
    modal: true,
    width: 600
  });
});
