<?php //defined('SYSPATH') or die('No direct access allowed.');

use Aws\Common\Aws;
use Aws\CloudSearch;


/**
 * Metamodel_Target_Cloudsearch
 *
 * @uses Target_Selectable
 * @package 
 * @version $id$
 * @copyright 2013 Mshanken Communications
 * @author dknapp@mshanken.com
 * @license BSD-3 {@link https://github.com/mshanken/metamodel/blob/master/LICENSE.md}
 */
class Metamodel_Target_Cloudsearch implements Target_Selectable
{
    /**
     * VIEW_PAYLOAD 
     *
     * @const string
     */
    const VIEW_PAYLOAD = 'cloudsearch_payload';

    /**
     * VIEW_INDEXER 
     *
     * @const string
     */
    const VIEW_INDEXER = 'cloudsearch_indexer';

    /**
     * DELIMITER 
     *
     * @const string
     */
    const DELIMITER = '__x__';

    /**
     * ATTR_FACET 
     *
     * @const string
     */
    const ATTR_FACET = 'do_facet';
    
    /**
     * ATTR_FACET_MAP 
     *
     * @const string
     */
    const ATTR_FACET_MAP = 'do_facet_map';

    /**
     * ATTR_FACET_MAP 
     *
     * @const string
     */
    const ATTR_FACET_RANGE = 'do_facet_range';

    /**
     * FIELD_PAYLOAD 
     *
     * @const string
     */
    const FIELD_PAYLOAD = 'cst_payload';
    
    /**
     * FIELD_ENTITY 
     *
     * @const string
     */
    const FIELD_ENTITY = 'cst_entity';

    /**
     * UNIVERSAL_SEARCH_FIELD 
     *
     * @const string
     */
    const UNIVERSAL_SEARCH_FIELD = 'cst_universal_search';

    /**
     * AWS Domain Description
     *
     * search / document url and arn info
     */
    protected $domain_description = null;

    /**
     * facets 
     *
     * @var mixed
     * @access protected
     */
    protected $facets = null;

    /**
     * select_count 
     *
     * @var mixed
     * @access protected
     */
    protected $select_count = null;
    
    protected static $elapsed = null;
    
