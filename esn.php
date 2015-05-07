<?php

require_once 'vendor/autoload.php';

define('CONFIG_PATH', 'config.json');

$config = json_decode(file_get_contents(CONFIG_PATH), true);
if (!$config) {
    throw new Exception("Could not load config.json from " . realpath(CONFIG_PATH) . ", Error " . json_last_error());
}
$dbConfig = $config['database'];

// settings
date_default_timezone_set('UTC');

define('PRINCIPALS_COLLECTION', 'principals');
define('PRINCIPALS_USERS', 'principals/users');
define('PRINCIPALS_COMMUNITIES', 'principals/communities');
define('PRINCIPALS_PROJECTS', 'principals/projects');
define('JSON_ROOT', 'json');

//Mapping PHP errors to exceptions
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");

try {
    $mongoEsn = new MongoClient($dbConfig['esn']['connectionString'], $dbConfig['esn']['connectionOptions']);
    if ($dbConfig['esn']['connectionString'] == $dbConfig['sabre']['connectionString']) {
        $mongoSabre = $mongoEsn;
    } else {
        $mongoSabre = new MongoClient($dbConfig['sabre']['connectionString'], $dbConfig['sabre']['connectionOptions']);
    }
} catch (MongoConnectionException $e) {
    // Create a fake server that will abort with the exception right away. This
    // allows us to use SabreDAV's exception handler and output.
    $server = new Sabre\DAV\Server([]);
    $server->on('beforeMethod', function() use ($e) {
        throw new Sabre\DAV\Exception\ServiceUnavailable($e->getMessage());
    }, 1);
    $server->exec();
    return;
}

// Databases
$esnDb = $mongoEsn->selectDB($dbConfig['esn']['db']);
$sabreDb = $mongoSabre->selectDB($dbConfig['sabre']['db']);

// Backends
$authBackend = new ESN\DAV\Auth\Backend\Esn($config['esn']['apiRoot'], $config['webserver']['realm']);
$calendarBackend = new ESN\CalDAV\Backend\Esn($sabreDb);
$addressbookBackend = new ESN\CardDAV\Backend\Esn($sabreDb);
$principalBackend = new ESN\DAVACL\PrincipalBackend\Mongo($esnDb);

// Directory structure
$tree = [
    new Sabre\DAV\SimpleCollection(PRINCIPALS_COLLECTION, [
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_USERS),
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_COMMUNITIES),
      new Sabre\CalDAV\Principal\Collection($principalBackend, PRINCIPALS_PROJECTS),
    ]),
    new ESN\CalDAV\CalendarRoot($principalBackend, $calendarBackend, $esnDb),
    new ESN\CardDAV\AddressBookRoot($principalBackend, $addressbookBackend, $esnDb),
];

$server = new Sabre\DAV\Server($tree);
$server->debugExceptions = true;

$server->setBaseUri($config['webserver']['baseUri']);

// Server Plugins
$authPlugin = new Sabre\DAV\Auth\Plugin($authBackend);
$server->addPlugin($authPlugin);

$aclPlugin = new Sabre\DAVACL\Plugin();
$server->addPlugin($aclPlugin);

// JSON api support
$jsonPlugin = new ESN\JSON\Plugin(JSON_ROOT);
$server->addPlugin($jsonPlugin);

// CalDAV support
$caldavPlugin = new Sabre\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

// CardDAV support
$carddavPlugin = new Sabre\CardDAV\Plugin();
$server->addPlugin($carddavPlugin);

// Calendar subscription support
$server->addPlugin(
    new Sabre\CalDAV\Subscriptions\Plugin()
);

// Calendar scheduling support
$server->addPlugin(
    new ESN\CalDAV\Schedule\Plugin()
);

$server->addPlugin(
    new ESN\CalDAV\Schedule\IMipPlugin($config['mail'])
);

// WebDAV-Sync plugin
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend
$browser = new Sabre\DAV\Browser\Plugin();
$server->addPlugin($browser);

// Support CORS
$corsPlugin = new ESN\DAV\CorsPlugin();
if (isset($config['webserver']['corsAllowMethods'])) {
    $corsPlugin->allowMethods = $config['webserver']['corsAllowMethods'];
}
if (isset($config['webserver']['corsAllowHeaders'])) {
    $corsPlugin->allowHeaders = $config['webserver']['corsAllowHeaders'];
}
if (isset($config['webserver']['corsAllowOrigin'])) {
    $corsPlugin->allowOrigin = $config['webserver']['corsAllowOrigin'];
}
if (isset($config['webserver']['corsAllowCredentials'])) {
    $corsPlugin->allowCredentials = $config['webserver']['corsAllowCredentials'];
}
if (isset($config['webserver']['corsExposeHeaders'])) {
    $corsPlugin->exposeHeaders = $config['webserver']['corsExposeHeaders'];
}

// Regardless of the webserver settings, we need to support the ESNToken header
$corsPlugin->allowHeaders[] = 'ESNToken';

$server->addPlugin($corsPlugin);

$esnHookPlugin = new ESN\CalDAV\ESNHookPlugin($config['esn']['calendarRoot'], $authBackend);
$server->addPlugin($esnHookPlugin);

$communityMembersPlugin = new ESN\CalDAV\CollaborationMembersPlugin($esnDb, 'communities');
$server->addPlugin($communityMembersPlugin);

$projectMembersPlugin = new ESN\CalDAV\CollaborationMembersPlugin($esnDb, 'projects');
$server->addPlugin($projectMembersPlugin);

// And off we go!
$server->exec();
