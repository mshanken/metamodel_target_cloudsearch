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
    const VIEW_PAYLOAD = 'cloudsearch_payload';
    const VIEW_FACETS = 'cloudsearch_facets';
    const VIEW_INDEXER = 'cloudsearch_indexer';

    const DELIMITER = '__x__';

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
    
    protected static $elapsed = null;
    
    /**
     * implements selectable
     */
    public function select(Entity_Row $entity, Selector $selector = null)
    {
        $info = $entity->get_root()->get_target_info($this);

        $query_parameters = array();

        $base_query = $selector->build_target_query($entity, $this);
        $field_query = sprintf("(field entity '%s')",
            strtr($entity->get_root()->get_name(), array("'" => "\\\'","\\" => "\\\\")));
        if(is_null($base_query)) {
            $query_parameters['bq'] = $field_query;
        }
        else
        {
            $query_parameters['bq'] = sprintf("(and %s %s)", $field_query, $base_query);
        }
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
        $url = $this->get_search_endpoint();

        $url .= $query_string;
        $url = strtr($url, array(' ' => '%20'));
        $options = array();
        
        $options[CURLOPT_RETURNTRANSFER] = TRUE;
        
        $session = curl_init($url);
        curl_setopt_array($session, $options);
        $response_body = curl_exec($session);
        $response = Parse::json_parse($response_body, true);
        $response_code = curl_getinfo($session, CURLINFO_HTTP_CODE);
        if($response_code == 100)
        {
            throw new HTTP_Exception_100('Throttled.');
        } else if($response_code != 200) 
        {
            throw new Exception('Cloudsearch error : ' . $response['messages'][0]['message']
              . ' ... the URL we hit was: ' . $url);
            throw new Exception('Cloudsearch error : ' . $response['messages'][0]['message']);
        }
        curl_close($session);
       
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
        $cloudsearch_endpoint = $this->get_document_endpoint();
        
        /* IAK
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
        */

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
        
        /* IAK
        $curl = curl_init($cloudsearch_endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $concatenated);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
        $response_data = curl_exec($curl);
        $response_info = curl_getinfo($curl);
        $succeeded = ($response_info['http_code'] == 200);
        $throttled = ($response_info['http_code'] == 100);
        */

        //@TODO returns ? throttled ?
    }

    /**
     * dchan function meant to be a drop in replacement for create_helper()
     *
     * @author dchan@mshanken.com
     */
    public function targetize(Entity_Row $entity) 
    { 
        $entity_name = $entity->get_root()->get_name();
        $timestamp = $entity['timestamp']->to_array();
        if(is_array($timestamp) && array_key_exists(0, $timestamp))
        {
            $timestamp = $timestamp[0];
        }
        else
        {
            $timestamp = time();
        }
        $payload = array();
        $payload['key'] = $entity['key']->to_array();
        $payload['timestamp'] = $entity['timestamp']->to_array();
        $payload['cloudsearch_payload'] = $entity['cloudsearch_payload']->to_array();
        $fields = array();
        $fields['entity'] = $entity_name;
        $fields['payload'] = json_encode($payload);
        foreach(array('cloudsearch_indexer', 'cloudsearch_facets') as $view_name)
        {
            $children = $entity[$view_name]->get_children();
            $array = $entity[$view_name]->to_array();
            if(is_null($array))
            {
                $array = array();
            }
            foreach($array as $alias => $value)
            {
                $child = $children[$alias];
                if(($child instanceof Entity_Array_Nested)
                   && !($child instanceof Entity_Array_Pivot))
                {
                    $value = array_map('json_encode', $value);
                }
                else if($child instanceof Entity_Columnset)
                {
                    $value = json_encode($value);
                }
                $fields[$entity_name . '__x__' . $this->clean_field_name($alias)] = $value;
            }
        }
        foreach($fields as $name => $value) {
            if(is_null($value)) $fields[$name] = '';
            else if(is_array($value) && !count($value)) $fields[$name] = array('');
        }
        $id_values = array();
        foreach($entity['key']->to_array() as $alias => $value)
        {
            $id_values[] = $value;
        }
        $id = $this->clean_field_name($entity_name) . '_' . $this->clean_field_name(implode('_', $id_values));
        $document_add = array();
        $document_add['type'] = 'add';
        $document_add['lang'] = 'en';
        $document_add['id'] = $id;
        $document_add['version'] = $timestamp;
        $document_add['fields'] = $fields;
        return json_encode($document_add);
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
            , $this->clean_field_name($this->lookup_entanglement_name($entity, $column_name))
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
        $search_terms = explode(' ', $search_string);
        if($column_name == "text")
        {
            $field_name = "text";
        }
        else
        {
            $field_name = sprintf("%s%s%s"
                , $this->clean_field_name($entity->get_root()->get_name())
                , Target_Cloudsearch::DELIMITER
                , $this->clean_field_name($this->lookup_entanglement_name($entity, $column_name))
            );
        }
        
        // Build search query with wildcard and exact for each word in string
        for($i=0;$i<count($search_terms);$i++)
        {
            $search_terms[$i] = sprintf("(or (and (field %s '%s')) (and (field %s '%s*')))",
                $field_name,
                $search_terms[$i],
                $field_name,
                $search_terms[$i]
            );
        }
        
        $cloudsearch_string = implode(' ', $search_terms);
        
        return $cloudsearch_string;

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
            , $this->clean_field_name($this->lookup_entanglement_name($entity, $column_name))
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
            , $this->clean_field_name($this->lookup_entanglement_name($entity, $column_name))
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
            , $this->clean_field_name($this->lookup_entanglement_name($entity, $column_name))
            , $min
            , $max
        );
    }
    
    /**
     * satisfy selector visitor interface
     *
     */
    public function visit_isnull($entity, $column_name) {
        $info = $entity->get_root()->get_target_info($this);
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
        $i = 0;
        foreach($items as $item)
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
            
            $i++;
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

    /**
     * helper of select helper
     *
     * this helper caches the result of querying cloudsearch (which is expensive)
     */ 
    public function get_search_endpoint() 
    {
        if (is_null($this->search_endpoint))
        {
            $this->get_cloudsearch_domain();
        }
        return $this->search_endpoint;
    }

    /**
     * helper of create, update, and delete
     *
     * caches expensive cloudsearch document url lookup
     */
    public function get_document_endpoint() 
    {
        if (is_null($this->document_endpoint))
        {
            $this->get_cloudsearch_domain();
        }
        return $this->document_endpoint;
    }

    /**
     * caches expensive cloudsearch domain info
     *
     * @see get_document_endpoint, 
     * @see get_search_endpoint
     */
    private function get_cloudsearch_domain() 
    {
        $memcache = new Memcache;
        $memcache->connect(Kohana::$config->load('cloudsearch.cache_host'), Kohana::$config->load('cloudsearch.cache_port'));
        
        $csdomain = Kohana::$config->load('cloudsearch.domain_name');
        
        $search_endpoint = $memcache->get('cloudsearch_search_endpoint'.$csdomain);
        $document_endpoint = $memcache->get('cloudsearch_document_endpoint'.$csdomain);
        
        if($search_endpoint && $document_endpoint)
        {
            $this->search_endpoint = $search_endpoint;
            $this->document_endpoint = $document_endpoint;
        }
        else
        {
            $config = Kohana::$config->load('cloudsearch.login');
            $config['domain'] = Kohana::$config->load('cloudsearch.domain_name');
            $cloudsearch = new AmazonCloudsearch($config);
            $response = $cloudsearch->describe_domains
                (array('DomainNames' => $csdomain));
            if(!isset($response->body->DescribeDomainsResult->DomainStatusList->member))
            {
                echo json_encode($response->body) . "\n";
                throw new Exception("No domain named " . $csdomain);
            }
            $domain = $response->body->DescribeDomainsResult->DomainStatusList->member;
            
            $search_endpoint = $domain->SearchService->Endpoint->to_string();
            //@TODO put hardcoded stuff into config files
            // date is AWS version number, probably wont change
            $this->search_endpoint = sprintf('http://%s/2011-02-01/search?', $search_endpoint) ;
            
            $document_endpoint = $domain->DocService->Endpoint->to_string();
            $this->document_endpoint = sprintf('http://%s/2011-02-01/documents/batch', $document_endpoint);
            
            $cache_ttl = Kohana::$config->load('cloudsearch.cache_ttl');
            
            error_log("CS domain: " . $csdomain . "; search endpoint: " . $this->search_endpoint . "; document endpoint: " . $this->document_endpoint);
            $memcache->set('cloudsearch_search_endpoint'.$csdomain, $this->search_endpoint, false, $cache_ttl);
            $memcache->set('cloudsearch_document_endpoint'.$csdomain, $this->document_endpoint, false, $cache_ttl);
            
        } 
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
    
    public function debug_info()
    {
        return array('elapsed' => Metamodel_Target_Cloudsearch::$elapsed);
    }

    public function lookup_entanglement_name($entity, $entanglement_name)
    {
        foreach(array(Target_Cloudsearch::VIEW_INDEXER, Target_Cloudsearch::VIEW_FACETS) as $view)
        {
            $result = $entity[$view]->lookup_entanglement_name($entanglement_name);
            if(!is_null($result))
            {
                return $result;
            }
        }
        return NULL;
    }
 
}