    /**
     * select
     *
     * @see Entity_Selectable
     * @param Entity_Row $entity
     * @param Selector $selector
     * @access public
     * @return void
     */
    public function select(Entity_Row $entity, Selector $selector = null)
    {
        $query_parameters = array(
            // @TODO use visit_exact()
            'bq' => sprintf('%s:"%s"'
                , Target_Cloudsearch::FIELD_ENTITY
                , strtr($entity->get_root()->get_name(), array("'" => "\\\'",'\\' => '\\\\'))
            ),
            'return-fields' => Target_Cloudsearch::FIELD_PAYLOAD,
        );

        if ($selector instanceof Selector)
        {
            if ($select_query = $selector->build_target_query($entity, $this))
            {
                $query_parameters['bq'] = sprintf("(and %s %s)", $query_parameters['bq'], $select_query);
            }

            if ($rank = $selector->build_target_sort($entity, $this))
            {
                $query_parameters['rank'] = $rank;
            }

            if ($page = $selector->build_target_page($entity, $this))
            {
                $query_parameters['start'] = $page[0];
                $query_parameters['size'] = $page[1];
            }
        }    

        $facet_fields = array();
        foreach ($entity[Target_Cloudsearch::VIEW_INDEXER] as $k => $v)
        {
            if ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Target_Cloudsearch::ATTR_FACET, $k))
            {
                if ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Target_Cloudsearch::ATTR_FACET_MAP, $k)) 
                {
                    $facet_fields[] = sprintf('%s%s%s_%s'
                            , $entity->get_root()->get_name()
                            , Target_Cloudsearch::DELIMITER
                            , $k
                            , Target_Cloudsearch::ATTR_FACET_MAP
                            );
                }
                else if ($ranges = $entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Target_Cloudsearch::ATTR_FACET_RANGE, $k))
                {
                    $facet_fields_range = array();
                    foreach ($ranges as $range)
                    {
                        $facet_fields_range[] = Target_Cloudsearch::parse_attr_facet_range($range);
                    }

                    if (!empty($facet_fields_range))
                    {
                        $query_parameters[sprintf('facet-%s%s%s-constraints'
                                , $entity->get_root()->get_name()
                                , Target_Cloudsearch::DELIMITER
                                , $k
                        )] = implode(',', $facet_fields_range);
                    }

                }
                // fields cannot be both sortable and facetable, so we use the facet field here
                else if ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Selector::ATTR_SORTABLE, $k))
                {
                     $facet_fields[] = sprintf('%s%s%s_%s'
                            , $entity->get_root()->get_name()
                            , Target_Cloudsearch::DELIMITER
                            , $k
                            , Target_Cloudsearch::ATTR_FACET
                            );
                }
                /*
                elseif (array_key_exists(sprintf('%s_%s', $k, Target_Cloudsearch::ATTR_FACET), $entity[Target_Cloudsearch::VIEW_INDEXER])) 
                {
                    $facet_fields[] = sprintf('%s%s%s_%s'
                            , $entity->get_root()->get_name()
                            , Target_Cloudsearch::DELIMITER
                            , $k
                            , Target_Cloudsearch::ATTR_FACET
                            );
                }
                */
                else
                {
                    $facet_fields[] = sprintf('%s%s%s'
                            , $entity->get_root()->get_name()
                            , Target_Cloudsearch::DELIMITER
                            , $k
                            );
                }
            }
        }
        $query_parameters['facet'] = implode(',', $facet_fields);

        $query_string = http_build_query($query_parameters);

        // calls curl to aws
        $url = $this->get_search_endpoint() .  $query_string;
        $url = strtr($url, array(' ' => '%20'));
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
        );
        
        $session = curl_init($url);
        curl_setopt_array($session, $options);
        $raw = curl_exec($session);
        $response = Parse::json_parse($raw, true);
        $response_code = curl_getinfo($session, CURLINFO_HTTP_CODE);
        curl_close($session);
        
        if($response_code == 100)
        {
            throw new HTTP_Exception_100('Throttled.');
        } 
        else if($response_code != 200) 
        {
            throw new Exception('Cloudsearch error : ' . $response['messages'][0]['message']
              . ' ... the URL we hit was: ' . $url);
            throw new Exception('Cloudsearch error : ' . $response['messages'][0]['message']);
        }
       
        Metamodel_Target_Cloudsearch::$elapsed = $response['info']['time-ms'] / 1000.0;

        $results = array();
        foreach($response['hits']['hit'] as $hit) 
        {
            $results[] = $this->columnize($entity, $hit['data']);
        }
        
        $this->select_count = $response['hits']['found'];

        if (array_key_exists('facets', $response)) 
        {
            $this->facets = $response['facets'];
        } 
        else 
        {
            $this->facets = array();
        }
        
        return $results;
    }

    /**
     * select_count
     *
     * @param Entity_Row $entity
     * @param Selector $selector
     * @access public
     * @return void
     */
    public function select_count(Entity_Row $entity, Selector $selector = null)
    {
        if (!isset($this->select_count))
        {
            $this->select($entity, $selector);
        }
        return $this->select_count;
    }
    
    /**
     * implements selectable
     */
    public function create(Entity_Row $entity) 
    { 
        $cloudsearch_endpoint = $this->get_document_endpoint();
        $clob = '[' . $this->create_document($entity) . ']';
        $curl = curl_init($cloudsearch_endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $clob);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
        $response_data = curl_exec($curl);
        $response_info = curl_getinfo($curl);

        $succeeded = ($response_info['http_code'] == 200);
        $throttled = ($response_info['http_code'] == 100);

        // @TODO no return ?  what does throttled do ?
    }
 
    /**
     * this wraps a target in the document stanzas cs expects when we do a post
     *
     */
    public function create_document($entity)
    {
        $unique_key = array($entity->get_root()->get_name());
        foreach($entity[Entity_Root::VIEW_KEY]->to_array() as $alias => $value)
        {
            $unique_key[] = $value;
        }
         
        $document_add = array();
        $document_add['type'] = 'add';
        $document_add['lang'] = 'en';
        $document_add['id'] = $this->clean_field_name(implode('_', $unique_key));
        $document_add['version'] = (int)implode('', $entity[Entity_Root::VIEW_TS]->to_array());
        $document_add['fields'] = $this->targetize($entity);
        
        return json_encode($document_add);
    }

    /**
     * implements selectable
     * 
     * only to satisfy interface. in ter face.
     */
    public function update(Entity_Row $entity, Selector $selector = null)
    { 
        return $this->create($entity);
    }

    /**
     * implements selectable
     */
    public function remove(Entity_Row $entity, Selector $selector)
    {
        $entities_to_remove = $this->select($entity, $selector);
        
        $clob = array();
        foreach($entities_to_remove as $i => $entity_to_remove)
        {
            $clob[] = json_encode(array(
                'type' => 'delete',
                'id' =>  $this->clean_field_name(implode('_', $entity_to_remove[Entity_Root::VIEW_KEY]->to_array())),
                'version' => time(),
            ));
        }

        $concatenated = sprintf('[%s]', implode('.', $clob));
        
        $cloudsearch_endpoint = $this->get_document_endpoint();
        
        $curl = curl_init($cloudsearch_endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $concatenated);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
        $response_data = curl_exec($curl);
        $response_info = curl_getinfo($curl);
        $succeeded = ($response_info['http_code'] == 200);
        $throttled = ($response_info['http_code'] == 100);

        //@TODO returns ? throttled ?
    }


    /**
     * All fields must be flat in cloudsearch, and all values are arrays
     *
     * 1. Type_Typeable are run thru type_transform()
     *      eg: foo = Type_String
     *          becomes
     *          $fields[entity__x__foo] = array(),
     *
     * 2. Entity_Columnset are recursively split into individual fields
     *      eg: foo = array('color' => Type_String, 'count' => Type_Int) 
     *          becomes
     *          $fields[entity_foo__x__color] = array(), $fields[entity_foo__x__count] = array();
     *
     * 3. Entity_Array_Simple is converted to case #1 with it's type = to it's childs type
     *
     * 4. Entity_Array_Pivot is converted to case #1 with it's type = get_child() or Type_String if it's complex
     *
     * 5. Entity_Array_Nested is converted to case #2
     *
     * 
     * @see type_transform()
     */
    // public function targetize_fields(Entity_Structure $parent, array $entity_name, array $fields = array(), $format_function = null)
    public function targetize_fields(Entity_Structure $parent, array $entity_name, array $fields = array(), callable $format_function = null)
    {
        if (is_null($format_function)) $format_function = array($this, 'type_transform_translate');
        $structure = $parent->get_children();
        $value = $parent->to_array();

        foreach ($structure as $alias => $type)
        {

//echo "\n ___ ".implode('@',$entity_name)."  $alias __ ". get_class($type);

            $entity = array_shift($entity_name);
            $entity_name[] = $this->clean_field_name($alias);
            $field_name = sprintf('%s%s%s'
                , $entity
                , Target_Cloudsearch::DELIMITER
                , implode('_', $entity_name)
            );
            array_unshift($entity_name, $entity);
            array_pop($entity_name);

            if ($type instanceof Type_Typeable)
            {
                $new = call_user_func_array($format_function, array(
                    $parent,
                    $type,
                    $alias,
                    $value[$alias],
                    $fields,
                    $field_name,
                ));
                $fields = array_merge($fields,$new);
//                var_export($new);
            }
        
            else if ($type instanceof Entity_Columnset)
            {
                $entity_path = $entity_name;
                $entity_path[] = $alias;
                $fields = $this->targetize_fields($type, $entity_path, $fields, $format_function);
            
            }

            else if ($type instanceof Entity_Array_Pivot || $type instanceof Entity_Array_Simple)
            {
                $fields = array_merge($fields, call_user_func_array($format_function, array(
                    $type,
                    $type->get_child(),
                    $alias,
                    $value[$alias],
                    $fields,
                    $field_name,
                )));
            }
            else if ($type instanceof Entity_Array_Nested)
            {
                $entity_path = $entity_name;
                $entity_path[] = $alias;
                foreach ($type as $k => $v)
                {
                    // $fields = $this->targetize_fields($type->get_child(), $entity_path, $fields, $format_function);
                    $fields = $this->targetize_fields($v, $entity_path, $fields, $format_function);
                }
            }
        }   

        return $fields;
    }

    protected function type_transform_translate($parent_obj, $child_obj, $child_alias, $child_value, $fields, $field_name)
    {
        if (!array_key_exists($field_name, $fields)) $fields[$field_name] = array();
        
        $transform = $this->type_transform($child_obj, $child_value);
        if (is_array($transform))
        {
            $fields[$field_name] = array_merge($fields[$field_name], $transform);
        } else {
            $fields[$field_name][] = $transform;
        }
        return array($field_name => $fields[$field_name]);
    }
    
    /**
     * dchan function meant to be a drop in replacement for create_helper()
     *
     * @author dchan@mshanken.com
     */
    public function targetize(Entity_Row $entity) 
    { 
        $entity_name = $entity->get_root()->get_name();
        $fields = array();
        $fields = $this->targetize_fields($entity[Entity_Root::VIEW_KEY], array($entity_name), $fields);
        $fields = $this->targetize_fields($entity[Target_Cloudsearch::VIEW_INDEXER], array($entity_name), $fields);
        $fields[Target_Cloudsearch::FIELD_ENTITY] = $entity_name;
        $fields[Target_Cloudsearch::FIELD_PAYLOAD] = json_encode(array(
            Entity_Root::VIEW_KEY => $entity[Entity_Root::VIEW_KEY]->to_array(),
            Entity_Root::VIEW_TS => $entity[Entity_Root::VIEW_TS]->to_array(),
            Target_Cloudsearch::VIEW_PAYLOAD => $entity[Target_Cloudsearch::VIEW_PAYLOAD]->to_array(),
        ));
        
        return $fields;
    }
       

    public function columnize(Entity_Row $entity, array $document) 
    {
        if (!($payload = array_shift($document[Target_Cloudsearch::FIELD_PAYLOAD])))
        {
            throw('invalid cloudsearch response ... '. var_export($document, true));
        }

        $result = clone $entity;
        $result[Target_Cloudsearch::VIEW_PAYLOAD] = Parse::json_parse($payload, true);

        return $result;
    }
    

    /**
     * satisfy selector visitor interface
     *
     * @param column_name is true name
     */
    public function visit_exact($entity, $column_name, $param) 
    {
        $children = $entity[Target_Cloudsearch::VIEW_INDEXER]->get_children();
        $alias = $this->visit_column_name($entity, $column_name);

        if (array_key_exists($alias, $children))
        {
            $param = $this->type_transform($children[$alias], $param);
        }
        //var_dump(array($alias,$column_name));

        $info = $entity->get_root()->get_target_info($this);
        $column_name_renamed = sprintf('%s%s%s'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($alias)
        );
        return sprintf(" %s:'%s'"
                , $column_name_renamed
                , strtr($param, array("'" => "\\'",'\\' => '\\\\'))
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_search($entity, $column_name, $param) 
    {
        $children = $entity[Target_Cloudsearch::VIEW_INDEXER]->get_children();
        $alias = $this->visit_column_name($entity, $column_name);
        if (array_key_exists($alias, $children))
        {
            $param = $this->type_transform($children[$alias], $param);
        }
        else if ($column_name == Target_Cloudsearch::UNIVERSAL_SEARCH_FIELD)
        {
            $alias = $column_name;
        }

        $search_string = strtr($param, array("'" => "\\'",'\\' => '\\\\'));
        $search_terms = explode(' ', $search_string);
        $field_name = sprintf("%s%s%s"
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($alias)
        );
        
        // Build search query with wildcard and exact for each word in string
        for($i=0;$i<count($search_terms);$i++)
        {
            $search_term = $search_terms[$i];
            $temporary = "";
            for($j = 0; $j < strlen($search_term); $j++)
            {
                if(preg_match('/^[a-zA-Z0-9]$/', $search_term[$j]))
                {
                    $temporary .= $search_term[$j];
                }
                else if($search_term[$j] == '\'')
                {
                    $temporary .= '\\\'';
                }
            }
            $search_term = $temporary;
            
            $search_terms[$i] = sprintf("(or (and (field %s '%s')) (and (field %s '%s*')))",
                $field_name,
                $search_term,
                $field_name,
                $search_term
            );
        }
        
        $cloudsearch_string = implode(' ', $search_terms);
        
        return $cloudsearch_string;

    }
    
    /**
     * special handling for types by this target.
     *
     * eg: dates are transformed to unix timestamps
     */
//    public function type_transform($entity, $column_name, $param) 
    public function type_transform($type, $value)
    {   
        if ($type instanceof Type_Date)
        {
           if (!is_numeric($value))
            {
                if ($date = DateTime::createFromFormat('Y-m-d G:i:s.u', $value)) {}
                else if ($date = DateTime::createFromFormat('Y-m-d G:i:s', $value)) {}
                else if ($date = DateTime::createFromFormat('Y-m-d', $value)) {}
                else
                {
                    debug_print_backtrace();
                    throw new HTTP_Exception_400(sprintf('Invalid Date Format %s', $value));
                }
                return $date->format('U');
            }
        }

        return $value;
    }
    
    /**
     * Return an array of valid methods which can be performed on the given type,
     * as defined by constants in the Selector class.
     */
    public function visit_selector_security(Type_Typeable $type, $sortable) {
        if ($type instanceof Type_Number)
        {
            $result = array(
                Selector::EXACT,
                Selector::RANGE_MAX,
                Selector::RANGE_MIN,
                Selector::RANGE,
                Selector::ISNULL,
            );
        } 
        else if ($type instanceof Type_Date)
        {
            $result = array(
                Selector::EXACT,
                Selector::RANGE_MAX,
                Selector::RANGE_MIN,
                Selector::RANGE,
            );
        }
        else if ($type instanceof Type_Typeable)
        {
            $result = array(
                Selector::SEARCH,
                // @TODO if FREETEXT, dont allow  Exact
                Selector::EXACT,
                Selector::ISNULL,
            );
        }
        
        if($sortable) $result[] = Selector::SORT;
        return $result;
    }

    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_max($entity, $column_name, $param) 
    { 
        $children = $entity[Target_Cloudsearch::VIEW_INDEXER]->get_children();
        $alias = $this->visit_column_name($entity, $column_name);
        if (array_key_exists($alias, $children))
        {
            $param = $this->type_transform($children[$alias], $param);
        }

        return sprintf('(filter %s%s%s ..%s)'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($alias)
            , $param
        );

    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_min($entity, $column_name, $param) 
    {
        $children = $entity[Target_Cloudsearch::VIEW_INDEXER]->get_children();
        $alias = $this->visit_column_name($entity, $column_name);
        if (array_key_exists($alias, $children))
        {
            $param = $this->type_transform($children[$alias], $param);
        }

        return sprintf('(filter %s%s%s %s..)'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($alias)
            , $param
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_range($entity, $column_name, $min, $max) 
    {
        $children = $entity[Target_Cloudsearch::VIEW_INDEXER]->get_children();
        $alias = $this->visit_column_name($entity, $column_name);
        if (array_key_exists($alias, $children))
        {
            $min = $this->type_transform($children[$alias], $min);
            $max = $this->type_transform($children[$alias], $max);
        }

        return sprintf('(filter %s%s%s %s..%s)'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($alias)
            , $min
            , $max
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_isnull($entity, $column_name) 
    {
        return sprintf("(not (field %s%s%s '*'))"
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($column_name)
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_operator_and($entity, array $parts) 
    {
        if(count($parts) > 0)
//            return sprintf('(and %s)', implode(' ', $parts));
            return implode(' ', $parts);
        else
            return NULL;
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_operator_or($entity, array $parts) 
    {
        if(count($parts) > 0)
            return sprintf('(or %s)', implode(' ', $parts));
        else
            return NULL;
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_operator_not($entity, $part) 
    {
        return sprintf('(not %s)', $part);
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_page($entity, $limit, $offset = 0) 
    {
        return array($offset, $limit);
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_sort($entity, array $items) 
    {
        $results = array();
        $i = 0;
        foreach($items as list($column_name, $direction))
        {
            $alias = $entity[Target_Cloudsearch::VIEW_INDEXER]->lookup_entanglement_name($column_name);

            $results[] = sprintf('%s%s%s%s'
                , ($direction == 'desc') ? '-' : ''
                , $this->clean_field_name($entity->get_root()->get_name())
                , Target_Cloudsearch::DELIMITER
                , $this->clean_field_name($alias)
            );
        }
        
        return implode(',', $results);
    }
    
    public function get_facets() 
    {
        return $this->facets;
    }

    public function clean_field_name($name) 
    {
        $name = strtolower($name);
        $name = preg_replace('/-/', '_', $name);
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        return $name;
    }

    /**
     * helper of select helper
     *
     * this helper caches the result of querying cloudsearch (which is expensive)
     */ 
    public function get_search_endpoint() 
    {
        if (is_null($this->domain_description))
        {
            $this->get_domain_description();
        }

        //@TODO magic
        return sprintf(
            'http://%s/2011-02-01/search?'            
            ,$this->domain_description['SearchService']['Endpoint']
        );
    }

    /**
     * helper of create, update, and delete
     *
     * caches expensive cloudsearch document url lookup
     */
    public function get_document_endpoint() 
    {
        if (is_null($this->domain_description))
        {
            $this->get_domain_description();
        }
        
        //@TODO magic
        return sprintf(
            'http://%s/2011-02-01/documents/batch'
            , $this->domain_description['DocService']['Endpoint']
        );


    }

    /**
     * caches expensive cloudsearch domain info
     *
     * @see get_document_endpoint, 
     * @see get_search_endpoint
     */
    public function get_domain_description() 
    {
        $memcache = new Memcache;
        $memcache->connect(Kohana::$config->load('cloudsearch.cache_host'), Kohana::$config->load('cloudsearch.cache_port'));
        $csdomain = Kohana::$config->load('cloudsearch.domain_name');
        $memcache_key = sprintf('cloudsearch_domain_desc_%s', $csdomain);
        
        if (!$this->domain_description)
        {
            if (!($this->domain_description = $memcache->get($memcache_key)))
            {
                $config = array(
                    'key' => Kohana::$config->load('cloudsearch.key'),
                    'secret' => Kohana::$config->load('cloudsearch.secret'),
                    'region' => Kohana::$config->load('cloudsearch.region'),
                );
    
                $aws = Aws::factory($config);
                $cloudsearch = $aws->get('CloudSearch');
                $response = $cloudsearch->describeDomains(array('DomainNames' => array($csdomain)));
                if (!count($response))
                {
                    echo json_encode($response->body) . "\n";
                    throw new Exception("No domain named " . $csdomain);
                }
    
                foreach ($cloudsearch->getDescribeDomainsIterator() as $d)
                {
                    $this->domain_description = array();
                    if (array_key_exists('SearchService',$d)) $this->domain_description['SearchService'] = $d['SearchService'];
                    if (array_key_exists('DocService',$d)) $this->domain_description['DocService'] = $d['DocService'];

                    break;
                }

                // only cache valid
                // sometimes when the domain is not ready, we get no endpoint
                if (array_key_exists('SearchService',$this->domain_description) 
                    && array_key_exists('Arn',$this->domain_description['SearchService'])
                    && array_key_exists('Endpoint',$this->domain_description['SearchService'])
                    && array_key_exists('DocService',$this->domain_description)
                    && array_key_exists('Arn',$this->domain_description['DocService'])
                    && array_key_exists('Endpoint',$this->domain_description['DocService'])
                )
                {
                    $cache_ttl = Kohana::$config->load('cloudsearch.cache_ttl');
                    $memcache->set($memcache_key, $this->domain_description, false, $cache_ttl);
                }
            }
        }

        return $this->domain_description;
    }
    

    public function validate_entity(Entity_Row $row)
    {
        return $row->get_root() instanceof Target_Cloudsearchable
            && $row[Target_Cloudsearch::VIEW_PAYLOAD] instanceof Entity_Columnset
            && $row[Target_Cloudsearch::VIEW_INDEXER] instanceof Entity_Columnset
            && $row[Entity_Root::VIEW_KEY] instanceof Entity_Columnset
            && $row[Entity_Root::VIEW_TS] instanceof Entity_Columnset
            && $row[Target_Cloudsearch::VIEW_PAYLOAD]->validate()
            && $row[Target_Cloudsearch::VIEW_INDEXER]->validate()
            && $row[Entity_Root::VIEW_KEY]->validate()
            && $row[Entity_Root::VIEW_TS]->validate();
    }
    
    public function debug_info()
    {
        return array('elapsed' => Metamodel_Target_Cloudsearch::$elapsed);
    }

    public function selectable_helper($entity, $entanglement_name) 
    {
        foreach ($entity->get_children() as $k => $v)
        {
            if ($entanglement_name == $entity->get_entanglement_name($k)) 
            {
                // echo "\n<li> FOUND " . $entity->get_entanglement_name($k) . ' == ' . $entanglement_name . "</li>\n";;
                return true;
            }

            if ($v instanceof Entity_Array_Nested) 
            {
                if ($this->selectable_helper($v->get_child(), $entanglement_name)) return true;
            } 
            else if ($v instanceof Entity_Columnset)
            {
                if ($this->selectable_helper($v, $entanglement_name)) return true;
            }
        }
        return false;
    }

    public function is_selectable(Entity_Row $row, $entanglement_name, array $allowed)
    {
        foreach (array(Entity_Root::VIEW_KEY
                    , Entity_Root::VIEW_TS
                    , Target_Cloudsearch::VIEW_INDEXER) as $view)
        {
            if ($row[$view]->lookup_entanglement_name($entanglement_name) !== false)
            {
                return true;
            }
        }
        return false;
    }


    public function add_selectable(Entity_Store $entity, Selector $selector)
    {
        foreach ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_children() as $k => $v)
        {
            if ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Target_Cloudsearch::ATTR_FACET_MAP, $k))
            {
                $selector->security->allow(
                    sprintf('%s_%s', $k, Target_Cloudsearch::ATTR_FACET_MAP)
                    , array(
                        Selector::EXACT
                    )
                );
            }
            else if ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Target_Cloudsearch::ATTR_FACET, $k))
            {
                $selector->security->allow(
                    sprintf('%s', $k)
                    , array(
                        Selector::EXACT
                    )
                );

                $selector->security->allow(
                    sprintf('%s_%s', $k, Target_Cloudsearch::ATTR_FACET)
                    , array(
                        Selector::EXACT
                    )
                );
            }
        }
    }

    /**
     * Helper for the visit_*() interface that builds WHERE clauses out of selectors.
     * Responsible for looking up an actual column name as it is seen by Postgres.
     */
    private function visit_column_name($entity, $column_name)
    {
        // look in standard index search fields
        if ($alias = $entity[Target_Cloudsearch::VIEW_INDEXER]->lookup_entanglement_name($column_name))
        {
            return $alias;
        }

        // look in facet mappings (generated at index time)
        if ($alias = strstr($column_name, '_'.Target_Cloudsearch::ATTR_FACET_MAP, true)) 
        {
            if ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Target_Cloudsearch::ATTR_FACET_MAP, $alias))
            {
                return $column_name;
            }   
        }

        // look for other facet fields 
        if ($alias = strstr($column_name, '_'.Target_Cloudsearch::ATTR_FACET, true)) 
        {
        
            if ($entity[Target_Cloudsearch::VIEW_INDEXER]->get_attribute(Target_Cloudsearch::ATTR_FACET, $alias))
            {
                return $column_name;
                return $alias;  // strip _do_facet part
            }   
        }

        // UNIVERSAL_SEARCH_FIELD allowed by default
        if ($column_name == Target_Cloudsearch::UNIVERSAL_SEARCH_FIELD)
        {
            return Target_Cloudsearch::UNIVERSAL_SEARCH_FIELD;
        }

        throw new HTTP_Exception_400(sprintf('Unknown selector field %s', $column_name));
    }

    /**
     * parse_attr_facet_range
     *
     * return a string representing the range in cloudsearch query format
     *
     * @param mixed $range
     * @static
     * @access public
     * @return void
     */
    static public function parse_attr_facet_range($range)
    {
        if (array_key_exists('max', $range))
        {
            if (array_key_exists('min', $range))
            {
                return sprintf('%d..%d', $range['min'], $range['max']);
            }
            else
            {
                return sprintf('..%d', $range['max']);
            }
        }
        else if (array_key_exists('min', $range))
        {
            return sprintf('%d..', $range['min']);
        }
    }
}
