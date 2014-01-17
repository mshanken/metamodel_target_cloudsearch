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

require(__DIR__ . '/../../vendors/aws/autoload.php');
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
$create_result = $cloudsearch->createDomain(array('DomainName' => $domain_name));

foreach ($domain_definition[$domain_name] as $field_name => $field_definition) 
{
    echo "\nCreating index field {$field_name} : ";
    $f = array(
        'DomainName' => $domain_name,
        'IndexField' => $field_definition,
    );
    //var_dump($f);
    $field_result = $cloudsearch->defineIndexField($f);
}

echo "\n\n Configuring Access Policies";

$create_result = $create_result->toArray();
$s_arn = $create_result['DomainStatus']['SearchService']['Arn'];
$d_arn = $create_result['DomainStatus']['DocService']['Arn'];
//@TODO magic
$ip='38.100.174.226';

$policy = array(
    'Statement' => array(
        array(
            'Effect' => 'Allow',
            'Action' => '*',
            'Resource' => $s_arn,
            'Condition' => array(
                'IpAddress' => array(
                    'aws:SourceIp' => array(
                        sprintf('%s/32', $ip),
                    ),
                ),
            ),
        ),
        array(
            'Effect' => 'Allow',
            'Action' => '*',
            'Resource' => $d_arn,
            'Condition' => array(
                'IpAddress' => array(
                    'aws:SourceIp' => array(
                        sprintf('%s/32', $ip),
                    ),
                ),
            ),
        ),
    ),
);

$result = $cloudsearch->UpdateServiceAccessPolicies(array(
    'DomainName' => $domain_name,
    'AccessPolicies' => json_encode($policy),
));

echo "\n\n Configuring stop words";

$cloudsearch->UpdateStopwordOptions(array(
    'DomainName' => $domain_name,
    'Stopwords' => json_encode(array('stopwords'=>array())),
));

echo "\n\n Initial Indexing started to allow data upload";

$cloudsearch->indexDocuments(array('DomainName' => $domain_name));
