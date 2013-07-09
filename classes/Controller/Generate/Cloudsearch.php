<?php defined('SYSPATH') or die('No direct script access.');

define('GENERATED_FILE_EXT', '.basic.php');

class Controller_Generate_Cloudsearch extends Controller_Generate_Docs
{
    public function action_cloudsearchdomain() 
    {
        $gen = $this->build_polymorphic_domain();
        $this->response->body(json_encode($gen));
    }

    /**
     * helper for build_polymorphic domain()
     */
    private function clean_field_name($name) {
        $name = strtolower($name);
        $name = preg_replace('/-/', '_', $name);
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        return $name;
    }
    
    // make docs helper
    public function get_entity_class_names()
    {
        $files = $this->getImportantFiles( Kohana::list_files('classes/Entity'));
        //$entities = Kodoc::class_methods($files);
        $entities = $this->class_methods($files);
        return array_keys($entities);
    }

    /**
     * create cloudsearch domain definition
     */
    public function build_polymorphic_domain() 
    {
        $field_definitions = array();
        $text_sources = array();
        
        foreach($this->get_entity_class_names() as $entity_class_name) {
            $entity_class = new ReflectionClass($entity_class_name);
            $interface_names = $entity_class->getInterfaceNames();
            if(in_array('Target_Cloudsearchable', $interface_names))
            {
                $entity = $entity_class_name::factory();
                $entity_name = $entity->get_root()->get_name();
                $entity_name_cleaned = $this->clean_field_name($entity_name);
                
                foreach($entity['cloudsearch_facets'] as $column_name => $column) 
                {
                    $type = $entity['cloudsearch_facets'][$column_name];
                    while($type instanceof Entity_Array ) //&& count($type)) 
                    {
                        $type = $entity['cloudsearch_facets'][$column_name][0];    
                    }

                    if($type instanceof Entity_Columnset)
                    {
                        $field_definition = array('type' => 'literal',
                                                  'search_enabled' => FALSE,
                                                  'facet_enabled' => TRUE,
                                                  'result_enabled' => FALSE);
                    } 
                    else
                    {
                        $field_definition = array('type' => 'literal',
                                                  'search_enabled' => TRUE,
                                                  'facet_enabled' => TRUE,
                                                  'result_enabled' => FALSE);
                    }
                    $column_name_cleaned = $this->clean_field_name($column_name);
                    $field_name = $entity_name_cleaned . Target_Cloudsearch::DELIMITER . $column_name_cleaned;
                    
                    $field_definitions[$field_name] = $field_definition;
                }

                foreach(array('key', 'cloudsearch_indexer') as $view_name)
                {
                    foreach($entity[$view_name]->get_children() as $column_name => $type) 
                    {
                        while ($type instanceof Entity_Array || $type instanceof Entity_Pivot)
                        {
                            $temporary = $type->get_children();
                            $type = $temporary[0];
                        }
    
                        if($type instanceof Type_FreeText)
                        {
                            $field_definition = array('type' => 'text',
                                                      'facet_enabled' => FALSE,
                                                      'result_enabled' => FALSE);
                        } 
                        else if ($type instanceof Type_Number)
                        {
                            $field_definition = array('type' => 'uint');
                        } 
                        else 
                        {
                            if (($type instanceof Type_WineColor)
                                || ($type instanceof Type_WineType))
                            {
                                $facet_enabled = TRUE;
                            }
                            else
                            {
                                $facet_enabled = FALSE;
                            }
                            
                            $field_definition = array('type' => 'literal',
                                                      'search_enabled' => TRUE,
                                                      'facet_enabled' => $facet_enabled,
                                                      'result_enabled' => FALSE);
                        }
                        /*
                        $field_definition['debug'] = array(
                            'type' => $type,
                            'column' => $column_name,
                            'gettype' => get_class($type),
                        );
                        */
                        
                        $column_name_cleaned = $this->clean_field_name($column_name);
                        $field_name = $entity_name_cleaned . Target_Cloudsearch::DELIMITER . $column_name_cleaned;
                        
                        if($field_definition['type'] == 'text')
                            $text_sources[] = $field_name;
                        
                        $field_definitions[$field_name] = $field_definition;
                    }
                }
            }
        }
        
        $field_definitions['text'] =
            array('type' => 'text',
                  'facet_enabled' => FALSE,
                  'result_enabled' => FALSE,
                  'sources' => array());
        foreach($text_sources as $source) {
            $field_definitions['text']['sources'][] =
                array('source_name' => $source, 'type' => 'copy');
        }
        
        $field_definitions['payload'] =
            array('type' => 'literal',
                  'search_enabled' => FALSE,
                  'facet_enabled' => FALSE,
                  'result_enabled' => TRUE);
        
        $field_definitions['entity'] =
            array('type' => 'literal',
                  'search_enabled' => TRUE,
                  'facet_enabled' => FALSE,
                  'result_enabled' => FALSE);
        
        $domain_definition = array('index_fields' => $field_definitions);
    
        return $domain_definition;
    }

}
