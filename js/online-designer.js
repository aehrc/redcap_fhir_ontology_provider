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
 * true exactly while a find-valueset autocomplete search is in flight.
 * REDCap core's own JSMO ajax() client (ExternalModules.__ajaxQueue) queues
 * EVERY module.ajax() call for the whole browser tab and runs them strictly
 * one at a time - each response's rotating verification token feeds the next
 * request, so this isn't just a soft duplicate-request guard, core genuinely
 * cannot run them concurrently. Firing one real request per keystroke against
 * a slow terminology server (a SNOMED CT "isa" search, say) would queue up
 * several multi-second real network calls back to back, making the search
 * box look stuck for a long burst of typing. runFindValuesetSearch() and
 * pendingFindValuesetRequest below coalesce a typing burst into at most one
 * in-flight request plus one queued follow-up using the latest term, instead
 * of one request per keystroke.
 */
var findValuesetSearchInFlight = false;

/** The most recent {request, response} superseded while a search was already
 * in flight - run once that search settles, per the note above. */
var pendingFindValuesetRequest = null;

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
  if (Object.prototype.hasOwnProperty.call(valuesetNameCache, category)) {
    // Already resolved (typically by showValuesetDetails() previewing this
    // exact URL moments ago, just before "Use this ValueSet" was clicked) -
    // no need to ask the FHIR server again for an answer we already have,
    // even if that answer was "this ValueSet has no name".
    if (valuesetNameCache[category]) {
      $('#fhir_selected_valueset_label').text(valuesetNameCache[category]);
    }
    return;
  }
  // Best-effort: resolve a human-readable name for display. A failure here is
  // silent - the raw URL already shown above is a valid, if less friendly, label.
  // Guard against the selection having moved on by the time this resolves.
  fhirOntologyModuleObject.ajax('get-valueset-info', {valueSet: category}).then(function (data) {
    if ($('#fhir_selected_valueset').val() !== category) {
      return;
    }
    if (data && !data.error) {
      valuesetNameCache[category] = data.name || null;
      if (data.name) {
        $('#fhir_selected_valueset_label').text(data.name);
      }
    }
  }).catch(function () {});
}

function clearValuesetDetailFields() {
  $('#fhirValueSet_name').text('');
  $('#fhirValueSet_version').text('');
  $('#fhirValueSet_status').text('');
  $('#fhirValueSet_expansion_count').text('');
  $('#fhirValueSet_contains').empty();
  clearOntologyOptionsRecommendation();
}

/** URL shapes findValueSet() itself produces that are always exactly one code
 * system, regardless of how many entries the ValueSet expands to - a SNOMED
 * CT implicit valueset (refset or isa) is always SNOMED CT; a LOINC implicit
 * answer list is always LOINC. */
var SINGLE_SYSTEM_URL_PATTERNS = [
  /^http:\/\/snomed\.info\/sct\?fhir_vs=/,
  /^http:\/\/loinc\.org\/vs\//
];

/**
 * Recommends an @FHIR-ONTOLOGY-OPTIONS tag for this ValueSet from data the dialog
 * already has (no extra request), or null if neither option clearly applies.
 * Two independent signals, each contributing its own option to the tag:
 *
 *  - return-all: recommended when expansion.total is known and small enough
 *    (<= 20, matching this module's own default result_limit) that an
 *    unfiltered fetch can genuinely return every entry in one go.
 *  - code-template=${CODE}: recommended only when every entry is CONFIRMED to
 *    share one code system - either the URL matches a known single-system
 *    shape above (true regardless of size), or every entry get-valueset-info
 *    actually returned shares one system AND expansion.total says that's
 *    every entry there is (get-valueset-info caps at 10, so for a larger
 *    ValueSet whose URL doesn't match a known shape, this can only ever see a
 *    partial sample - no recommendation is made rather than guessing from it).
 */
function computeOntologyOptionsRecommendation(valueSetUrl, data) {
  var messages = [];
  var tagOptions = [];

  var total = data.expansion ? data.expansion.total : undefined;
  if (typeof total === 'number' && total <= 20) {
    messages.push('This ValueSet only has ' + total + ' ' + (total === 1 ? 'entry' : 'entries') + ' - we advise using the "return-all" option to make it easier to browse and select without needing to match the exact wording.');
    tagOptions.push('return-all');
  }

  var contains = (data.expansion && data.expansion.contains) || [];
  var confirmedSingleSystem = SINGLE_SYSTEM_URL_PATTERNS.some(function (pattern) {
    return pattern.test(valueSetUrl);
  });
  if (!confirmedSingleSystem && contains.length > 0 && typeof total === 'number' && total <= contains.length) {
    confirmedSingleSystem = contains.every(function (entry) {
      return entry.system === contains[0].system;
    });
  }
  if (confirmedSingleSystem) {
    messages.push('All entries in this ValueSet use the same code system - we advise using the "code-template=${CODE}" option to store just the code, rather than code and system.');
    tagOptions.push('code-template=${CODE}');
  }

  if (!tagOptions.length) {
    return null;
  }
  return {messages: messages, tag: "@FHIR-ONTOLOGY-OPTIONS='" + tagOptions.join(';') + "'"};
}

