<?php
// Usage: Two parameters: a string naming a CloudSearch domain, and
// a filename giving a JSON-format dump of the domain's configuration:
//  php start-domain.php wine-profile ./wine-profile.json


if (count($argv) != 2)
{
    echo "\n\n Create and define a new cloudsearch domain";
    echo "\n\n Usage : $argv[0] <domain_file.json>";
    echo "\n\n";
    exit(1);
}

$domain_definition_file = $argv[1];

require('vendor/autoload.php');
use Aws\Common\Aws;
use Aws\CloudSearch;

$aws = Aws::factory(array(
    'key'    => 'AKIAJ2CILZCTOEVDBNQQ',
    'secret' => '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',
    'region' => 'us-east-1'
));
$cloudsearch = $aws->get('CloudSearch');

$domain_definition = json_decode(file_get_contents($domain_definition_file), true);
$domain_name = array_keys($domain_definition);
$domain_name = array_shift($domain_name);

echo "\n\nCreating domain: {$domain_name}";
$result = $cloudsearch->createDomain(array('DomainName' => $domain_name));

foreach ($domain_definition[$domain_name] as $field_name => $field_definition) 
{
    echo "\nCreating index field {$field_name} : ";
    $result = $cloudsearch->defineIndexField(array(
        'DomainName' => $domain_name,
        'IndexField' => $field_definition,
    ));
}
