<?php
/**
 * Created by IntelliJ IDEA.
 * User: abhijeet.pa
 * Date: 11/07/13
 * Time: 1:45 PM
 * To change this template use File | Settings | File Templates.
 */

require_once './aws/aws-autoloader.php';

use Aws\S3\S3Client;
use Aws\S3\Enum\Permission;
use Aws\S3\Enum\Group;
use Aws\S3\Model\AcpBuilder;

$config=file_get_contents("./aws.creds");
$config = explode("\n",$config);

define('AWS_ACCESS_KEY', $config[0]);
define('AWS_SECRET_KEY', $config[1]);
define('SEPARATOR', ';#;');
define('OWNERID', $config[2]);
define('OWNEREMAIL', $config[3]);

main_function($argv);

function main_function($argv)
{
    if(!(count($argv)>1)){
        print  "Enter valid options:\n".
               "-m: (required) defines the mode, default value 1 (1 for view_buckets, 2 for view_objects in a bucket, 3 for setting objects metadata, 4 for setting objects acl)\n".
               "-b: bucket_name, default value 'staging_shared'\n".
               "-k: objects_keys separated by ';#;', default value '/download'\n".
               "-v: visibility, default value 'private'\n".
               "-c: cache_control, default value '86400'\n".
               "-e: expries , default value '+5 years'\n";
        return;
    }

    $options = get_options($argv);
    $func = select_function_based_on_mode($options['mode']);

    return $func($options);
}

function get_S3_client()
{
    $client = S3Client::factory(array(
        'key' => AWS_ACCESS_KEY,
        'secret' => AWS_SECRET_KEY
    ));

    return $client;
}

function get_buckets()
{
    $client = get_S3_client();
    $buckets_iterator = $client->getIterator('ListBuckets');
    return $buckets_iterator;
}

function get_objects_list($options)
{
    $bucket = get_valid_value($options, 'bucket_name', 'staging_shared');
    $client = get_S3_client();
    $objects_iterator = $client->getIterator('ListObjects', array(
        'Bucket' => $bucket
    ));
    return $objects_iterator;
}

function get_valid_value($array, $key, $default)
{
    return (isset($array[$key]) && $array[$key] != "")?$array[$key]:$default;
}

function set_objects_metadata($options)
{
    $bucket_name = get_valid_value($options, 'bucket_name','staging_shared');
    $objects_keys = get_valid_value($options, 'objects_keys','/download');
    $cache_control = get_valid_value($options, 'cache_control', '86400');
    $expires = get_valid_value($options, 'expires', '+5 years');
    $visibility = get_valid_value($options, 'visibility', 'private');

    $visibility = $visibility == 'public'?'public_read':$visibility;

    $client = get_S3_client();
    $response = array();

    if($objects_keys == 'all'){
        $objects_keys = get_object_keys(get_objects_list($options));
    }

    date_default_timezone_set('GMT');
    $objects_keys = explode(SEPARATOR, $objects_keys);

    foreach ($objects_keys as $object_key)
    {
        $headObject = get_headObject($bucket_name, $object_key);

        $metadata = $headObject['Metadata'];
        $metadata ['Cache-Control'] = "max-age=$cache_control";
        $metadata ['Expires'] = gmdate("D, d M Y H:i:s T", strtotime("$expires"));

        $args = array(
            'ACL' => $visibility,
            'Bucket' => $bucket_name,
            'CopySource' => "$bucket_name/$object_key}",
            'Key' => $object_key['Key'],
            'ContentType' => $headObject['ContentType'],
            'Metadata' => $metadata,
            'MetadataDirective' => 'REPLACE'
        );
        $response[] = $client->copyObject($args);
        print "setting metadata $visibility: $bucket_name - $object_key\n";
    }
    return $response;
}

function set_objects_acl($options)
{
    $bucket_name = get_valid_value($options, 'bucket_name','staging_shared');
    $objects = get_valid_value($options, 'objects_keys','/download');
    $visibility = get_valid_value($options, 'visibility', 'private');

    $client = get_S3_client();
    $response = array();

    if($objects == 'all'){
        $objects = get_object_keys(get_objects_list($options));
    }

    $objects = explode(SEPARATOR, $objects);

    foreach ($objects as $object_key) {
        $acp = get_acp_object($visibility);
        $args = array(
            'Bucket' => $bucket_name,
            'Key' => $object_key,
            'ACP' => $acp
        );
        $response[] = $client->putObjectAcl($args);
        print "setting acl $visibility: $bucket_name - $object_key\n";
    }
    return $response;
}

function get_acp_object($visibility)
{
    $acp = AcpBuilder::newInstance()
        ->setOwner(OWNERID)
        ->addGrantForEmail(Permission::FULL_CONTROL, OWNEREMAIL);

    switch ($visibility) {
        case 'public':
            return $acp->addGrantForGroup(Permission::READ, 'http://acs.amazonaws.com/groups/global/AllUsers')->build();
        case 'private':
            return $acp->build();

    }
}

function get_object_keys($objects)
{
    $str = "";
    foreach ($objects as $object) {
        $str .= $object['Key'] . SEPARATOR;
    }
    $str = str_split($str, strlen($str) - 2);
    return $str[0];
}

function get_headObject($bucket_name, $key_name)
{
    $client = get_S3_client();
    $metaData = $client->headObject(array('Bucket' => $bucket_name, 'Key' => $key_name));
    return $metaData;
}

function get_options($argv)
{
    $mode = 1;
    $bucket_name = $objects_keys = $visibility = $cache_control = $expires = "";

    foreach ($argv as $key => $value) {
        $option = substr($value, 0, 2);
        switch ($option) {
            case "-m":
                $arr = explode("-m", $value);
                $mode = trim($arr[1]);
                break;
            case "-b":
                $arr = explode("-b", $value);
                $bucket_name = trim($arr[1]);
                break;
            case "-k":
                $arr = explode("-k", $value);
                $objects_keys = trim($arr[1]);
                break;
            case "-v":
                $arr = explode("-v", $value);
                $visibility = trim($arr[1]);
                break;
            case "-c":
                $arr = explode("-c", $value);
                $cache_control = trim($arr[1]);
                break;
            case "-e":
                $arr = explode("-e", $value);
                $expires = trim($arr[1]);
                break;
        }
    }

    return array('mode' => $mode, 'bucket_name' => $bucket_name, 'objects_keys' => $objects_keys,
                 'visibility' => $visibility, 'cache_control' => $cache_control, 'expires' => $expires);
}

function select_function_based_on_mode($mode)
{
    $function = "";

    switch ($mode) {
        case 1:
            $function = "view_buckets";
            break;
        case 2:
            $function = "view_objects_list";
            break;
        case 3:
            $function = "set_objects_metadata";
            break;
        case 4:
            $function = "set_objects_acl";
            break;
    }

    return $function;
}

function view_buckets($options)
{
    $buckets = get_buckets();
    foreach ($buckets as $bucket) {
        print "{$bucket['Name']}\n";
    }
}

function view_objects_list($options)
{
    $objects = get_objects_list($options);
    foreach ($objects as $object) {
        print "{$object['Key']}\n";
    }
}
