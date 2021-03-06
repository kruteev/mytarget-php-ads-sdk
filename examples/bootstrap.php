<?php

use Dsl\MyTarget\Token\ClientCredentials\Credentials;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Client as GuzzleClient;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Dsl\MyTarget\Transport\Middleware\HttpMiddlewareStackPrototype;
use Dsl\MyTarget\Transport\Middleware\Impl\ResponseValidatingMiddleware;
use Dsl\MyTarget\Limiting\DoctrineCacheRateLimitProvider;
use Dsl\MyTarget\Limiting\LimitingMiddleware;
use Dsl\MyTarget\Token\TokenAcquirer;
use Dsl\MyTarget\Token\TokenManager;
use Dsl\MyTarget\Token\TokenGrantMiddleware;
use Dsl\MyTarget\Client;
use Dsl\MyTarget\Token\LockManager;
use Dsl\MyTarget\Transport\RequestFactory;
use Dsl\MyTarget\Transport\GuzzleHttpTransport;

$autoloader = require_once __DIR__ . '/../vendor/autoload.php';

AnnotationRegistry::registerLoader([$autoloader, 'loadClass']);

/**
 * Config example
 *
 * $logger = new \Psr\Log\NullLogger();
 * $redis = new \Predis\Client('localhost');
 * $cache = new \DSL\Cache\RedisHashMapCache($redis);
 * $lock = new DSL\Lock\RedisLock($redis);
 * $token = new \Dsl\MyTarget\Token\DoctrineCacheTokenStorage($cache, function($v) {return 'token_' . $v; });
 *
 * return [
 *     'client_id' => '',
 *     'client_secret' => '',
 *     'logger' => $logger,
 *     'cache' => $cache,
 *     'lock' => $lock,
 *     'token_storage' => $token,
 * ];
 */
$config = include __DIR__ . '/.config.php';
$credentials = new Credentials($config['client_id'], $config['client_secret']);

$baseUri = new Uri('https://target.my.com');

$requestFactory = new RequestFactory($baseUri);
$http = new GuzzleHttpTransport(new GuzzleClient());

$httpStack = HttpMiddlewareStackPrototype::newEmpty($http);
$httpStack->push(new ResponseValidatingMiddleware());

$rateLimitProvider = new DoctrineCacheRateLimitProvider($config['cache']);
$httpStack->push(new LimitingMiddleware($rateLimitProvider));

$tokenLockManager = new LockManager($config['lock'], 300, function ($v) { return 'lock_' . $v; });
$tokenAcquirer = new TokenAcquirer($baseUri, $http, $credentials);
$tokenManager = new TokenManager($tokenAcquirer, $config['token_storage'], $credentials, $tokenLockManager);
// Also you can use SimpleGrantMiddleware with own rules
$httpStack->push(new TokenGrantMiddleware($tokenManager));

$client = new Client($requestFactory, $httpStack);

$mapper = \Dsl\MyTarget\simpleMapper(true);

return [$client, $mapper, $config];
