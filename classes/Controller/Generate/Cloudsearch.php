<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Generate_Cloudsearch extends Controller_Generate_Docs
{

    protected function copy_sources($field, $sources)
    {
        if (!empty($sources))
        {
            $field['SourceAttributes'] = array();
            foreach ($sources as $s)
            {
                $field['SourceAttributes'][] = array (
                    'SourceDataFunction' => 'Copy',
                    'SourceDataCopy' => array (
                        'SourceName' => $s,
                    ),
                );
            }
        }
        return $field;
    }

    /**
     * literal—a literal field contains an identifier or other data that you want to be able to match exactly.
     *
     * The value of a literal field can be returned in search results or the field can be used as a facet, but not both. 
     * By default, literal fields are not search-enabled, result-enabled, or facet-enabled. 
     *
     * if both facet is required we use a non-stemming text field
     */
    protected function literal_field($name, $facet = false, $result = false, $default = null) 
    {
        if ($facet)
        {
            return $this->text_field($name, $facet, $result, false, $default);
        }

        $tmp = array (
            'IndexFieldName' => $name,
            'IndexFieldType' => 'literal',
            'LiteralOptions' => array (
                'SearchEnabled' => !$facet,
                'FacetEnabled'  => $facet,
                'ResultEnabled' => $result,
            ),
        );
        if (!is_null($default)) $tmp['LiteralOptions']['DefaultValue'] = $default;

        return $tmp;
    }

    /**
     * text—a text field contains arbitrary alphanumeric data. A text field is always searchable. 
     *
     * The value of a text field can either be returned in search results or the field can be used as a facet. 
     * By default, text fields are neither result-enabled or facet-enabled.
     */
    protected function text_field($name, $facet = false, $result = false, $stemming = true, $default = null) 
    {
        $tmp = array (
            'IndexFieldName' => $name,
            'IndexFieldType' => 'text',
            'TextOptions' => array (
                'FacetEnabled'  => $facet,
                'ResultEnabled' => $result,
            ),
        );
        
        if (!$stemming) $tmp['TextOptions']['TextProcessor'] = 'cs_text_no_stemming';
        if (!is_null($default)) $tmp['TextOptions']['DefaultValue'] = $default;
        return $tmp;
    }

    /**
     * uint—a uint field contains an unsigned integer value. 
     *
     * Uint fields are always searchable, the value of a uint field can always be returned in results, and faceting is always enabled. 
     * Uint fields can also be used in rank expressions.
     */
    protected function uint_field($name, $default = null)
    {
        $tmp = array (
            'IndexFieldName' => $name,
            'IndexFieldType' => 'literal',
            'UIntOptions' => array (),
        );

        if (!is_null($default)) $tmp['LiteralOptions']['DefaultValue'] = $default;
        return $tmp;
    }




    /**
     * Generate json domain definition for cloudsearch from all entities
     * 
     * This action adds the payload and entity fields required by Target_Cloudsearch
     */
    public function action_cloudsearchdomain() 
    {
        $files = $this->getImportantFiles( Kohana::list_files('classes/Entity'));
        $entities = array_keys($this->class_methods($files));
        $domain_name = $this->request->param('domain');
        $domain = array(
            $domain_name => array(),
        );

        foreach($entities as $entity_class_name) 
        {
            if (class_exists($entity_class_name))
            {
                $entity = $entity_class_name::factory();
                if ($entity->get_root() instanceof Target_Cloudsearchable)
                {
                    $domain[$domain_name] = array_merge($domain[$domain_name], $this->build_entity_domain($entity));
                }
            }
        }
        
        $domain[$domain_name][Target_Cloudsearch::FIELD_PAYLOAD] = $this->literal_field(Target_Cloudsearch::FIELD_PAYLOAD, false, false);
        $domain[$domain_name][Target_Cloudsearch::FIELD_ENTITY] = $this->literal_field(Target_Cloudsearch::FIELD_ENTITY, false, false);
        
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
            $field_definitions = $cs->targetize_fields($entity[$view], array($entity_name), $field_definitions, array($this,'type_transform'));
        }

        // general search field
        $universal_field = sprintf('%s%s%s'
            , $entity_name
            , Target_Cloudsearch::DELIMITER
            , Target_Cloudsearch::UNIVERSAL_SEARCH_FIELD
        );

        $copy = array();
        foreach ($field_definitions as $k => $v)
        {
            if ($v['IndexFieldType'] == 'text')
            {
                $copy[] = $v['IndexFieldName'];
            }
        }
        $field_definitions[$universal_field] = $this->copy_sources($this->text_field($universal_field, false, false), $copy);

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
    public function type_transform(Entity_Base $parent, $type, $alias, $value, $fields, $field_name)
    {

        // Pivot children
        if ($type instanceof Entity_Columnset_Join)
        {
            if (count($type) > 1) 
            {
                return $this->literal_field($field_name, false, false);
            }
            $parent = $type;
            $tmp = $type->get_children();
            $type = array_shift($tmp);
        }

        if ($type instanceof Type_Date || $type instanceof Type_Number)
        {  
            return $this->uint_field($field_name);
        }
    
        if ($parent->get_attribute(Target_Cloudsearch::ATTR_FREETEXT, $alias))
        {
            return $this->text_field($field_name
                , $parent->get_attribute(Target_Cloudsearch::ATTR_FACET, $alias)
                , $parent->get_attribute(Selector::SORTABLE, $alias)
            );
        }
        return $this->literal_field($field_name
            , $parent->get_attribute(Target_Cloudsearch::ATTR_FACET, $alias)
            , $parent->get_attribute(Selector::SORTABLE, $alias)
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
