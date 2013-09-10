<?php

class Entity_Example
extends Entity_Root
implements Target_Cloudsearchable
{
    public function __construct()
    {
        parent::__construct('example');    
        
        $this['key'] = new Entity_Columnset('key');
        $this['key']['primary_id'] = new Entity_Column('primary_id', Type::factory('uuid'));
        $this['key']->set_attribute(Entity_Root::REQUIRED, 'primary_id');

        $this['timestamp'] = new Entity_Columnset('timestamp');
        $this['timestamp']['modified_at'] = new Entity_Column('modified_at', Type::factory('date'));
        $this['timestamp']->set_attribute(Entity_Root::REQUIRED, 'modified_at');

        $this['api'] = new Entity_Columnset('api');
        $this['api']['name'] = new Entity_Column('name', Type::factory('string'));
        
        $this['api']['related'] = new Entity_Columnset('related');
        $this['api']['related']['related_id'] = new Entity_Column('related_id', Type::factory('uuid'));
        $this['api']['related']['related_name'] = new Entity_Column('related_name', Type::factory('string'));
        $this['api']['related']->set_attribute(Entity_Root::REQUIRED, 'related_name');
        
        $multiple = new Entity_ColumnSet('multiple');
        $multiple['multiple_id'] = new Entity_Column('multiple_id', Type::factory('uuid'));
        $multiple['multiple_name'] = new Entity_Column('multiple_name', Type::factory('string'));
        $this['api']['multiple'] = array($multiple);

        $this[Target_Cloudsearch::VIEW_INDEXER] = new Entity_Columnset(Target_Cloudsearch::VIEW_INDEXER);
        $this[Target_Cloudsearch::VIEW_INDEXER]['primary_id'] = new Entity_Column('primary_id', Type::factory('uuid'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['name'] = new Entity_Column('name', Type::factory('freetext'));
        
        // tests for columnset non-facet
        $this[Target_Cloudsearch::VIEW_INDEXER]['related_id'] = new Entity_Column('related_id', Type::factory('uuid'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['related_name'] = new Entity_Column('related_name', Type::factory('freetext'));
        
        // tests for array[columnset] non-facet
        $multiple_id = new Entity_Columnset_Join('multiple');
        $multiple_id['multiple_id'] = new Entity_Column('multiple_id', Type::factory('uuid'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['multiple_id'] = array($multiple_id);

        $multiple_name = new Entity_Columnset_Join('multiple');
        $multiple_name['multiple_name'] = new Entity_Column('multiple_name', Type::factory('freetext'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['multiple_name'] = array($multiple_name);

        $this[Target_Cloudsearch::VIEW_FACETS] = new Entity_Columnset('cloudsearch_facets');
        
        // test for columnset facet
        $related_facet = new Entity_ColumnSet('related_facetable');
        $related_facet['related_id'] = new Entity_Column('related_id', Type::factory('uuid'));
        $related_facet['related_name'] = new Entity_Column('related_name', Type::factory('freetext'));
        $this[Target_Cloudsearch::VIEW_FACETS]['related_facetable'] = $related_facet;

        // test for array[columnset] facet
        $multiple_facet = new Entity_ColumnSet('multiple');
        $multiple_facet['multiple_id'] = new Entity_Column('multiple_id', Type::factory('uuid'));
        $multiple_facet['multiple_name'] = new Entity_Column('multiple_name', Type::factory('freetext'));
        $this[Target_Cloudsearch::VIEW_FACETS]['multiple_facetable'] = array($multiple_facet);

        $this[Target_Cloudsearch::VIEW_PAYLOAD] = new Entity_Columnset('cloudsearch_payload');
        $this[Target_Cloudsearch::VIEW_PAYLOAD]['name'] = new Entity_Column('name', Type::factory('string'));
        
        $this[Target_Cloudsearch::VIEW_PAYLOAD]['related'] = new Entity_Columnset('related');
        $this[Target_Cloudsearch::VIEW_PAYLOAD]['related']['related_id'] = new Entity_Column('related_id', Type::factory('uuid'));
        $this[Target_Cloudsearch::VIEW_PAYLOAD]['related']['name'] = new Entity_Column('related_name', Type::factory('string'));
        $this[Target_Cloudsearch::VIEW_PAYLOAD]['related']->set_attribute(Entity_Root::REQUIRED, 'name');
        
        $multiple = new Entity_ColumnSet('multiple');
        $multiple['multiple_id'] = new Entity_Column('multiple_id', Type::factory('uuid'));
        $multiple['multiple_name'] = new Entity_Column('multiple_name', Type::factory('string'));
        $this[Target_Cloudsearch::VIEW_PAYLOAD]['multiple'] = array($multiple);

        $info = new Target_Info_Cloudsearch();
        $this->set_target_info(new Target_Cloudsearch(), $info);
    }
    
}

class CloudsearchTest extends Unittest_TestCase
{
    public function testCloudsearch()
    {
        $target = new Target_Cloudsearch();        
        $entity = Entity_Example::factory();

        $entity['key']['primary_id'] = 'f59dfde3-3919-4b58-c200-0cb83fd4ff54';

        $entity['timestamp']['modified_at'] = '1357147485';
        $entity['api']['name'] = 'An Entity';

        $entity['api']['related']['related_id'] = 'd3db3422-c7a4-4a73-c8f4-e5940c02c2c1';
        $entity['api']['related']['related_name'] = 'A Related Entity';
        $entity['api']['multiple'][0]['multiple_id'] = '1c0cc778-d5ad-4d4c-9280-064c483df702';
        $entity['api']['multiple'][0]['multiple_name'] = 'A Multiple Entity';
        $entity['api']['multiple'][1]['multiple_id'] = '8663622f-cac6-4041-9cd1-d9f9288b4aa6';
        $entity['api']['multiple'][1]['multiple_name'] = 'Another Multiple Entity';
        
        $json = $target->targetize($entity);
        $parsed = Parse::json_parse($json, true);
        
        $this->assertEquals(5, count($parsed));
        $this->assertEquals("add", $parsed['type']);
        $this->assertEquals("en", $parsed['lang']);
        $this->assertEquals("example_f59dfde3_3919_4b58_c200_0cb83fd4ff54", $parsed['id']);
        $this->assertInternalType('integer', $parsed['version']);
        $this->assertInternalType('array', $parsed['fields']);
        
        $fields = $parsed['fields'];
        $this->assertEquals(10, count($fields));

        $this->assertEquals("example", $fields['entity']);
        $this->assertInternalType('string', $fields['payload']);

        $this->assertEquals($entity['key']['primary_id'], $fields['example__x__primary_id']);
        $this->assertEquals($entity['api']['name'], $fields['example__x__name']);
        $this->assertEquals($entity['api']['related']['related_id'],
                            $fields['example__x__related_id']);
        $this->assertEquals($entity['api']['related']['related_name'],
                            $fields['example__x__related_name']);
        $this->assertInternalType('string', $fields['example__x__related_facetable']);
        $related_fields = Parse::json_parse($fields['example__x__related_facetable'], true);
        $this->assertEquals($entity['api']['related']['related_id'], $related_fields['related_id']);
        $this->assertEquals($entity['api']['related']['related_name'],
                            $related_fields['related_name']);
        
        $this->assertInternalType('array', $fields['example__x__multiple_id']);
        $this->assertEquals(2, count($fields['example__x__multiple_id']));
        $this->assertEquals($entity['api']['multiple'][0]['multiple_id'],
                            $fields['example__x__multiple_id'][0]);
        $this->assertEquals($entity['api']['multiple'][1]['multiple_id'],
                            $fields['example__x__multiple_id'][1]);
        
        $this->assertInternalType('array', $fields['example__x__multiple_name']);
        $this->assertEquals(2, count($fields['example__x__multiple_name']));
        $this->assertEquals($entity['api']['multiple'][0]['multiple_name'],
                            $fields['example__x__multiple_name'][0]);
        $this->assertEquals($entity['api']['multiple'][1]['multiple_name'],
                            $fields['example__x__multiple_name'][1]);
        
        $this->assertInternalType('array', $fields['example__x__multiple_facetable']);

        $this->assertEquals(2, count($fields['example__x__multiple_facetable']));
        $this->assertInternalType('string', $fields['example__x__multiple_facetable'][0]);
        $multiple_fields_0 = Parse::json_parse($fields['example__x__multiple_facetable'][0], true);
        $this->assertEquals($entity['api']['multiple'][0]['multiple_id'],
                            $multiple_fields_0['multiple_id']);
        $this->assertEquals($entity['api']['multiple'][0]['multiple_name'],
                            $multiple_fields_0['multiple_name']);
        $this->assertInternalType('string', $fields['example__x__multiple_facetable'][1]);
        $multiple_fields_1 = Parse::json_parse($fields['example__x__multiple_facetable'][1], true);
        $this->assertEquals($entity['api']['multiple'][1]['multiple_id'],
                            $multiple_fields_1['multiple_id']);
        $this->assertEquals($entity['api']['multiple'][1]['multiple_name'],
                            $multiple_fields_1['multiple_name']);
        
        $mangled = array();
        foreach($parsed['fields'] as $key => $value)
        {
            if(is_array($value))
            {
                $mangled[$key] = $value;
            }
            else
            {
                $mangled[$key] = array($value);
            }
        }

        $entity2 = $target->columnize(Entity_Example::factory(), $mangled);
        
        $this->assertEquals($entity['key']['primary_id'], $entity2['key']['primary_id']);
        $this->assertEquals($entity['timestamp']['modified_at'],
                            $entity2['timestamp']['modified_at']);
        $this->assertEquals($entity['api']['name'], $entity2['api']['name']);
        $this->assertEquals($entity['api']['related']['related_id'],
                            $entity2['api']['related']['related_id']);
        $this->assertEquals($entity['api']['related']['related_name'],
                            $entity2['api']['related']['related_name']);
        $this->assertEquals(2, count($entity2['api']['multiple']));
        $this->assertEquals($entity['api']['multiple'][0]['multiple_id'],
                            $entity2['api']['multiple'][0]['multiple_id']);
        $this->assertEquals($entity['api']['multiple'][0]['multiple_name'],
                            $entity2['api']['multiple'][0]['multiple_name']);
        $this->assertEquals($entity['api']['multiple'][1]['multiple_id'],
                            $entity2['api']['multiple'][1]['multiple_id']);
        $this->assertEquals($entity['api']['multiple'][1]['multiple_name'],
                            $entity2['api']['multiple'][1]['multiple_name']);
    }
}
