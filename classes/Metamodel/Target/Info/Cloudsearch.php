<?php //defined('SYSPATH') or die('No direct access allowed.');
/**
 * target/info/cloudsearch.php
 * 
 * @package Metamodel
 * @subpackage Target_Info_Cloudsearch
 * @author dknapp@mshanken.com
 *
 **/

/**
 * Additional information needed by Cloudsearch target.
 *
 * @package Metamodel
 * @subpackage Target_Info
 * @author dchan@mshanken.com
 */
Class Metamodel_Target_Info_Cloudsearch
extends Target_Info
{
    private $entity_name = NULL;
    private $field_types = array();
    private $facet_maps = array();
    
    public function __construct() 
    {
    }
    
    // @TODO
    public function validate(Entity_Root $root)
    {
        if(!($root[Target_Cloudsearch::VIEW_PAYLOAD] instanceof Entity_Columnset)) return false;
        if(!($root[Target_Cloudsearch::VIEW_INDEXER] instanceof Entity_Columnset)) return false;
        
        return true;
    }
}

