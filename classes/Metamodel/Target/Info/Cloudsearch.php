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
class Metamodel_Target_Info_Cloudsearch extends Target_Info
{
    /**
     * entity_name 
     *
     * @var mixed
     * @access private
     */
    private $entity_name = NULL;
    
    /**
     * field_types 
     *
     * @var array
     * @access private
     */
    private $field_types = array();

    /**
     * facet_maps 
     *
     * @var array
     * @access private
     */
    private $facet_maps = array();
    
    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() 
    {
    }
    
    /**
     * validate
     *
     * @param Entity_Root $root
     * @access public
     * @return void
     */
    public function validate(Entity_Root $root)
    {
        if (!($root instanceof Target_Cloudsearchable)) return false;
        if(!($root[Target_Cloudsearch::VIEW_PAYLOAD] instanceof Entity_Columnset)) return false;
        if(!($root[Target_Cloudsearch::VIEW_INDEXER] instanceof Entity_Columnset)) return false;
        
        return true;
    }
}

