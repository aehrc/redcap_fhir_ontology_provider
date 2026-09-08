/**
 * Adds a hover tooltip showing the full value of any ontology-linked field on
 * a data entry or survey page. This is a bit of a hack, if REDCap change
 * their code it will break: it looks for all input fields tagged as
 * autosug-ont-field, which should mean they are an ontology lookup, and adds
 * a hover function which sets the field's title to match its value. This
 * gives a popup with the full value text shown instead of being restricted
 * by the size of the input field.
 *
 * Loaded (gated on the add_value_tooltip system setting) from both
 * redcap_data_entry_form() and redcap_survey_page() - identical on both page
 * types, so one shared file.
 */
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
