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
    private $facet_constraints = array();
    
    public function __construct() 
    {
    }
    
    // textual ?
    public function set_numeric($field_name) {
        $this->field_types[$field_name] = 'numeric';
    }
    
    public function is_numeric($field_name) {
        return array_key_exists($field_name, $this->field_types)
               && ($this->field_types[$field_name] == 'numeric');
    }
    

    // handle bracket facets
    public function get_facet_constraints()
    {
        return $this->facet_constraints;
    }

    public function set_facet_constraints($field, array $constraints)
    {
        $this->facet_constraints[$field] = $constraints;
    }

    // @TODO
    public function validate(Entity_Root $root)
    {
        if(!($root[Target_Cloudsearch::VIEW_PAYLOAD] instanceof Entity_Columnset)) return false;
        if(!($root[Target_Cloudsearch::VIEW_FACETS] instanceof Entity_Columnset)) return false;
        if(!($root[Target_Cloudsearch::VIEW_INDEXER] instanceof Entity_Columnset)) return false;
        
        return true;
    }
}

