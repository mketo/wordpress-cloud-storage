wordpress-cloud-storage
=======================

Simple plugin for wordpress to upload/delete media from Amazon S3.


Installation
------------

1. Download and install plugin
2. Use AWS Identity and Access Management to create a new user
3. Add the below settings to wp-config.php
4. Activate plugin


Settings
--------

Add these settings to wp-config.php:

```PHP
define('WCS_BUCKET', 'My bucket name');
define('WCS_KEY', 'My access key for user');
define('WCS_PREFIX', 'Prefix for bucket');
define('WCS_REGION', 'Region for bucket');
define('WCS_SECRET', 'My access secret for user');
```
