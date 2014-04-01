<?php //defined('SYSPATH') or die('No direct access allowed.');
/**
 * target/cloudsearch/id.php
 * 
 * this target will use entity[keys] to retrieve values from the db/memcache
 * instead of relying on cloudsearch payload field.
 *
 * @package Metamodel
 * @subpackage Target
 * @author dchan@mshanken.com
 *
 **/

class Metamodel_Target_Cloudsearch_Id
extends Metamodel_Target_Cloudsearch
{
    protected $key_map = null;
    protected $db_target;

    public function __construct()
    {
        $this->db_target = new Target_Pgsql_Memcache();
    }

    public function select(Entity_Row $entity, Selector $selector = null) 
    {
        $this->key_map = array();
        foreach ($entity[Entity_Root::VIEW_KEY] as $key => $value)
        {
            $this->key_map[$key] = sprintf('%s%s%s', $entity->get_root()->get_name(), Target_Cloudsearch::DELIMITER, $key);
        }

        return parent::select($entity, $selector); 
    }

    public function columnize(Entity_Row $entity, array $document) 
    {
        $selector = new Selector();

        foreach ($this->key_map as $key => $alias)
        {
            $selector->exact($key, array_shift($document[$alias]));
        }

        $results = $this->db_target->select($entity, $selector);
        return array_pop($results);
    }


    /**
     * select_helper_query_facet
     *
     * overrridable helper to build query[return-fields] for select()
     *
     * @param mixed $entity
     * @access protected
     * @array void
     */
    protected function select_helper_query_return($entity)
    {
        return array_values($this->key_map);
    }
    
}
