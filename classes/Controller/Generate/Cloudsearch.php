<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Generate_Cloudsearch extends Controller_Generate_Docs
{

    private $info = null;

    protected function source_map($field, $view)
    {   
        $field_name = $field['IndexFieldName'];
        $map_field_name = sprintf('%s_%s', $field_name, Target_Cloudsearch::ATTR_FACET_MAP);

        $column_name = explode(Target_Cloudsearch::DELIMITER, $field_name);
        $column_name = array_pop($column_name);

        if ($map = $view->get_attribute(Target_Cloudsearch::ATTR_FACET_MAP, $column_name)) 
        {
            $field = $this->literal_field($map_field_name, true, false, false);
        
            $field[$map_field_name]['SourceAttributes'][] = array(
                'SourceDataFunction' => 'Map',
                'SourceDataMap' => array (
                    'Cases' => $map['cases'],
                    'DefaultValue' => $map['default'],
                    'SourceName' => $field_name,
                ),
            );
            return $field;
        }

        return array($field_name => $field);
    }


    protected function source_copy($field, $sources)
    {
        if (count($sources) > 0)
        {
            if (!array_key_exists('SourceAttributes', $field)) $field['SourceAttributes'] = array();

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
     * but not both!
     * BUT NOT BOTH!
     *
     * By default, literal fields are not search-enabled, result-enabled, or facet-enabled. 
     *
     * if both facet is required we use a non-stemming text field
     */
    protected function literal_field($name, $facet = false, $result = false, $search = true, $default = null) 
    {
        $ret = array($name => null);
        if ($facet && $result)
        {
            $facet_name = sprintf('%s_%s', $name, Target_Cloudsearch::ATTR_FACET);
            $facet_field = $this->literal_field($facet_name, true, false, false, null);
            $facet_field[$facet_name] = $this->source_copy($facet_field[$facet_name], array($name));

            $ret += $facet_field;
            $facet = false;
            $result = true;
        }

        $field = array (
            'IndexFieldName' => $name,
            'IndexFieldType' => 'literal',
            'LiteralOptions' => array (
                'SearchEnabled' => $search, 
                'FacetEnabled'  => $facet,
                'ResultEnabled' => $result,
            ),
        );
        if (!is_null($default)) $field['LiteralOptions']['DefaultValue'] = $default;

        $ret[$name] = $field;

        return $ret;
    }

    /**
     * text—a text field contains arbitrary alphanumeric data. A text field is always searchable. 
     *
     * The value of  a text field can either be returned in search results or the field can be used as a facet. 
     * By default, text fields are neither result-enabled or facet-enabled.
     */
    protected function text_field($name, $facet = false, $result = false, $stemming = true, $default = null) 
    {
        $ret = array($name => null);
        if ($facet && $result)
        {
            $facet_name = sprintf('%s_%s', $name, Target_Cloudsearch::ATTR_FACET);
            $facet_field = $this->literal_field($facet_name, true, false, false, null);
            $facet_field[$facet_name] = $this->source_copy($facet_field[$facet_name], array($name));
            $facet = false;
            $result = true;
            $ret += $facet_field;
        }

        $field = array (
            'IndexFieldName' => $name,
            'IndexFieldType' => 'text',
            'TextOptions' => array (
                'FacetEnabled'  => $facet,
                'ResultEnabled' => $result,
            ),
        );
        
        if (!$stemming) $field['TextOptions']['TextProcessor'] = 'cs_text_no_stemming';
        if (!is_null($default)) $field['TextOptions']['DefaultValue'] = $default;
        
        $ret[$name] = $field;    
    
        return $ret;
    }

    /**
     * uint—a uint field contains an unsigned integer value. 
     *
     * Uint fields are always searchable, the value of a uint field can always be returned in results, and faceting is always enabled. 
     * Uint fields can also be used in rank expressions.
     */
    protected function uint_field($name, $default = null)
    {
        $field = array (
            'IndexFieldName' => $name,
            'IndexFieldType' => 'uint',
            'UIntOptions' => array (),
        );

        if (!is_null($default)) $field['UintOptions']['DefaultValue'] = $default;

        $ret = array($name=>$field);

        return $ret;
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
        
        $domain[$domain_name] += $this->literal_field(Target_Cloudsearch::FIELD_PAYLOAD, false, true, false);   // only return
        $domain[$domain_name] += $this->literal_field(Target_Cloudsearch::FIELD_ENTITY, false, false, true);    // only search
        
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

        // create a facet_map field if needed
        $info = $entity->get_root();
        foreach ($field_definitions as $k => $v)
        {
            $field_definitions += $this->source_map($v, $info[Target_Cloudsearch::VIEW_INDEXER]);
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
            if (!array_key_exists('IndexFieldType', $v)) var_dump($k);

            if ($v['IndexFieldType'] == 'text')
            {
                $copy[] = $v['IndexFieldName'];
            }
        }

        if (count($copy))
        {
            $field_definitions += $this->text_field($universal_field, false, false);
            $field_definitions[$universal_field] = $this->source_copy($field_definitions[$universal_field], $copy);
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
    public function type_transform(Entity_Structure $parent, $type, $alias, $value, $fields, $field_name)
    {

        // Pivot children
        if ($type instanceof Entity_Columnset_Join)
        {
            if (count($type) > 1) 
            {
                return $this->literal_field($field_name, false, false, true);
            }
            $parent = $type;
            $tmp = $type->get_children();
            $type = array_shift($tmp);
        }

        // @TODO bools should be in too ?

        if ($type instanceof Type_Date || $type instanceof Type_Number)
        {  
            // var_export($this->uint_field($field_name));
            return $this->uint_field($field_name);
        }
    
        if ($parent->get_attribute(Selector::ATTR_TEXT_SEARCH, $alias))
        {
            return $this->text_field($field_name
                , $parent->get_attribute(Target_Cloudsearch::ATTR_FACET, $alias)
                , $parent->get_attribute(Selector::ATTR_SORTABLE, $alias)
            );
        }
        return $this->literal_field($field_name
            , $parent->get_attribute(Target_Cloudsearch::ATTR_FACET, $alias)
            , $parent->get_attribute(Selector::ATTR_SORTABLE, $alias)
            , true
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
