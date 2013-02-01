<?php
/**
 * cloudsearchable.php
 * 
 * @package Metamodel
 * @subpackage Entity
 * @author dchan@mshanken.com
 * 
 **/


/**
 * Interface for entities which use cloudsearch
 *
 * cloudsearch has 3 field aspects: 
 *    searchable (text or exact)
 *    facetable
 *    returnable
 *
 * @see Entity
 * @see Target_Cloudsearch
 * @package Metamodel
 * @subpackage Entity
 */
Interface Target_Cloudsearchable
{
    function view_key();
    function view_timestamp();
    
    // this information describes search and or exact and or bracket 
    // facet fields, but not facets
    // used for querying, indexing, and domain building
    // NOT returnable
    function view_cloudsearch_indexer();

    // this information describes search and or fields which are 
    // non-bracket facets
    // used for querying, indexing, and domain building
    // NOT returnable
    function view_cloudsearch_facets();

    // this information is retrieved from cloudsearch results 
    // used for querying, indexing
    // returnable
    function view_cloudsearch_payload();


    /**
     * This method returns a configured Target_Info_CloudSearch object
     * which is suitable for use in indexing the entity into CloudSearch.
     */
    function target_cloudsearch_info();
}



