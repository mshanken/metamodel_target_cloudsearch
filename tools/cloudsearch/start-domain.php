<?php
// Usage: Two parameters: a string naming a CloudSearch domain, and
// a filename giving a JSON-format dump of the domain's configuration:
//  php start-domain.php wine-profile ./wine-profile.json

$dry_run = FALSE;

$realpath = realpath(__DIR__ . '/../..');
$application = $realpath . '/application';
$modules = $realpath . '/modules';
$system = $realpath . '/system';
define('EXT', '.php');
error_reporting(E_ALL | E_STRICT);
define('DOCROOT', realpath(dirname(__FILE__)).DIRECTORY_SEPARATOR);
define('APPPATH', realpath($application).DIRECTORY_SEPARATOR);
define('MODPATH', realpath($modules).DIRECTORY_SEPARATOR);
define('SYSPATH', realpath($system).DIRECTORY_SEPARATOR);
unset($application, $modules, $system);
if ( ! defined('KOHANA_START_TIME'))
{
    define('KOHANA_START_TIME', microtime(TRUE));
}
if ( ! defined('KOHANA_START_MEMORY'))
{
    define('KOHANA_START_MEMORY', memory_get_usage());
}
require APPPATH.'bootstrap'.EXT;
ob_end_clean();


function encode_boolean($boolean) {
    if($boolean) return 'true';
    else return 'false';
}

function encode_string($string) {
    return (string) $string;
}

function encode_integer($integer) {
    return (int) $integer;
}


$config = Kohana::$config->load('cloudsearch')->as_array();
$cloudsearch = new AmazonCloudSearch($config);
$domain_name = $argv[1];
$description = json_decode(file_get_contents($argv[2]), true);

echo "Creating domain.\n";
if(!$dry_run) {
    $result = $cloudsearch->create_domain($domain_name);
    if(!$result->isOK()) throw new Exception("Failed.");
}

$passes = array(array(), array());
foreach($description['index_fields'] as $field_name => $field_information) {
    if(!array_key_exists('sources', $field_information)) {
        $passes[0][$field_name] = $field_information;
    } else {
        $passes[1][$field_name] = $field_information;
    }
}

foreach($passes as $pass) {
    foreach($pass as $field_name => $field_information) {
        $field_definition = array('IndexFieldName' => $field_name);
        if($field_information['type'] == 'literal') {
            $field_definition['IndexFieldType'] = 'literal';
            $search_enabled = encode_boolean($field_information['search_enabled']);
            $facet_enabled = encode_boolean($field_information['facet_enabled']);
            $result_enabled = encode_boolean($field_information['result_enabled']);
            $options = array('SearchEnabled' => $search_enabled,
                             'FacetEnabled' => $facet_enabled,
                             'ResultEnabled' => $result_enabled);
            if(array_key_exists('default_value', $field_information))
                $options['DefaultValue'] = encode_string($field_information['default_value']);
            $field_definition['LiteralOptions'] = $options;
        } else if($field_information['type'] == 'uint') {
            $options = array();
            if(array_key_exists('default_value', $field_information))
                $options['DefaultValue'] = encode_integer($field_information['default_value']);
            $field_definition['IndexFieldType'] = 'uint';
            $field_definition['UIntOptions'] = $options;
        } else if($field_information['type'] == 'text') {
            $facet_enabled = encode_boolean($field_information['facet_enabled']);
            $result_enabled = encode_boolean($field_information['result_enabled']);
            $options = array('FacetEnabled' => $facet_enabled,
                             'ResultEnabled' => $result_enabled);
            if(array_key_exists('default_value', $field_information))
                $options['DefaultValue'] = encode_string($field_information['default_value']);
            $field_definition['IndexFieldType'] = 'text';
            $field_definition['TextOptions'] = $options;
        } else {
            throw new Exception("Unhandled case.");
        }
        if(array_key_exists('sources', $field_information)) {
            $sources = array();
            foreach($field_information['sources'] as $source_information) {
                if($source_information['type'] == 'copy') {
                    $source_name = encode_string($source_information['source_name']);
                    $source = array('SourceName' => $source_name);
                    if(array_key_exists('default_value', $source_information))
                        $source['DefaultAttributes'] =
                            encode_string($source_information['default_value']);
                    $source = array('SourceDataFunction' => 'Copy',
                                    'SourceDataCopy' => $source);
                } else if($source_information['type'] == 'trim_title') {
                    throw new Exception("Unhandled case.");
                } else if($source_information['type'] == 'map') {
                    throw new Exception("Unhandled case.");
                } else {
                    throw new Exception("Unhandled case.");
                }
                $sources[] = $source;
            }
            $field_definition['SourceAttributes'] = $sources;
        }
        echo "Creating index field " . $field_name . ".\n";
        if(!$dry_run) {
            $result = $cloudsearch->define_index_field($domain_name, $field_definition);
            if(!$result->isOK()) throw new Exception("Failed.");
        }
    }
}
