<?php

class Entity_Example
extends Entity_Root
implements Target_Cloudsearchable
{
    public function __construct()
    {
        parent::__construct('example');    
        
        $this[Entity_Root::VIEW_KEY] = new Entity_Columnset(Entity_Root::VIEW_KEY);
        $this[Entity_Root::VIEW_KEY]['primary_id'] = new Entity_Column('primary_id', Type::factory('uuid'));
        $this[Entity_Root::VIEW_KEY]->set_attribute(Entity_Root::ATTR_REQUIRED, 'primary_id');

        $this[Entity_Root::VIEW_TS] = new Entity_Columnset('timestamp');
        $this[Entity_Root::VIEW_TS]['modified_at'] = new Entity_Column('modified_at', Type::factory('date'));
        $this[Entity_Root::VIEW_TS]->set_attribute(Entity_Root::ATTR_REQUIRED, 'modified_at');

        $this[Target_Cloudsearch::VIEW_INDEXER] = new Entity_Columnset('indexer');
        // a string literal
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_string_literal'] = new Entity_Column('test_string_literal', Type::factory('string'));

        // a string freetext
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_string_freetext'] = new Entity_Column('test_string_freetext', Type::factory('string'));
        $this[Target_Cloudsearch::VIEW_INDEXER]->set_attribute(Selector::ATTR_TEXT_SEARCH, 'test_string_freetext');

        // a date
        $this[Target_Cloudsearch::VIEW_INDEXER]['date'] = new Entity_Column('test_date', Type::factory('date'));

        // a number
        $this[Target_Cloudsearch::VIEW_INDEXER]['integer'] = new Entity_Column('test_integer', Type::factory('int'));

        // a uuid aka other
        $this[Target_Cloudsearch::VIEW_INDEXER]['uuid'] = new Entity_Column('test_uuid', Type::factory('uuid'));

        // a columnset
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_colset'] = new Entity_Columnset('test_colset');
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_string_literal'] = new Entity_Column('test_string_literal', Type::factory('string'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_string_freetext'] = new Entity_Column('test_string_freetext', Type::factory('string'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_date'] = new Entity_Column('test_date', Type::factory('date'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_integer'] = new Entity_Column('test_integer', Type::factory('int'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_uuid'] = new Entity_Column('test_uuid', Type::factory('uuid'));

        // a simple array
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_simple_array'] = array(new Entity_Column('test_array_simple_node', Type::factory('int')));

        // a pivot
        $pivot = new Entity_Columnset_Join('test_colset_nested');  // entangles with a_nested_array
        $pivot['test_string_pivot'] = new Entity_Column('test_string_literal', Type::factory('string'));
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_pivot'] = array($pivot);


        // a pivot join
        $pivot = new Entity_Columnset_Join('test_colset_nested'); // entangles with a_nested_pivot
        $pivot['test_string_literal'] = new Entity_Column('test_string_literal', Type::factory('string'));
        $pivot['test_string_freetext'] = new Entity_Column('test_string_freetext', Type::factory('string'));
        $pivot['test_date'] = new Entity_Column('test_date', Type::factory('date'));
        $pivot['test_integer'] = new Entity_Column('test_integer', Type::factory('int'));
        $pivot['test_uuid'] = new Entity_Column('test_uuid', Type::factory('uuid'));
        $pivot->set_attribute(Selector::ATTR_TEXT_SEARCH, 'test_string_freetext');
        $pivot->set_attribute(Target_Cloudsearch::ATTR_FACET, 'test_string_freetext');
        $pivot->set_attribute(Target_Cloudsearch::ATTR_FACET, 'test_string_literal');
        $pivot->set_attribute(Target_Cloudsearch::ATTR_FACET, 'test_uuid');
        $pivot->set_attribute(Selector::ATTR_SORTABLE, 'test_uuid');
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_pivot_complex'] = array($pivot);

        // a nested array
        $nestee = new Entity_Columnset('test_colset_nested');
        $nestee['test_string_literal'] = new Entity_Column('test_string_literal', Type::factory('string'));
        $nestee['test_string_freetext'] = new Entity_Column('test_string_freetext', Type::factory('string'));
        $nestee['test_date'] = new Entity_Column('test_date', Type::factory('date'));
        $nestee['test_integer'] = new Entity_Column('test_integer', Type::factory('int'));
        $nestee['test_uuid'] = new Entity_Column('test_uuid', Type::factory('uuid'));
        $nestee->set_attribute(Selector::ATTR_TEXT_SEARCH, 'test_string_freetext');
        $nestee->set_attribute(Target_Cloudsearch::ATTR_FACET, 'test_string_freetext');
        $nestee->set_attribute(Target_Cloudsearch::ATTR_FACET, 'test_string_literal');
        $nestee->set_attribute(Target_Cloudsearch::ATTR_FACET, 'test_uuid');
        $nestee->set_attribute(Selector::ATTR_SORTABLE, 'test_uuid');
        $this[Target_Cloudsearch::VIEW_INDEXER]['test_nested'] = array($nestee);

        $this['api'] = clone $this[Target_Cloudsearch::VIEW_INDEXER];

        $this[Target_Cloudsearch::VIEW_PAYLOAD] = clone $this[Target_Cloudsearch::VIEW_INDEXER];

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

        $entity[Entity_Root::VIEW_KEY]['primary_id'] = 'f59dfde3-3919-4b58-c200-0cb83fd4ff54';
        $entity[Entity_Root::VIEW_TS]['modified_at'] = '2013-02-02';

        $entity['api']['test_colset']['test_string_literal'] = 'a literal string here';
        $entity['api']['test_colset']['test_string_freetext'] = 'a freetext string here';
        $entity['api']['test_colset']['test_date'] = '2012-03-03';
        $entity['api']['test_colset']['test_integer'] = 123456;
        $entity['api']['test_colset']['test_uuid'] = 'ddddcccc-bbbb-aaaa-1111-000000000001';
        $entity['api']['test_simple_array'] = array(
            10,20,30,40,50
        );
        $entity['api']['test_nested'] = array(
            array(
                'test_string_literal' => 'pivot L 1',
                'test_string_freetext' => 'pivot F 1',
                'test_date' => '2001-01-01',
                'test_integer' => 1111,
                'test_uuid' => 'ddddcccc-bbbb-aaaa-1111-000000000001',
            ),
            array(
                'test_string_literal' => 'pivot L 2',
                'test_string_freetext' => 'pivot F 2',
                'test_date' => '2001-02-02',
                'test_integer' => 2222,
                'test_uuid' => 'ddddcccc-bbbb-aaaa-1111-000000000002',
            ),
            array(
                'test_string_literal' => 'pivot L 3',
                'test_string_freetext' => 'pivot F 3',
                'test_date' => '2001-03-03',
                'test_integer' => 3333,
                'test_uuid' => 'ddddcccc-bbbb-aaaa-1111-000000000003',
            ),
        );





        $json = $target->create_document($entity);
        $parsed = Parse::json_parse($json, true);

        $this->assertEquals(5, count($parsed));
        $this->assertEquals("add", $parsed['type']);
        $this->assertEquals("en", $parsed['lang']);
        $this->assertEquals("example_f59dfde3_3919_4b58_c200_0cb83fd4ff54", $parsed['id']);
        $this->assertInternalType('integer', $parsed['version']);
        $this->assertInternalType('array', $parsed['fields']);
        
        $fields = $parsed['fields'];
        $this->assertEquals(21, count($fields));

        $this->assertEquals("example", $fields[Target_Cloudsearch::FIELD_ENTITY]);
        $this->assertInternalType('string', $fields[Target_Cloudsearch::FIELD_PAYLOAD]);

        $this->assertEquals($entity[Entity_Root::VIEW_KEY]['primary_id'], $fields['example' . Target_Cloudsearch::DELIMITER . 'primary_id'][0]);
        $this->assertInternalType('array', $fields[sprintf('example%sprimary_id', Target_Cloudsearch::DELIMITER)]);
        $this->assertEquals($fields[sprintf('example%sprimary_id', Target_Cloudsearch::DELIMITER)][0], $entity[Entity_Root::VIEW_KEY]['primary_id']);


        $this->assertInternalType('array', $fields[sprintf('example%stest_string_literal', Target_Cloudsearch::DELIMITER)]);
        $this->assertEquals($fields[sprintf('example%stest_string_literal', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['test_string_literal']);
        $this->assertInternalType('array', $fields[sprintf('example%stest_string_freetext', Target_Cloudsearch::DELIMITER)]);
        $this->assertEquals($fields[sprintf('example%stest_string_freetext', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['test_string_freetext']);

        $date = DateTime::createFromFormat('Y-m-d', $entity[Target_Cloudsearch::VIEW_INDEXER]['date']);
        $this->assertEquals($fields[sprintf('example%sdate', Target_Cloudsearch::DELIMITER)][0], $date->format('U'));

        $this->assertEquals($fields[sprintf('example%sinteger', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['integer']);
        $this->assertInternalType('array', $fields[sprintf('example%suuid', Target_Cloudsearch::DELIMITER)]);
        $this->assertEquals($fields[sprintf('example%suuid', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['uuid']);

        $this->assertInternalType('array', $fields[sprintf('example%stest_colset_test_string_literal', Target_Cloudsearch::DELIMITER)]);
        $this->assertEquals($fields[sprintf('example%stest_colset_test_string_literal', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_string_literal']);
        $this->assertInternalType('array', $fields[sprintf('example%stest_colset_test_string_freetext', Target_Cloudsearch::DELIMITER)]);
        $this->assertEquals($fields[sprintf('example%stest_colset_test_string_freetext', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_string_freetext']);

        $date = DateTime::createFromFormat('Y-m-d', $entity[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_date']);
        $this->assertEquals($fields[sprintf('example%stest_colset_test_date', Target_Cloudsearch::DELIMITER)][0], $date->format('U'));

        $this->assertEquals($fields[sprintf('example%stest_colset_test_integer', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_integer']);
        $this->assertInternalType('array', $fields[sprintf('example%stest_colset_test_uuid', Target_Cloudsearch::DELIMITER)]);
        $this->assertEquals($fields[sprintf('example%stest_colset_test_uuid', Target_Cloudsearch::DELIMITER)][0], $entity[Target_Cloudsearch::VIEW_INDEXER]['test_colset']['test_uuid']);


        $this->assertInternalType('array', $fields[sprintf('example%stest_simple_array', Target_Cloudsearch::DELIMITER)]);

        $this->assertCount(5, $fields[sprintf('example%stest_simple_array', Target_Cloudsearch::DELIMITER)]);
        for ($i = 0; $i < 5; $i++)
        {
            $this->assertEquals($entity[Target_Cloudsearch::VIEW_INDEXER]['test_simple_array'][$i], $fields[sprintf('example%stest_simple_array', Target_Cloudsearch::DELIMITER)][$i]);
        }
//        $this->assertEquals($fields[sprintf('example%stest_simple_array', Target_Cloudsearch::DELIMITER)], $entity[Target_Cloudsearch::VIEW_INDEXER]['test_simple_array']);
        
        //pivot
        $this->assertInternalType('array', $fields[sprintf('example%stest_pivot', Target_Cloudsearch::DELIMITER)]);
        $this->assertCount(3, $fields[sprintf('example%stest_pivot', Target_Cloudsearch::DELIMITER)]);
        
        // pivot complex
        $this->assertInternalType('array', $fields[sprintf('example%stest_pivot_complex', Target_Cloudsearch::DELIMITER)]);
        $this->assertCount(3, $fields[sprintf('example%stest_pivot_complex', Target_Cloudsearch::DELIMITER)]);

        for ($i = 0; $i < 3; $i++)
        {
            $this->assertEquals($entity[Target_Cloudsearch::VIEW_INDEXER]['test_pivot'][$i], $fields[sprintf('example%stest_pivot', Target_Cloudsearch::DELIMITER)][$i]);
            $this->assertEquals($entity[Target_Cloudsearch::VIEW_INDEXER]['test_pivot_complex'][$i], $fields[sprintf('example%stest_pivot_complex', Target_Cloudsearch::DELIMITER)][$i]);

        }

        // nested
        $this->assertArrayNotHasKey(sprintf('example%stest_nested', Target_Cloudsearch::DELIMITER), $fields);

        // all nested fields converted to array
        $this->assertInternalType('array', $fields[sprintf('example%stest_nested_test_string_literal', Target_Cloudsearch::DELIMITER)]);
        $this->assertInternalType('array', $fields[sprintf('example%stest_nested_test_string_freetext', Target_Cloudsearch::DELIMITER)]);
        $this->assertInternalType('array', $fields[sprintf('example%stest_nested_test_integer', Target_Cloudsearch::DELIMITER)]);
        $this->assertInternalType('array', $fields[sprintf('example%stest_nested_test_uuid', Target_Cloudsearch::DELIMITER)]);

        for ($i = 0; $i < 3; $i++)
        {
            $this->assertContains(
                $entity[Target_Cloudsearch::VIEW_INDEXER]['test_nested'][$i]['test_string_literal']
                , $fields[sprintf('example%stest_nested_test_string_literal', Target_Cloudsearch::DELIMITER)]
            );
            $this->assertContains($entity[Target_Cloudsearch::VIEW_INDEXER]['test_nested'][$i]['test_string_freetext'], $fields[sprintf('example%stest_nested_test_string_freetext', Target_Cloudsearch::DELIMITER)]);
            $this->assertContains($entity[Target_Cloudsearch::VIEW_INDEXER]['test_nested'][$i]['test_integer'], $fields[sprintf('example%stest_nested_test_integer', Target_Cloudsearch::DELIMITER)]);
            $this->assertContains($entity[Target_Cloudsearch::VIEW_INDEXER]['test_nested'][$i]['test_uuid'], $fields[sprintf('example%stest_nested_test_uuid', Target_Cloudsearch::DELIMITER)]);
            $date = DateTime::createFromFormat('Y-m-d', $entity[Target_Cloudsearch::VIEW_INDEXER]['test_nested'][$i]['test_date']);
            $this->assertContains($date->format('U'), $fields[sprintf('example%stest_nested_test_date', Target_Cloudsearch::DELIMITER)]);
        }


    }
}
