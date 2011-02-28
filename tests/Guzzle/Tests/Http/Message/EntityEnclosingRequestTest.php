<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Guzzle;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestException;
use Guzzle\Http\Message\Response;
use Guzzle\Http\QueryString;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class EntityEnclosingRequestTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var RequestFactory
     */
    protected $factory;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        if (!$this->factory) {
            $this->factory = RequestFactory::getInstance();
        }
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::__toString
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFields
     */
    public function testRequestIncludesBodyInMessage()
    {
        $request = $this->factory->newRequest('PUT', 'http://www.guzzle-project.com/', null, 'data');
        $this->assertEquals("PUT / HTTP/1.1\r\nUser-Agent: " . Guzzle::getDefaultUserAgent() . "\r\nHost: www.guzzle-project.com\r\nContent-Length: 4\r\nExpect: 100-Continue\r\n\r\ndata", (string) $request);
        unset($request);

        // Adds POST fields and sets Content-Length
        $request = $this->factory->newRequest('POST', 'http://www.guzzle-project.com/', null, array(
            'data' => '123'
        ));
        $this->assertEquals("POST / HTTP/1.1\r\nUser-Agent: " . Guzzle::getDefaultUserAgent() . "\r\nHost: www.guzzle-project.com\r\nContent-Type: application/x-www-form-urlencoded\r\n\r\ndata=123", (string) $request);
        unset($request);

        $request = $this->factory->newRequest('POST', 'http://www.test.com/');
        $request->addPostFiles(array(
            'file' => __FILE__
        ));
        $request->addPostFields(array(
            'a' => 'b'
        ));

        $message = (string) $request;
        $this->assertEquals('multipart/form-data', $request->getHeader('Content-Type'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::process
     */
    public function testRequestDeterminesContentBeforeSending()
    {
        // Tests using a POST entity body
        $request = $this->factory->newRequest('POST', 'http://www.test.com/');
        $request->addPostFields(array(
            'test' => '123'
        ));
        $this->assertContains("\r\n\r\ntest=123", (string)$request);
        unset($request);

        // Tests using a Content-Length entity body
        $request = $this->factory->newRequest('PUT', 'http://www.test.com/');
        $request->setBody(EntityBody::factory('test'));
        $this->assertNull($request->getHeader('Content-Length'));
        $request->process($request);
        $this->assertEquals(4, $request->getHeader('Content-Length'));
        unset($request);

        // Tests using a Transfer-Encoding chunked entity body already set
        $request = $this->factory->newRequest('PUT', 'http://www.test.com/');
        $request->setBody(EntityBody::factory('test'))
                ->setHeader('Transfer-Encoding', 'chunked');
        $request->process($request);
        $this->assertNull($request->getHeader('Content-Length'));

        // Tests using a Transfer-Encoding chunked entity body with undeterminable size
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nData");
        $request = $this->factory->newRequest('PUT', 'http://www.test.com/');
        $request->setBody(EntityBody::factory(fopen($this->getServer()->getUrl(), 'r')));
        $request->process($request);
        $this->assertNull($request->getHeader('Content-Length'));
        $this->assertEquals('chunked', $request->getHeader('Transfer-Encoding'));

        // Tests making sure HTTP/1.0 has a Content-Length header
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nData");
        $request = $this->factory->newRequest('PUT', 'http://www.test.com/');
        $request->setBody(EntityBody::factory(fopen($this->getServer()->getUrl(), 'r')));
        $request->setProtocolVersion('1.0');
        try {
            $request->process($request);
            $this->fail('Expected exception due to 1.0 and no Content-Length');
        } catch (RequestException $e) {
        }
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setBody
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getBody
     */
    public function testRequestHasMutableBody()
    {
        $request = $this->factory->newRequest('PUT', 'http://www.guzzle-project.com/', null, 'data');
        $body = $request->getBody();
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $body);
        $this->assertEquals($body, $request->getBody());

        $request->setBody(EntityBody::factory('foobar'));
        $this->assertNotEquals($body, $request->getBody());
        $this->assertEquals('foobar', (string) $request->getBody());
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFields
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostFields
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostFiles
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addChain
     */
    public function testSetPostFields()
    {
        $request = $this->factory->newRequest('POST', 'http://www.guzzle-project.com/');
        $this->assertInstanceOf('Guzzle\\Http\\QueryString', $request->getPostFields());

        $fields = new QueryString(array(
            'a' => 'b'
        ));
        $request->addPostFields($fields);
        $this->assertEquals($fields->getAll(), $request->getPostFields()->getAll());
        $this->assertEquals(array(), $request->getPostFiles());
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostFiles
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFiles
     */
    public function testSetPostFiles()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = $this->factory->newRequest('POST', $this->getServer()->getUrl());
        $request->addPostFiles(array(__FILE__));
        $this->assertEquals(array(
            'file' => '@' . __FILE__
        ), $request->getPostFields()->getAll());
        $this->assertEquals(array(
            'file' => __FILE__
        ), $request->getPostFiles());
        $request->send();
        
        $this->assertNotNull($request->getHeader('Content-Length'));
        $this->assertContains('multipart/form-data; boundary=--', $request->getHeader('Content-Type'), '-> cURL must add the boundary');
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFiles
     * @expectedException Guzzle\Http\Message\RequestException
     */
    public function testSetPostFilesThrowsExceptionWhenFileIsNotFound()
    {
        $request = $this->factory->newRequest('POST', 'http://www.guzzle-project.com/');
        $request->addPostFiles(array(
            'file' => 'filenotfound.ini'
        ));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::process
     */
    public function testProcessMethodAddsPostBodyAndEntityBodyHeaders()
    {
        $request = $this->factory->newRequest('POST', 'http://www.guzzle-project.com/');
        $request->getPostFields()->set('a', 'b');
        $this->assertContains("\r\n\r\na=b", (string) $request);
        $this->assertEquals('application/x-www-form-urlencoded', $request->getHeader('Content-Type'));
        unset($request);

        $request = $this->factory->newRequest('POST', 'http://www.guzzle-project.com/');
        $request->getPostFields()->set('a', 'b');
        $request->process($request);
        $this->assertEquals('application/x-www-form-urlencoded', $request->getHeader('Content-Type'));
        $this->assertEquals('a=b', $request->getCurlOptions()->get(CURLOPT_POSTFIELDS));
        unset($request);

        $request = $this->factory->newRequest('POST', 'http://www.guzzle-project.com/');
        $request->addPostFiles(array('file' => __FILE__));
        $request->process($request);
        $this->assertEquals('multipart/form-data', $request->getHeader('Content-Type'));
        $this->assertEquals(array('file' => '@' . __FILE__), $request->getCurlOptions()->get(CURLOPT_POSTFIELDS));
    }
}