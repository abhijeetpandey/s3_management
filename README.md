S3 Management
=========

A php script to manage s3 buckets &amp; objects.


Usage
=========
Run the script with using appropriate options. The script is only for :
* displaying buckets
* displaying objects in any bucket
* changing acl of specified objects of any bucket (making them private/public)
* adding/changing headers of objects (only expires and cache_control)
 

-m: (required) defines the mode, default value 1 (1 for view_buckets, 2 for view_objects in a bucket, 3 for setting objects metadata, 4 for setting objects acl)
-b: bucket_name, default value 'staging_shared'
-k: objects_keys separated by ';#;', default value '/download'
-v: visibility, default value 'private'
-c: cache_control, default value '86400'
-e: expries , default value '+5 years'
