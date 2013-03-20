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
    const VIEW_PAYLOAD = 'Cloudsearch_PAYLOAD';
    const VIEW_FACETS = 'Cloudsearch_FACETS';
    const VIEW_INDEXER = 'Cloudsearch_INDEX';

    const DELIMITER = '__x__';

    protected $cloudsearch_domain = null;

    /**
     * URL of AWS cloudsearch API
     *
     * eg: 
     * $url = 'http://' . $this->search_endpoint . '/2011-02-01/search?' . $query_string;
     */
    protected $search_endpoint = null;

    /**
     *
     */
    protected $document_endpoint = null;

    protected $facets = null;
    protected $select_count = null;
    
    /**
     * implements selectable
     */
    public function select(Entity_Row $entity, Selector $selector = null)
    {
        $info = $entity->get_root()->get_target_info($this);

        $query_parameters = array();

        $base_query = $selector->build_target_query($entity, $this);
        if(is_null($base_query)) {
            $base_query = sprintf("(not (field %s%s%s 'A'))"
                , $this->clean_field_name($entity->get_root()->get_name())
                , Target_Cloudsearch::DELIMITER
                , $this->clean_field_name($info->get_id_field())
            );
        }       
        $query_parameters['bq'] = sprintf("(and (field entity '%s') %s)"
            , strtr($entity->get_root()->get_name(), array("'" => "\\\'","\\" => "\\\\"))
            , $base_query
        );
        $query_parameters['return-fields'] = 'payload';

        if ($rank = $selector->build_target_sort($entity, $this)) $query_parameters['rank'] = $rank;

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
        if (count($entity[Target_Cloudsearch::VIEW_FACETS]))
        {
            foreach ($entity[Target_Cloudsearch::VIEW_FACETS] as $k => $v)
            {
                if (!array_key_exists($k, $facet_constraints))
                {
                    $tmp[] = sprintf('%s%s%s', $entity->get_root()->get_name(), Target_Cloudsearch::DELIMITER, $k);
                }
            }
            $facet_parameters['facet'] = implode(',', $tmp);
        }

        foreach ($facet_constraints as $k => $v)
        {
            $key = sprintf('facet-%s%s%s-constraints', $entity->get_root()->get_name(), Target_Cloudsearch::DELIMITER, $k);
            $facet_parameters[$key] = implode(',', $facet_constraints[$k]);
        }
        $query_parameters = array_merge($query_parameters, $facet_parameters);
        $query_string = http_build_query($query_parameters);

        // calls curl to aws
        $url = $this->get_search_endpoint($info);

        $url .= $query_string;
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
       
        $results = array();
        foreach($response['hits']['hit'] as $hit) 
        {
            $results[] = $this->columnize($entity, $hit['data']);
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
     *
     * @returns number of rows returned
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
        $info = $entity->get_root()->get_target_info($this);
        $cloudsearch_endpoint = $this->get_document_endpoint($info);
        
        $clob = '[' . $this->targetize($entity) . ']';
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
        $info = $entity->get_root()->get_target_info($this);

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
        
        $cloudsearch_endpoint = $this->get_document_endpoint($info);
        
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
     * dchan function meant to be a drop in replacement for create_helper()
     *
     * @author dchan@mshanken.com
     */
    public function targetize(Entity_Row $entity) 
    { 
        $info = $entity->get_root()->get_target_info($this);

        $fields_renamed = array(
            'payload' => json_encode(array(
                Entity_Root::VIEW_KEY => $entity[Entity_Root::VIEW_KEY]->to_array(),
                Entity_Root::VIEW_TS => $entity[Entity_Root::VIEW_TS]->to_array(),
                Target_Cloudsearch::VIEW_PAYLOAD =>  $entity[Target_Cloudsearch::VIEW_PAYLOAD]->to_array(),
            )),
            'entity' => $entity->get_root()->get_name(),
        );

        if(!$entity[Target_Cloudsearch::VIEW_FACETS]->validate())
        {
            throw new Exception('Invalid column values.' . var_export($entity[$view_name]->problems(), true));
        }
        
        foreach($entity[Target_Cloudsearch::VIEW_FACETS] as $column_name => $column) 
        {            
            $new_name = $this->clean_field_name(sprintf('%s%s%s'
                , $entity->get_root()->get_name()
                , Target_Cloudsearch::DELIMITER
                , $column_name
            ));

            if ($column instanceof Entity_Array)
            {
                $fields_renamed[$new_name] = array();

                if(!count($column)) // no rows
                {
                    $fields_renamed[$new_name][] = '';
                } 
                else if ($column[0] instanceof Entity_Columnset) // rows are columnsets ?
                {
                    $fields_renamed[$new_name] = array_map('json_encode', $column->to_array());
                }
                else  // rows are scalar
                {
                    $fields_renamed[$new_name] = $column->to_array();
                }
            }
            else if ($column instanceof Entity_Columnset)
            {
                if (!$column->is_empty())
                {
                    $fields_renamed[$new_name] = json_encode($column->to_array());
                }
                else 
                {
                    $fields_renamed[$new_name] = '';
                }
            } 
            else if (empty($column))
            {
                $fields_renamed[$new_name] = '';
            }
            else  // scalar
            {
                $fields_renamed[$new_name] = $column;
            }
        }

        foreach(array(Entity_Root::VIEW_KEY, Target_Cloudsearch::VIEW_INDEXER) as $view_name)
        {
            if(!$entity[$view_name]->validate())
            {
                throw new Exception('Invalid column values.' . var_export($entity[$view_name]->problems(), true));
            }
            
            foreach($entity[$view_name] as $column_name => $column) 
            {
                $new_name = $this->clean_field_name(sprintf('%s%s%s'
                    , $entity->get_root()->get_name()
                    , Target_Cloudsearch::DELIMITER
                    , $column_name
                ));

                if ($column instanceof Entity_Array)
                {
                    $fields_renamed[$new_name] = array();

                    $value = $column->to_array();
                    if(empty($value)) 
                    {
                        $value = array('');
                    } 
                }
                else if ($column instanceof Entity_Structure)
                {
                    $value = $column->to_array();
                }
                else 
                {   
                    $value = $column;
                }

                if (empty($value)) 
                {
                    $fields_renamed[$new_name] = '';
                } 
                else 
                {
                    $fields_renamed[$new_name] = $value;
                }
            }
        }
        return array(
            'type' => 'add',
            'lang' => 'en',
            'id' => $this->clean_field_name(implode('_', $entity[Entity_Root::VIEW_KEY]->to_array())),
            'version' => time(),
            'fields' => $fields_renamed
        );
    }
    

    public function columnize(Entity_Row $entity, array $document) 
    {
        if (!($payload = array_shift($document['payload'])))
        {
            throw('invalid cloudsearch response ... '. var_export($document, true));
        }

        $result_data = Parse::json_parse($payload, true);
        $result = clone $entity;
        foreach ($result_data as $k => $v) 
        {
            $result[$k] = $v;
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
    public function visit_exact($entity, $column_name, $param) {
        $info = $entity->get_root()->get_target_info($this);
        $column_name_renamed = sprintf('%s%s%s'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
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
    public function visit_search($entity, $column_name, $param) {
        $info = $entity->get_root()->get_target_info($this);
        $search_string = strtr($param, array("'" => "\\\'",'\\' => '\\\\'));
        $search_terms = implode("* ", explode(' ', $search_string));
        return sprintf("(field %s%s%s '%s*')"
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($column_name)
            , $search_terms
        );

    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_max($entity, $column_name, $param) {
        $info = $entity->get_root()->get_target_info($this);
        return sprintf('(filter %s%s%s %s..)'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($column_name)
            , $param
        );

    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_min($entity, $column_name, $param) {
        $info = $entity->get_root()->get_target_info($this);
        return sprintf('(filter %s%s%s %s..)'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
            , $this->clean_field_name($column_name)
            , $param
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_range($entity, $column_name, $min, $max) {
        $info = $entity->get_root()->get_target_info($this);
        return sprintf('(filter %s%s%s %s..%s)'
            , $this->clean_field_name($entity->get_root()->get_name())
            , Target_Cloudsearch::DELIMITER
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
            
            $entity_name = $entity->get_root()->get_name();
            $entity_name = strtolower($entity_name);
            $entity_name = preg_replace('/-/', '_', $entity_name);
            $entity_name = preg_replace('/[^a-z0-9_]/', '', $entity_name);
            $result .= $entity_name;
            
            $result .= Target_Cloudsearch::DELIMITER;
            
            $column_name = $item[0];
            $column_name = strtolower($column_name);
            $column_name = preg_replace('/-/', '_', $column_name);
            $column_name = preg_replace('/[^a-z0-9_]/', '', $column_name);
            $result .= $column_name;
        }
        
        return $result;
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

    public function selector_security(Entity_Row $entity, Selector $selector)
    {
        $security = $selector->security;
        // @TODO move this to child class, it is project/schema specific
        $security->map('any', 'text')->allow('any');
        
        $allowed = array();
        
        foreach($entity[Target_Cloudsearch::VIEW_INDEXER] as $column_name => $column) 
        {
            if(!array_key_exists($column_name, $allowed)) 
            {
                $allowed[$column_name] = array();
            }
            
            /*
            while ($type instanceof Entity_Array || $type instanceof Entity_Pivot)
            {
                $type = $type->get_type();
            }
            */

            if($type instanceof Type_FreeText)
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
    
    /**
     * helper of select helper
     *
     * this helper caches the result of querying cloudsearch (which is expensive)
     */ 
    private function get_search_endpoint(Target_Info_Cloudsearch $info) 
    {
        if (is_null($this->search_endpoint))
        {
            $domain = $this->get_cloudsearch_domain($info);
            $endpoint = $domain->SearchService->Endpoint->to_string();

            //@TODO put hardcoded stuff into config files
            // date is AWS version number, probably wont change
            $this->search_endpoint = sprintf('http://%s/2011-02-01/search?', $endpoint) ;
        }
        return $this->search_endpoint;
    }

    /**
     * helper of create, update, and delete
     *
     * caches expensive cloudsearch document url lookup
     */
    private function get_document_endpoint(Target_Info_Cloudsearch $info) 
    {
        if (is_null($this->search_endpoint))
        {
            $domain = $this->get_cloudsearch_domain($info);
            $this->document_endpoint = $domain->DocService->Endpoint->to_string();
        }
        return $this->document_endpoint;
    }

    /**
     * caches expensive cloudsearch domain info
     *
     * @see get_document_endpoint, 
     * @see get_search_endpoint
     */
    private function get_cloudsearch_domain(Target_Info_Cloudsearch $info) 
    {
        if(is_null($this->cloudsearch_domain))
        {
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

            $config = Kohana::$config->load('cloudsearch')->as_array();
            $config['domain'] = $info->get_domain_name();
            $cloudsearch = new AmazonCloudsearch($config);
            $response = $cloudsearch->describe_domains(array('DomainNames' => $config['domain']));
            if(!isset($response->body->DescribeDomainsResult->DomainStatusList->member))
            {
                echo json_encode($response->body) . "\n";
                throw new Exception("No domain named " . $config['domain']);
            }
            $this->cloudsearch_domain = $response->body->DescribeDomainsResult->DomainStatusList->member;
           
        } 
        return $this->cloudsearch_domain;        
    }
    

    public function validate_entity(Entity_Row $row)
    {
        return $row->get_root() instanceof Target_Cloudsearchable
            && $row[Target_Cloudsearch::VIEW_PAYLOAD] instanceof Entity_Columnset
            && $row[Target_Cloudsearch::VIEW_FACETS] instanceof Entity_Columnset
            && $row[Target_Cloudsearch::VIEW_INDEXER] instanceof Entity_Columnset
            && $row[Entity_Root::VIEW_KEY] instanceof Entity_Columnset
            && $row[Entity_Root::VIEW_TS] instanceof Entity_Columnset
            && $row[Target_Cloudsearch::VIEW_PAYLOAD]->validate()
            && $row[Target_Cloudsearch::VIEW_FACETS]->validate()
            && $row[Target_Cloudsearch::VIEW_INDEXER]->validate()
            && $row[Entity_Root::VIEW_KEY]->validate()
            && $row[Entity_Root::VIEW_TS]->validate();
    }

}
