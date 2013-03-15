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
    /**
     * This method returns a configured Target_Info_Cloudsearch object
     * which is suitable for use in indexing the entity into Cloudsearch.
     */
    function target_cloudsearch_info();
}



