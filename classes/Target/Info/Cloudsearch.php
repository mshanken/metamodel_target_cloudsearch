<?php //defined('SYSPATH') or die('No direct access allowed.');
/**
 * target/info/cloudsearch.php
 * 
 * @package Metamodel
 * @subpackage Target_Info_CloudSearch
 * @author dknapp@mshanken.com
 *
 **/

/**
 * Additional information needed by CloudSearch target.
 *
 * @package Metamodel
 * @subpackage Target_Info
 * @author dchan@mshanken.com
 */
class Target_Info_CloudSearch
extends Target_Info
{
    private $entity_name = NULL;
    private $id_field = NULL;
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
    
    // key view ?  id_for_backbone ? 
    public function set_id_field($field_name) {
        $this->id_field = $field_name;
    }
    
    public function get_id_field() {
        return $this->id_field;
    }
    
    public function set_domain_name($domain_name) {
        $this->domain_name = $domain_name;
    }
    
    public function get_domain_name() {
        return $this->domain_name;
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
}

