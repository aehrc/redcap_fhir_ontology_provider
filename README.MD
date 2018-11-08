# FHIR Ontology External Module

As part of release 8.8.1 of REDCap an extension point was added to allow external modules to become an 
*'Ontology Provider'*. These act like the existing BioPortal ontology mechanism, but allow alternative sources.
The main function of an ontology provider is to take a search term and return some match of code + display.

This module allows a FHIR based terminology server to be an alternative ontology provider.

This is done using the ValueSet/$expand operation



## Using the module
The module code needs to be places in a directory modules/fhir-ontoloy_v0.1

The module should then show up as an external module.

Besides exposing the module to projects, the only setting for the module is the url for the fhir server. The default value should work out of the box, but people may want to run their own server to have better control of the available ValueSets.

### Online designer

Once enabled the online designer will have a new ontology source availble. If selected the follow UI elements will be made available:

Search For valuset using: (dropdown with the options)
    - ValueSet Name - searching using the name of the valueset
    - By CodeSystem - searching using the name of the codesystem
    - SnomedCT Refset - search for a SnomedCT Refset
    - SnomedCT isa implicit valueset - search for a SnomedCT concept and use the valueset composed of it and its children
    - LOINC implicit answer set - search for a LOINC implicit answer set
    
text input with autocomplete based on the search mode

select button - Select the valueset found using the search, making it the selected valuset

text input which is filled out by the search button, contains the uri for the selected valueset

Show details button - Retrieve the first 10 entries of the selected valueset, and display along with other information about the valueset in a dialog.


## Fhir based Terminolgy Service

The FHIR terminology specification is based on two key concepts, originally defined in HL7 v3 Core Principles : 

- *code system* - defines a set of codes with meanings (also known as enumeration, terminology, classification, and/or ontology) 

- *value set* - selects a set of codes from those defined by one or more code systems 
Code systems define which codes (symbols and/or expressions) exist, and how they are understood. Value Sets select a set of codes from one or more code systems to specify which codes can be used in a particular context. 


Implicit value sets are those whose specification can be predicted based on the grammar of the underlying code system, and the known structure of the URL that identifies them. Both SNOMED CT and LOINC define implicit value sets. LOINC defines implicit value set for answer lists, SNOMED CT has two common sets of implicit value sets defined: By Subsumption, and By Reference Set.

A SNOMED CT implicit value set URL has two parts: 
- the base URL is either "http://snomed.info/sct", or the URI for the edition version, in the format specified by the IHTSDO the SNOMED CT URI Specification 
- a query portion that specifies the scope of the content 

"http://snomed.info/sct" should be understood to mean an unspecified edition/version. This defines an incomplete value set whose actual membership will depend on the particular edition used when it is expanded. If no version or edition is specified, the terminology service SHALL use the latest version available for its default edition (or the international edition, if no other edition is the default). 


For the second part of the URL (the query part), the 4 possible values are: 
- *?fhir_vs* - all Concept IDs in the edition/version. If the base URI is http://snomed.info/sct, this means all possible SNOMED CT concepts 
- *?fhir_vs=isa/[sctid]* - all concept IDs that are subsumed by the specified Concept. 
- *?fhir_vs=refset* - all concept ids that correspond to real references sets defined in the specified SNOMED CT edition 
- *?fhir_vs=refset/[sctid]* - all concept IDs in the specified reference set


To explore SnomedCT check out Shrimp http://ontoserver.csiro.au/shrimp
