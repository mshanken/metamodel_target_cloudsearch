<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Generate_Cloudsearch extends Controller_Generate_Docs
{
    /**
     * Generate json domain definition for cloudsearch from all entities
     * 
     * This action adds the payload and entity fields required by Target_Cloudsearch
     */
    public function action_cloudsearchdomain() 
    {
        $files = $this->getImportantFiles( Kohana::list_files('classes/Entity'));
        $entities = array_keys($this->class_methods($files));
        $domain = array(
            'index_fields' => array(),
        );

        foreach($entities as $entity_class_name) 
        {
            if (class_exists($entity_class_name))
            {
                $entity = $entity_class_name::factory();
                if ($entity->get_root() instanceof Target_Cloudsearchable)
                {
                    $domain['index_fields'] = array_merge($domain['index_fields'], $this->build_entity_domain($entity));
                }
            }
        }
        
        $domain['index_fields'][Target_Cloudsearch::FIELD_PAYLOAD] = array(
            'type' => 'literal',
            'search_enabled' => FALSE,
            'facet_enabled' => FALSE,
            'result_enabled' => TRUE
        );
        
        $domain['index_fields'][Target_Cloudsearch::FIELD_ENTITY] = array(
            'type' => 'literal',
            'search_enabled' => TRUE,
            'facet_enabled' => FALSE,
            'result_enabled' => FALSE
        );
        
        $this->response->body(json_encode($domain));
    }
    
    /**
     * create cloudsearch domain definition for a single entity
     */
    protected function build_entity_domain(Entity_Row $entity) 
    {
        $cs = new Target_Cloudsearch();
        $field_definitions = array();
        $entity_name = $this->clean_field_name($entity->get_root()->get_name());
        
        foreach (array(Entity_Root::VIEW_KEY, Target_Cloudsearch::VIEW_INDEXER) as $view)
        {
            // $field_definitions = $this->do_indexer_fields($entity[$view], array($entity_name), $field_definitions);
            $field_definitions = $cs->targetize_fields($entity[$view], array($entity_name), $field_definitions, array($this,'type_transform'));
        }

        // general search field
        $text_field_name = sprintf('%s%s%s'
            , $entity_name
            , Target_Cloudsearch::DELIMITER
            , Target_Cloudsearch::UNIVERSAL_SEARCH_FIELD
        );

        $field_definitions[$text_field_name] = array(
            'type' => 'text',
            'facet_enabled' => FALSE,
            'result_enabled' => FALSE,
            'sources' => array(),
        );
 
        foreach ($field_definitions as $k => $v)
        {
            if ('text' == $v['type'])
            {
                $field_definitions[$text_field_name]['sources'][] = array(
                    'source_name' => $k,
                    'type' => 'copy',
                );
            }
        }

        return $field_definitions;
    }


    /**
     * field definition based on type and attributes 
     *
     * callback for Target_Cloudsearch::targetize_fields
     *
     * NOTE: normally $parent[$alias] = $type however array_pivots cannot be routed this way,
     * so we require that all three are passed in.
     *
     */
    public function type_transform(Entity_Base $parent, $type, $alias, $value)
    {
        // Pivot children
        if ($type instanceof Entity_Columnset_Join)
        {
            if (count($type) > 1) 
            {
                return array(
                    'type' => 'literal',
                    'search_enabled' => true,
                    'facet_enabled' => false,
                    'result_enabled' => false,   
                );
            } 
            $tmp = $type->get_children();
            $type = array_shift($tmp);
        }

        if ($type instanceof Type_Date || $type instanceof Type_Number)
        {  
            return array(
                'type' => 'uint',
                'search_enabled' => true,
                'facet_enabled' => true,
                'result_enabled' => true,   
            );
        }

        return array(
            'type' => ($parent->get_attribute(Target_Cloudsearch::ATTR_FREETEXT, $alias))? 'text' : 'literal',
            'search_enabled' => true,
            'facet_enabled' => $parent->get_attribute(Target_Cloudsearch::ATTR_FACET, $alias),
            'result_enabled' => $parent->get_attribute(Selector::SORTABLE, $alias),
        );
    }

    /**
     * clean name string to make them cloudsearch compliant
     * 
     * specifically removes dashes
     */
    protected function clean_field_name($name) 
    {
        $name = strtolower($name);
        $name = preg_replace('/-/', '_', $name);
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        return $name;
    }

}
