# crontab

## Script Test

```php
<?php

use EasySwoole\Crontab\Crontab;
use EasySwoole\Crontab\Tests\Jobs\JobPerMin;

require_once 'vendor/autoload.php';


$http = new Swoole\Http\Server('0.0.0.0', 9501);
$crontab = new Crontab();
$crontab->register(new JobPerMin());

$crontab->attachToServer($http);
$http->on('request', function ($request, $response) use ($crontab) {

    $ret = $crontab->rightNow('JobPerMin');

    $response->header('Content-Type', 'text/plain');
    $response->end('Hello World ' . $ret);
});

$http->start();
```