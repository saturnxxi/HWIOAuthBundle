<?php

/*
 * This file is part of the HWIOAuthBundle package.
 *
 * (c) Hardware.Info <opensource@hardware.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HWI\Bundle\OAuthBundle\Tests\OAuth\ResourceOwner;

use HWI\Bundle\OAuthBundle\OAuth\ResourceOwner\GenericOAuth1ResourceOwner;
use Symfony\Component\HttpFoundation\Request;

class GenericOAuth1ResourceOwnerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var GenericOAuth1ResourceOwner
     */
    protected $resourceOwner;
    protected $buzzClient;
    protected $buzzResponse;
    protected $buzzResponseContentType;
    protected $storage;

    protected $userResponse = '{"id": "1", "foo": "bar"}';
    protected $options = array(
        'client_id'           => 'clientid',
        'client_secret'       => 'clientsecret',

        'infos_url'           => 'http://user.info/?test=1',
        'request_token_url'   => 'http://user.request/?test=2',
        'authorization_url'   => 'http://user.auth/?test=3',
        'access_token_url'    => 'http://user.access/?test=4',

        'user_response_class' => '\HWI\Bundle\OAuthBundle\OAuth\Response\PathUserResponse',

        'signature_method'    => 'HMAC-SHA1',

        'csrf'                => false,

        'realm'               => null,
        'scope'               => null,
    );

    protected $paths = array(
        'identifier' => 'id',
        'nickname'   => 'foo',
        'realname'   => 'foo_disp',
    );

    public function setUp()
    {
        $this->resourceOwner = $this->createResourceOwner('oauth1');
    }

    public function testGetOption()
    {
        $this->assertEquals($this->options['infos_url'], $this->resourceOwner->getOption('infos_url'));
        $this->assertEquals($this->options['request_token_url'], $this->resourceOwner->getOption('request_token_url'));
        $this->assertEquals($this->options['authorization_url'], $this->resourceOwner->getOption('authorization_url'));
        $this->assertEquals($this->options['access_token_url'], $this->resourceOwner->getOption('access_token_url'));

        $this->assertEquals($this->options['user_response_class'], $this->resourceOwner->getOption('user_response_class'));

        $this->assertEquals($this->options['signature_method'], $this->resourceOwner->getOption('signature_method'));

        $this->assertEquals($this->options['realm'], $this->resourceOwner->getOption('realm'));
        $this->assertEquals($this->options['scope'], $this->resourceOwner->getOption('scope'));
        $this->assertEquals($this->options['csrf'], $this->resourceOwner->getOption('csrf'));
    }

    public function testGetOptionWithDefaults()
    {
        $buzzClient = $this->getMockBuilder('\Buzz\Client\ClientInterface')
            ->disableOriginalConstructor()->getMock();
        $httpUtils = $this->getMockBuilder('\Symfony\Component\Security\Http\HttpUtils')
            ->disableOriginalConstructor()->getMock();

        $storage = $this->getMock('\HWI\Bundle\OAuthBundle\OAuth\RequestDataStorageInterface');

        $resourceOwner = new GenericOAuth1ResourceOwner($buzzClient, $httpUtils, array(), 'oauth1', $storage);

        $this->assertNull($resourceOwner->getOption('client_id'));
        $this->assertNull($resourceOwner->getOption('client_secret'));

        $this->assertNull($resourceOwner->getOption('infos_url'));

        $this->assertEquals('HWI\Bundle\OAuthBundle\OAuth\Response\PathUserResponse', $resourceOwner->getOption('user_response_class'));

        $this->assertNull($resourceOwner->getOption('realm'));
        $this->assertNull($resourceOwner->getOption('scope'));
        $this->assertFalse($resourceOwner->getOption('csrf'));

        $this->assertEquals('HMAC-SHA1', $resourceOwner->getOption('signature_method'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testGetInvalidOptionThrowsException()
    {
        $this->resourceOwner->getOption('non_existing');
    }

    public function testGetUserInformation()
    {
        $this->mockBuzz($this->userResponse, 'application/json; charset=utf-8');

        $accessToken  = array('oauth_token' => 'token', 'oauth_token_secret' => 'secret');
        $userResponse = $this->resourceOwner->getUserInformation($accessToken);

        $this->assertEquals('1', $userResponse->getUsername());
        $this->assertEquals('bar', $userResponse->getNickname());
        $this->assertEquals($accessToken['oauth_token'], $userResponse->getAccessToken());
        $this->assertNull($userResponse->getRefreshToken());
        $this->assertNull($userResponse->getExpiresIn());
    }

    public function testGetAuthorizationUrlContainOAuthTokenAndSecret()
    {
        $this->mockBuzz('{"oauth_token": "token", "oauth_token_secret": "secret"}', 'application/json; charset=utf-8');

        $this->storage->expects($this->once())
            ->method('save')
            ->with($this->resourceOwner, array('oauth_token' => 'token', 'oauth_token_secret' => 'secret', 'timestamp' => time()));

        $this->assertEquals(
            $this->options['authorization_url'].'&oauth_token=token',
            $this->resourceOwner->getAuthorizationUrl('http://redirect.to/')
        );
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\AuthenticationException
     */
    public function testGetAuthorizationUrlFailedResponseContainOnlyOAuthToken()
    {
        $this->mockBuzz('{"oauth_token": "token"}', 'application/json; charset=utf-8');

        $this->storage->expects($this->never())
            ->method('save');

        $this->resourceOwner->getAuthorizationUrl('http://redirect.to/');
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\AuthenticationException
     */
    public function testGetAuthorizationUrlFailedResponseContainOAuthProblem()
    {
        $this->mockBuzz('oauth_problem=message');

        $this->storage->expects($this->never())
            ->method('save');

        $this->resourceOwner->getAuthorizationUrl('http://redirect.to/');
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\AuthenticationException
     */
    public function testGetAuthorizationUrlFailedResponseNotContainOAuthTokenOrSecret()
    {
        $this->mockBuzz('invalid');

        $this->storage->expects($this->never())
            ->method('save');

        $this->resourceOwner->getAuthorizationUrl('http://redirect.to/');
    }

    public function testGetAccessToken()
    {
        $this->mockBuzz('oauth_token=token&oauth_token_secret=secret');

        $request = new Request(array('oauth_verifier' => 'code', 'oauth_token' => 'token'));

        $this->storage->expects($this->once())
            ->method('fetch')
            ->with($this->resourceOwner, 'token')
            ->will($this->returnValue(array('oauth_token' => 'token2', 'oauth_token_secret' => 'secret2')));

        $this->assertEquals(
            array('oauth_token' => 'token', 'oauth_token_secret' => 'secret'),
            $this->resourceOwner->getAccessToken($request, 'http://redirect.to/')
        );
    }

    public function testGetAccessTokenJsonResponse()
    {
        $this->mockBuzz('{"oauth_token": "token", "oauth_token_secret": "secret"}', 'application/json');

        $request = new Request(array('oauth_verifier' => 'code', 'oauth_token' => 'token'));

        $this->storage->expects($this->once())
            ->method('fetch')
            ->with($this->resourceOwner, 'token')
            ->will($this->returnValue(array('oauth_token' => 'token2', 'oauth_token_secret' => 'secret2')));

        $this->assertEquals(
            array('oauth_token' => 'token', 'oauth_token_secret' => 'secret'),
            $this->resourceOwner->getAccessToken($request, 'http://redirect.to/')
        );
    }

    public function testGetAccessTokenJsonCharsetResponse()
    {
        $this->mockBuzz('{"oauth_token": "token", "oauth_token_secret": "secret"}', 'application/json; charset=utf-8');

        $request = new Request(array('oauth_verifier' => 'code', 'oauth_token' => 'token'));

        $this->storage->expects($this->once())
            ->method('fetch')
            ->with($this->resourceOwner, 'token')
            ->will($this->returnValue(array('oauth_token' => 'token2', 'oauth_token_secret' => 'secret2')));

        $this->assertEquals(
            array('oauth_token' => 'token', 'oauth_token_secret' => 'secret'),
            $this->resourceOwner->getAccessToken($request, 'http://redirect.to/')
        );
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\AuthenticationException
     */
    public function testGetAccessTokenFailedResponse()
    {
        $this->mockBuzz('invalid');

        $this->storage->expects($this->once())
            ->method('fetch')
            ->will($this->returnValue(array('oauth_token' => 'token', 'oauth_token_secret' => 'secret')));

        $this->storage->expects($this->never())
            ->method('save');

        $request = new Request(array('oauth_token' => 'token', 'oauth_verifier' => 'code'));

        $this->resourceOwner->getAccessToken($request, 'http://redirect.to/');
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\AuthenticationException
     */
    public function testGetAccessTokenErrorResponse()
    {
        $this->mockBuzz('error=foo');

        $this->storage->expects($this->once())
            ->method('fetch')
            ->will($this->returnValue(array('oauth_token' => 'token', 'oauth_token_secret' => 'secret')));

        $this->storage->expects($this->never())
            ->method('save');

        $request = new Request(array('oauth_token' => 'token', 'oauth_verifier' => 'code'));

        $this->resourceOwner->getAccessToken($request, 'http://redirect.to/');
    }

    public function testRefreshAccessToken()
    {
        $this->setExpectedException('\Symfony\Component\Security\Core\Exception\AuthenticationException');

        $this->resourceOwner->refreshAccessToken('token');
    }

    public function testRevokeToken()
    {
        $this->setExpectedException('\Symfony\Component\Security\Core\Exception\AuthenticationException');

        $this->resourceOwner->revokeToken('token');
    }

    public function testCsrfTokenIsAlwaysValidForOAuth1()
    {
        $this->storage->expects($this->never())
            ->method('fetch');

        $this->assertFalse($this->resourceOwner->getOption('csrf'));
        $this->assertTrue($this->resourceOwner->isCsrfTokenValid('valid_token'));
    }

    public function testCsrfTokenValid()
    {
        $resourceOwner = $this->createResourceOwner('oauth1', array('csrf' => true));

        $this->storage->expects($this->never())
            ->method('fetch');

        $this->assertTrue($resourceOwner->getOption('csrf'));
        $this->assertTrue($resourceOwner->isCsrfTokenValid('valid_token'));
    }

    public function testGetSetName()
    {
        $this->assertEquals('oauth1', $this->resourceOwner->getName());
        $this->resourceOwner->setName('foo');
        $this->assertEquals('foo', $this->resourceOwner->getName());
    }

    public function testCustomResponseClass()
    {
        $class         = '\HWI\Bundle\OAuthBundle\Tests\Fixtures\CustomUserResponse';
        $resourceOwner = $this->createResourceOwner('oauth1', array('user_response_class' => $class));

        $this->mockBuzz();

        /**
         * @var $userResponse \HWI\Bundle\OAuthBundle\Tests\Fixtures\CustomUserResponse
         */
        $userResponse = $resourceOwner->getUserInformation(array('oauth_token' => 'token', 'oauth_token_secret' => 'secret'));

        $this->assertInstanceOf($class, $userResponse);
        $this->assertEquals('foo666', $userResponse->getUsername());
        $this->assertEquals('foo', $userResponse->getNickname());
        $this->assertEquals('token', $userResponse->getAccessToken());
        $this->assertNull($userResponse->getRefreshToken());
        $this->assertNull($userResponse->getExpiresIn());
    }

    public function buzzSendMock($request, $response)
    {
        $response->setContent($this->buzzResponse);
        $response->addHeader('Content-Type: ' . $this->buzzResponseContentType);
    }

    protected function mockBuzz($response = '', $contentType = 'text/plain')
    {
        $this->buzzClient->expects($this->once())
            ->method('send')
            ->will($this->returnCallback(array($this, 'buzzSendMock')));
        $this->buzzResponse = $response;
        $this->buzzResponseContentType = $contentType;
    }

    protected function createResourceOwner($name, array $options = array(), array $paths = array())
    {
        $this->buzzClient = $this->getMockBuilder('\Buzz\Client\ClientInterface')
            ->disableOriginalConstructor()->getMock();
        $httpUtils = $this->getMockBuilder('\Symfony\Component\Security\Http\HttpUtils')
            ->disableOriginalConstructor()->getMock();

        $this->storage = $this->getMock('\HWI\Bundle\OAuthBundle\OAuth\RequestDataStorageInterface');

        $resourceOwner = $this->setUpResourceOwner($name, $httpUtils, array_merge($this->options, $options));
        $resourceOwner->addPaths(array_merge($this->paths, $paths));

        return $resourceOwner;
    }

    protected function setUpResourceOwner($name, $httpUtils, array $options)
    {
        return new GenericOAuth1ResourceOwner($this->buzzClient, $httpUtils, $options, $name, $this->storage);
    }
}