function clearOntologyOptionsRecommendation() {
  $('#fhir_ontology_recommendation').hide();
  $('#fhir_ontology_recommendation_text').empty();
  $('#fhir_ontology_recommendation_tag').text('');
  $('#fhir_ontology_recommendation_copy_feedback').text('');
}

function renderOntologyOptionsRecommendation(valueSetUrl, data) {
  var recommendation = computeOntologyOptionsRecommendation(valueSetUrl, data);
  if (!recommendation) {
    clearOntologyOptionsRecommendation();
    return;
  }
  var $text = $('#fhir_ontology_recommendation_text').empty();
  for (var message of recommendation.messages) {
    // build via DOM - nothing here is server-supplied, but stay consistent
    // with this file's own convention of never building markup from strings
    $text.append($('<div>').text(message));
  }
  $('#fhir_ontology_recommendation_tag').text(recommendation.tag);
  $('#fhir_ontology_recommendation_copy_feedback').text('');
  $('#fhir_ontology_recommendation').show();
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

  if (valueSetUrl === pendingPreviewUrl) {
    // Already fetching this exact URL (e.g. Change -> Cancel -> Change again
    // before the first request settled) - that request's own .then()/.catch()
    // will still render the result once it lands, so there is nothing this
    // second call needs to kick off.
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
    renderOntologyOptionsRecommendation(data.url || valueSetUrl, data);
    valuesetNameCache[valueSetUrl] = data.name || null;
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

/**
 * Runs one find-valueset search and reports its result to jQuery UI
 * autocomplete via response(). While it's in flight, further source() calls
 * are coalesced into pendingFindValuesetRequest rather than firing their own
 * request - see findValuesetSearchInFlight's docblock. Once this one settles,
 * runs exactly one follow-up for the latest superseded request, if any.
 */
function runFindValuesetSearch(request, response) {
  var search_type = $('#fhir_valueset_search_type').val();
  findValuesetSearchInFlight = true;
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
  }).then(function () {
    findValuesetSearchInFlight = false;
    if (pendingFindValuesetRequest) {
      var next = pendingFindValuesetRequest;
      pendingFindValuesetRequest = null;
      runFindValuesetSearch(next.request, next.response);
    }
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

  $('#fhir_ontology_recommendation_copy').on('click', function () {
    var tag = $('#fhir_ontology_recommendation_tag').text();
    var $feedback = $('#fhir_ontology_recommendation_copy_feedback');
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
      $feedback.text('Copy not supported - select and copy the text manually.');
      return;
    }
    navigator.clipboard.writeText(tag).then(function () {
      $feedback.text('Copied!');
    }).catch(function () {
      $feedback.text('Could not copy - select and copy the text manually.');
    });
  });

  $('#fhir_valueset_search_type').on('change', function () {
    $('#fhir_valueset_search').val('');
  });

  $('#fhir_value_set_url').on('change', function () {
    showValuesetDetails($.trim($(this).val()));
  });

  $('#fhir_valueset_search').autocomplete({
    source: function (request, response) {
      if (findValuesetSearchInFlight) {
        // A search is already queued/in flight - see
        // findValuesetSearchInFlight's docblock. Answer any previously
        // superseded request with an empty result first: jQuery UI's
        // autocomplete tracks its own "pending" count per response() call, so
        // every source() invocation needs its response() called eventually,
        // even one this coalescing decides never to actually run.
        if (pendingFindValuesetRequest) {
          pendingFindValuesetRequest.response([]);
        }
        pendingFindValuesetRequest = {request: request, response: response};
        return;
      }
      runFindValuesetSearch(request, response);
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
    width: 600,
    // Safety net on top of the results table's own scroll region (added
    // separately in the markup) - jQuery UI makes the dialog's content area
    // scrollable once it would exceed this, so a small viewport (or any
    // future content growth) still leaves the footer buttons reachable
    // instead of pushing them off-screen.
    maxHeight: Math.max(400, $(window).height() - 100)
  });
});
