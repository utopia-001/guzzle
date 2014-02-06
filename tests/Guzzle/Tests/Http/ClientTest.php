<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\Adapter\MockAdapter;
use Guzzle\Http\Client;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Exception\RequestException;

/**
 * @covers Guzzle\Http\Client
 */
class ClientTest extends \PHPUnit_Framework_TestCase
{
    public function testProvidesDefaultUserAgent()
    {
        $this->assertEquals(1, preg_match('#^Guzzle/.+ curl/.+ PHP/.+$#', Client::getDefaultUserAgent()));
    }

    public function testUsesDefaultDefaultOptions()
    {
        $client = new Client();
        $this->assertTrue($client->getConfig('defaults/allow_redirects'));
        $this->assertTrue($client->getConfig('defaults/exceptions'));
        $this->assertContains('cacert.pem', $client->getConfig('defaults/verify'));
    }

    public function testUsesProvidedDefaultOptions()
    {
        $client = new Client([
            'defaults' => [
                'allow_redirects' => false,
                'query' => ['foo' => 'bar']
            ]
        ]);
        $this->assertFalse($client->getConfig('defaults/allow_redirects'));
        $this->assertTrue($client->getConfig('defaults/exceptions'));
        $this->assertContains('cacert.pem', $client->getConfig('defaults/verify'));
        $this->assertEquals(['foo' => 'bar'], $client->getConfig('defaults/query'));
    }

    public function testCanSpecifyBaseUrl()
    {
        $this->assertEquals(null, (new Client())->getConfig('base_url'));
        $this->assertEquals('http://foo', (new Client([
            'base_url' => 'http://foo'
        ]))->getConfig('base_url'));
    }

    public function testCanSpecifyBaseUrlUriTemplate()
    {
        $client = new Client(['base_url' => ['http://foo.com/{var}/', ['var' => 'baz']]]);
        $this->assertEquals('http://foo.com/baz/', $client->getConfig('base_url'));
    }

