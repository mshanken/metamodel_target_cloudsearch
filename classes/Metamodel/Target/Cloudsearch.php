<?php //defined('SYSPATH') or die('No direct access allowed.');
/**
 * target/cloudsearch.php
 * 
 * @package Metamodel
 * @subpackage Target
 * @author dknapp@mshanken.com
 *
 **/

Class Metamodel_Target_Cloudsearch
implements Target_Selectable
{
    const DELIMITER = '__x__';
    protected static $search_endpoint = null;
    protected static $document_endpoint = null;
    protected $facets = null;
    protected $select_count = null;
    
    // singleton save of expensive cloudsearch search url lookup
    // helper of select helper
    private static function get_search_endpoint(Target_Info $info) {
        // @TODO dont use self:: use Target_Cloudsearch::
        if(self::$search_endpoint) return self::$search_endpoint;
        
        $config = Kohana::$config->load('cloudsearch')->as_array();
        $config['domain'] = $info->get_domain_name();
        $cloudsearch = new AmazonCloudsearch($config);
        $response = $cloudsearch->describe_domains(array('DomainNames' => $config['domain']));
        if(!isset($response->body->DescribeDomainsResult->DomainStatusList->member))
        {
            echo json_encode($response->body) . "\n";
            throw new Exception("No domain named " . $config['domain']);
        }
        $domain = $response->body->DescribeDomainsResult->DomainStatusList->member;
        $endpoint = $domain->SearchService->Endpoint->to_string();
        
        self::$search_endpoint = $endpoint;
        
        return $endpoint;
    }

    // singleton save of expensive cloudsearch document url lookup
    // helper of create, update, and delete
    private static function get_document_endpoint(Target_Info $info) {
        // @TODO dont use self:: use Target_Cloudsearch::
        if(self::$document_endpoint) return self::$document_endpoint;
        
        $config = Kohana::$config->load('cloudsearch')->as_array();
        $config['domain'] = $info->get_domain_name();
        $cloudsearch = new AmazonCloudsearch($config);
        $response = $cloudsearch->describe_domains(array('DomainNames' => $config['domain']));
        if(!isset($response->body->DescribeDomainsResult->DomainStatusList->member))
        {
            echo json_encode($response->body) . "\n";
            throw new Exception("No domain named " . $config['domain']);
        }
        $domain = $response->body->DescribeDomainsResult->DomainStatusList->member;
        $endpoint = $domain->DocService->Endpoint->to_string();
        
        self::$document_endpoint = $endpoint;
        
        return $endpoint;
    }
    
    // helper for select
    private function select_helper($query_string, Target_Info $info)
    {
        //@TODO no using self:: breaks inheritance.
        $endpoint = self::get_search_endpoint($info);

        //@TODO put hardcoded stuff into config files
        $url = 'http://' . $endpoint . '/2011-02-01/search?' . $query_string;
        $url = strtr($url, array(' ' => '%20'));
        $options = array();
        
        $options[CURLOPT_RETURNTRANSFER] = TRUE;
        
        $session = curl_init($url);
        curl_setopt_array($session, $options);
        $response_body = curl_exec($session);
        $response = Parse::json_parse($response_body, true);
        $response_code = curl_getinfo($session, CURLINFO_HTTP_CODE);
        if($response_code != 200) 
        {
            echo $url;
            echo $response_body;
            throw new Exception('Cloudsearch error : ' . $response['messages'][0]['message']);
        }
        curl_close($session);
        return $response;
    }

    /**
     * implements selectable
     */
    public function select(Entity $e, Selector $selector = null)
    {
        if (!($e instanceof Target_Cloudsearchable)) throw new Exception('Entity must implement Cloudsearchable');
        $info = $e->target_cloudsearch_info();

        $query_parameters = array();
        $query_parameters['return-fields'] = 'payload';
        $query_parameters['bq'] = $this->build_query($e, $selector);
        $rank = $selector->build_target_sort($e, $this);
        if(!is_null($rank)) $query_parameters['rank'] = $rank;

        $sort_page = $selector->getPageInfo();
        if(!empty($sort_page[Selector::PAGE])) 
        {
            $params = $sort_page[Selector::PAGE];
            $query_parameters['start'] = $params[0];
            $query_parameters['size'] = $params[1];
        }

        $facet_constraints = $info->get_facet_constraints();
        $facet_parameters = array();
        $tmp = array();
        if (count($e['cloudsearch_facets']))
        {
            foreach ($e['cloudsearch_facets'] as $k => $v)
            {
                if (!array_key_exists($k, $facet_constraints))
                {
                    $tmp[] = sprintf('%s%s%s', $e->getName(), self::DELIMITER, $k);
                }
            }
            $facet_parameters['facet'] = implode(',', $tmp);
        }

        foreach ($facet_constraints as $k => $v)
        {
            $key = sprintf('facet-%s%s%s-constraints', $e->getName(), self::DELIMITER, $k);
            $facet_parameters[$key] = implode(',', $facet_constraints[$k]);
        }
        $query_parameters = array_merge($query_parameters, $facet_parameters);

        $query_string = http_build_query($query_parameters);

        // calls curl to aws
        $response = $this->select_helper($query_string, $info);
        
        $results = array();
        foreach($response['hits']['hit'] as $hit) 
        {
            $results[] = $this->columnize($e, $hit['data']);
        }
        
        $this->select_count = $response['hits']['found'];

        if (array_key_exists('facets', $response)) 
        {
            $this->facets = $response['facets'];
        } else {
            $this->facets = array();
        }
        
        return $results;
    }

    /**
     * implements selectable
     */
    public function select_count(Entity $e, Selector $selector = null)
    {
        return $this->select_count;
    }
    
    /**
     * implements selectable
     */
    public function create(Entity $entity) 
    { 
        if (!($entity instanceof Target_Cloudsearchable))
            throw new Exception('Entity must implement Cloudsearchable');
        
        $cloudsearch_endpoint = self::get_document_endpoint($entity->target_cloudsearch_info());
        
        $clob = "[" . $this->targetize($entity) . "]";
        $curl = 
        curl_init($cloudsearch_endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $clob);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
        $response_data = curl_exec($curl);
        $response_info = curl_getinfo($curl);
        $succeeded = ($response_info['http_code'] == 200);
        $throttled = ($response_info['http_code'] == 100);
    }
       
    /**
     * implements selectable
     * 
     * only to satisfy interface. in ter face.
     */
    public function update(Entity $entity, Selector $selector = null)
    { 
        $this->create($entity);
    }

    /**
     * implements selectable
     */
    public function remove(Entity $entity, Selector $selector)
    {
        if (!($entity instanceof Target_Cloudsearchable))
            throw new Exception('Entity must implement Cloudsearchable');
        
        $entities_to_remove = $this->select($entity, $selector);
        
        $concatenated = "[";
        foreach($entities_to_remove as $i => $entity_to_remove)
        {
            $id = $this->clean_field_name(implode('_', $entity_to_remove['key']->getData()));
            
            $clob = json_encode(array(
                'type' => 'delete',
                'id' =>  $id,
                'version' => time(),
            ));
            
            if($i > 0) $concatenated .= ".";
            $concatenated .= $clob;
        }
        $concatenated .= "]";
        
        $cloudsearch_endpoint = self::get_document_endpoint($entity->target_cloudsearch_info());
        
        $curl = 
        curl_init($cloudsearch_endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $concatenated);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
        $response_data = curl_exec($curl);
        $response_info = curl_getinfo($curl);
        $succeeded = ($response_info['http_code'] == 200);
        $throttled = ($response_info['http_code'] == 100);
    }

    public function validate_entity(Entity $entity)
    {
        return $entity instanceof Target_Cloudsearchable;    
    }

    /**
     * dchan function meant to be a drop in replacement for create_helper()
     *
     * @author dchan@mshanken.com
     */
    public function targetize(Entity $entity) 
    { 
        if (!($entity instanceof Target_Cloudsearchable)) throw new Exception('Entity must implement Cloudsearchable');

        $fields_renamed = array(
            'payload' => json_encode(array(
                'key' => $entity['key']->getData(),
                'timestamp' => $entity['timestamp']->getData(),
                'cloudsearch_payload' =>  $entity['cloudsearch_payload']->getData(),
            )),
            'entity' => $entity->getName(),
        );

        if(!$entity['cloudsearch_facets']->validate())
        {
            throw new Exception('Invalid column values.' . var_export($entity[$view_name]->problems(), true));
        }
        
        foreach($entity['cloudsearch_facets'] as $column_name => $column) 
        {            
            $new_name = $this->clean_field_name(sprintf('%s%s%s', 
                $entity->getName(), Target_Cloudsearch::DELIMITER, $column_name
            ));
            
            $type = $entity['cloudsearch_facets'][$column_name];
            $is_array = $type instanceof Entity_Array;
            while($type instanceof Entity_Array || $type instanceof Entity_Pivot)
            {
                $type = $type->getType();
            }
            
            if($is_array) {
                $fields_renamed[$new_name] = array();
                $values = $column->getData();
                if(is_null($values)) {
                    $fields_renamed[$new_name][] = '';
                } else {
                    foreach($values as $value) {
                        if($type instanceof Entity_Columnset)
                        {
                            $fields_renamed[$new_name][] = json_encode($value);
                        } 
                        else
                        {
                            $fields_renamed[$new_name][] = $value;
                        }
                    }
                }
            } else {
                $value = $column->getData();
                if(is_null($value)) {
                    $fields_renamed[$new_name] = '';
                }
                else if($type instanceof Entity_Columnset)
                {
                    $fields_renamed[$new_name] = json_encode($value);
                } 
                else
                {
                    $fields_renamed[$new_name] = $value;
                }
            }
        }

        foreach(array('key', 'cloudsearch_indexer') as $view_name)
        {
            if(!$entity[$view_name]->validate())
            {
                throw new Exception('Invalid column values.' . var_export($entity[$view_name]->problems(), true));
            }
            
            foreach($entity[$view_name] as $column_name => $column) 
            {
                $new_name = $this->clean_field_name(sprintf('%s%s%s', 
                    $entity->getName(), Target_Cloudsearch::DELIMITER, $column_name
                ));
                
                $type = $column->getType();
                $is_array = $type instanceof Entity_Array;
                while ($type instanceof Entity_Array || $type instanceof Entity_Pivot)
                {
                    $type = $type->getType();
                }
                
                if($is_array) {
                    $fields_renamed[$new_name] = array();
                    $values = $column->getData();
                    if(is_null($values)) {
                        $fields_renamed[$new_name][] = '';
                    } else {
                        foreach($values as $value) {
                            $fields_renamed[$new_name][] = $value;
                        }
                    }
                } else {
                    $value = $column->getData();
                    if(is_null($value)) {
                        $fields_renamed[$new_name] = '';
                    } else {
                        $fields_renamed[$new_name] = $value;
                    }
                }
            }
        }
        return array(
            'type' => 'add',
            'lang' => 'en',
            'id' => $this->clean_field_name(implode('_', $entity['key']->getData())),
            'version' => time(),
            'fields' => $fields_renamed
        );
    }
    

    public function columnize(Entity $entity, array $document) 
    {
        if (!($payload = array_shift($document['payload'])))
        {
            throw('invalid cloudsearch response ... '. var_export($document, true));
        }

        $result_data = Parse::json_parse($payload, true);
        $result = clone $entity;
        foreach ($result_data as $k => $v) 
        {
            $result[$k]->setData($v);
        }
            
        if (count($document['payload']))
        {
            // @TODO do something if extra payloads exist...
        }

        return $result;
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_exact($entity, $column_storage_name, $param) {
        $column_name = $entity['cloudsearch_indexer']->lookup_storage_name($column_storage_name);
        $info = $entity->target_cloudsearch_info();
        $column_name_renamed = sprintf('%s%s%s'
            , $this->clean_field_name($entity->getName())
            , self::DELIMITER
            , $this->clean_field_name($column_name)
        );
        if($info->is_numeric($column_name)) {
            return sprintf("(field %s %s)", $column_name_renamed, $param);
        } else {
            return sprintf("(field %s '%s')", $column_name_renamed,
                strtr($param, array("'" => "\\\'","\\" => "\\\\"))
            );
        }
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_search($entity, $column_storage_name, $param) {
        if($column_storage_name == "text")
        {
            $column_name = "text";
            $use_namespace = FALSE;
        }
        else
        {
            $column_name = $entity['cloudsearch_indexer']->lookup_storage_name($column_storage_name);
            $use_namespace = TRUE;
        }
        $info = $entity->target_cloudsearch_info();
        $search_string = strtr($param, array("'" => "\\\'",'\\' => '\\\\'));
        $search_terms = implode("* ", explode(' ', $search_string));
        return sprintf("(field %s%s%s '%s*')"
            , $use_namespace ? $this->clean_field_name($entity->getName()) : ""
            , $use_namespace ? self::DELIMITER : ""
            , $this->clean_field_name($column_name)
            , $search_terms
        );

    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_max($entity, $column_storage_name, $param) {
        $column_name = $entity['cloudsearch_indexer']->lookup_storage_name($column_storage_name);
        $info = $entity->target_cloudsearch_info();
        return sprintf('(filter %s%s%s %s..)'
            , $this->clean_field_name($entity->getName())
            , self::DELIMITER
            , $this->clean_field_name($column_name)
            , $param
        );

    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_min($entity, $column_storage_name, $param) {
        $column_name = $entity['cloudsearch_indexer']->lookup_storage_name($column_storage_name);
        $info = $entity->target_cloudsearch_info();
        return sprintf('(filter %s%s%s %s..)'
            , $this->clean_field_name($entity->getName())
            , self::DELIMITER
            , $this->clean_field_name($column_name)
            , $param
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_range($entity, $column_storage_name, $min, $max) {
        $column_name = $entity['cloudsearch_indexer']->lookup_storage_name($column_storage_name);
        $info = $entity->target_cloudsearch_info();
        return sprintf('(filter %s%s%s %s..%s)'
            , $this->clean_field_name($entity->getName())
            , self::DELIMITER
            , $this->clean_field_name($column_name)
            , $min
            , $max
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_operator_and($entity, array $parts) {
        if(count($parts) > 0)
            return sprintf('(and %s)', implode(' ', $parts));
        else
            return NULL;
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_operator_or($entity, array $parts) {
        if(count($parts) > 0)
            return sprintf('(or %s)', implode(' ', $parts));
        else
            return NULL;
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_operator_not($entity, $part) {
        return sprintf('(not %s)', $part);
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_page($entity, $limit, $offset = 0) {
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_sort($entity, array $items) {
        $result = "";
        foreach($items as $i => $item)
        {
            if($i > 0) $result .= ",";
            
            if($item[1] == "desc") $result .= '-';
            
            $entity_name = $entity->getName();
            $entity_name = strtolower($entity_name);
            $entity_name = preg_replace('/-/', '_', $entity_name);
            $entity_name = preg_replace('/[^a-z0-9_]/', '', $entity_name);
            $result .= $entity_name;
            
            $result .= "__x__";
            
            $column_name = $item[0];
            $column_name = strtolower($column_name);
            $column_name = preg_replace('/-/', '_', $column_name);
            $column_name = preg_replace('/[^a-z0-9_]/', '', $column_name);
            $result .= $column_name;
        }
        
        return $result;
    }
    
    /**
     * Wrapper around selector's build_target_query() method to also add the
     * entity-name as a criterion.
     *
     * @TODO why is this a function? it's used exactly once. --dchan
     */
    private function build_query(Entity $entity, Selector $selector) 
    {
        $info = $entity->target_cloudsearch_info();
        $entity_name_cleaned = $this->clean_field_name($entity->getName());
        
        $base_query = $selector->build_target_query($entity, $this);
        if(is_null($base_query)) {
            $id_field = $info->get_id_field();
            $id_field_cleaned = $this->clean_field_name($id_field);
            $id_field_renamed = $entity_name_cleaned . '__x__' . $id_field_cleaned;
            $base_query = "(not (field " . $id_field_renamed . " 'A'))";
        }
        
        return sprintf("(and (field entity '%s') %s)"
            , strtr($entity->getName()
            , array("'" => "\\\'","\\" => "\\\\"))
            , $base_query
        );
    }

    public function get_facets() {
        return $this->facets;
    }

    public function clean_field_name($name) {
        $name = strtolower($name);
        $name = preg_replace('/-/', '_', $name);
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        return $name;
    }

    public function selector_security(Entity $entity, Selector $selector)
    {
        $security = $selector->security;
        // @TODO move this to child class, it is project/schema specific
        $security->map('any', 'text')->allow('any');
        
        $allowed = array();
        
        foreach($entity['selector'] as $column_name => $column) 
        {
            if(!array_key_exists($column_name, $allowed)) 
            {
                $allowed[$column_name] = array();
            }
            
            $type = $column->getType();
            while ($type instanceof Entity_Array || $type instanceof Entity_Pivot)
            {
                $type = $type->getType();
            }

            if($type instanceof Type_Freetext)
            {
                $allowed[$column_name][] = Selector::SEARCH;
                $allowed[$column_name][] = Selector::SORT;
            } 
            else if ($type instanceof Type_Number)
            {
                $allowed[$column_name][] = Selector::SEARCH;
                $allowed[$column_name][] = Selector::EXACT;
                $allowed[$column_name][] = Selector::SORT;
            } 
            else 
            {
                $allowed[$column_name][] = Selector::SEARCH;
                $allowed[$column_name][] = Selector::EXACT;
                $allowed[$column_name][] = Selector::SORT;
            }
        }
        
        foreach($allowed as $column_name => $permissions) 
        {
            $security->allow($column_name, $permissions);
        }
        $security->allow('text_relevance', array(Selector::SORT));
    }
    
}