    public function testClientUsesDefaultAdapterWhenNoneIsSet()
    {
        $client = new Client();
        if (!extension_loaded('curl')) {
            $adapter = 'Guzzle\Http\Adapter\StreamAdapter';
        } elseif (ini_get('allow_url_fopen')) {
            $adapter = 'Guzzle\Http\Adapter\StreamingProxyAdapter';
        } else {
            $adapter = 'Guzzle\Http\Adapter\Curl\CurlAdapter';
        }
        $this->assertInstanceOf($adapter, $this->readAttribute($client, 'adapter'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyAdapter()
    {
        $adapter = $this->getMockBuilder('Guzzle\Http\Adapter\AdapterInterface')
            ->setMethods('send')
            ->getMockForAbstractClass();
        $adapter->expects($this->once())
            ->method('send')
            ->will($this->throwException(new \Exception('Foo')));
        $client = new Client(['adapter' => $adapter]);
        $client->get();
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyMessageFactory()
    {
        $factory = $this->getMockBuilder('Guzzle\Http\Message\MessageFactoryInterface')
            ->setMethods('createRequest')
            ->getMockForAbstractClass();
        $factory->expects($this->once())
            ->method('createRequest')
            ->will($this->throwException(new \Exception('Foo')));
        $client = new Client(['message_factory' => $factory]);
        $client->get();
    }

    public function testAddsDefaultUserAgentHeaderWithDefaultOptions()
    {
        $client = new Client(['defaults' => ['allow_redirects' => false]]);
        $this->assertFalse($client->getConfig('defaults/allow_redirects'));
        $this->assertEquals(['User-Agent' => Client::getDefaultUserAgent()], $client->getConfig('defaults/headers'));
    }

    public function testAddsDefaultUserAgentHeaderWithoutDefaultOptions()
    {
        $client = new Client();
        $this->assertEquals(['User-Agent' => Client::getDefaultUserAgent()], $client->getConfig('defaults/headers'));
    }

    private function getRequestClient()
    {
        $client = $this->getMockBuilder('Guzzle\Http\Client')
            ->setMethods(['send'])
            ->getMock();
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnArgument(0));

        return $client;
    }

    public function requestMethodProvider()
    {
        return [
            ['GET', false],
            ['HEAD', false],
            ['DELETE', false],
            ['OPTIONS', false],
            ['POST', 'foo'],
            ['PUT', 'foo'],
            ['PATCH', 'foo']
        ];
    }

    /**
     * @dataProvider requestMethodProvider
     */
    public function testClientProvidesMethodShortcut($method, $body)
    {
        $client = $this->getRequestClient();
        if ($body) {
            $request = $client->{$method}('http://foo.com', ['X-Baz' => 'Bar'], $body, ['query' => ['a' => 'b']]);
        } else {
            $request = $client->{$method}('http://foo.com', ['X-Baz' => 'Bar'], ['query' => ['a' => 'b']]);
        }
        $this->assertEquals($method, $request->getMethod());
        $this->assertEquals('Bar', $request->getHeader('X-Baz'));
        $this->assertEquals('a=b', $request->getQuery());
        if ($body) {
            $this->assertEquals($body, $request->getBody());
        }
    }

    public function testClientMergesDefaultOptionsWithRequestOptions()
    {
        $f = $this->getMockBuilder('Guzzle\Http\Message\MessageFactoryInterface')
            ->setMethods(array('createRequest'))
            ->getMockForAbstractClass();

        $o = null;
        // Intercept the creation
        $f->expects($this->once())
            ->method('createRequest')
            ->will($this->returnCallback(
                function ($method, $url, array $headers = [], $body = null, array $options = array()) use (&$o) {
                    $o = $options;
                    return (new MessageFactory())->createRequest($method, $url, $headers, $body, $options);
                }
            ));

        $client = new Client([
            'message_factory' => $f,
            'defaults' => [
                'headers' => ['Foo' => 'Bar'],
                'query' => ['baz' => 'bam'],
                'exceptions' => false
            ]
        ]);

        $request = $client->createRequest('GET', 'http://foo.com?a=b', ['Hi' => 'there'], null, [
            'allow_redirects' => false,
            'query' => ['t' => 1],
            'headers' => ['1' => 'one']
        ]);

        $this->assertFalse($o['allow_redirects']);
        $this->assertFalse($o['exceptions']);
        $this->assertEquals('Bar', $request->getHeader('Foo'));
        $this->assertEquals('there', $request->getHeader('Hi'));
        $this->assertEquals('one', $request->getHeader('1'));
        $this->assertEquals('a=b&baz=bam&t=1', $request->getQuery());
    }

    public function testUsesBaseUrlWhenNoUrlIsSet()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/baz?bam=bar',
            $client->createRequest('GET')->getUrl()
        );
    }

    public function testUsesBaseUrlCombinedWithProvidedUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/bar/bam',
            $client->createRequest('GET', 'bar/bam')->getUrl()
        );
    }

    public function testUsesBaseUrlCombinedWithProvidedUrlViaUriTemplate()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/bar/123',
            $client->createRequest('GET', ['bar/{bam}', ['bam' => '123']])->getUrl()
        );
    }

    public function testSettingAbsoluteUrlOverridesBaseUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/foo',
            $client->createRequest('GET', '/foo')->getUrl()
        );
    }

    public function testClientSendsRequests()
    {
        $response = new Response(200);
        $adapter = new MockAdapter();
        $adapter->setResponse($response);
        $client = new Client(['adapter' => $adapter]);
        $this->assertSame($response, $client->get('http://test.com'));
        $this->assertEquals('http://test.com', $response->getEffectiveUrl());
    }

    public function testSendingRequestCanBeIntercepted()
    {
        $response = new Response(200);
        $response2 = new Response(200);
        $adapter = new MockAdapter();
        $adapter->setResponse($response);
        $client = new Client(['adapter' => $adapter]);
        $client->getEventDispatcher()->addListener(
            RequestEvents::BEFORE_SEND,
            function (RequestBeforeSendEvent $e) use ($response2) {
                $e->intercept($response2);
            }
        );
        $this->assertSame($response2, $client->get('http://test.com'));
        $this->assertEquals('http://test.com', $response2->getEffectiveUrl());
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage No response
     */
    public function testEnsuresResponseIsPresentAfterSending()
    {
        $adapter = $this->getMockBuilder('Guzzle\Http\Adapter\MockAdapter')
            ->setMethods(['send'])
            ->getMock();
        $adapter->expects($this->once())
            ->method('send');
        $client = new Client(['adapter' => $adapter]);
        $client->get('/');
    }

    public function testClientHandlesErrorsDuringBeforeSend()
    {
        $client = new Client();
        $client->getEventDispatcher()->addListener(RequestEvents::BEFORE_SEND, function ($e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $client->getEventDispatcher()->addListener(RequestEvents::ERROR, function ($e) {
            $e->intercept(new Response(200));
        });
        $this->assertEquals(200, $client->get('/')->getStatusCode());
    }

    /**
     * @expectedException \Guzzle\Http\Exception\RequestException
     * @expectedExceptionMessage foo
     */
    public function testClientHandlesErrorsDuringBeforeSendAndThrowsIfUnhandled()
    {
        $client = new Client();
        $client->getEventDispatcher()->addListener(RequestEvents::BEFORE_SEND, function ($e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $client->get('/');
    }

    public function testCanSetConfigValues()
    {
        $client = new Client(['foo' => 'bar']);
        $client->setConfig('foo', 'baz');
        $client->setConfig('defaults/headers/foo', 'bar');
        $this->assertEquals('baz', $client->getConfig('foo'));
        $this->assertEquals('bar', $client->getConfig('defaults/headers/foo'));
    }
}
