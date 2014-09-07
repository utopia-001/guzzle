<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\TransactionIterator;

class TransactionIteratorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesConstructor()
    {
        new TransactionIterator('foo', new Client(), []);
    }

    public function testCreatesTransactions()
    {
        $client = new Client();
        $requests = [
            $client->createRequest('GET', 'http://test.com'),
            $client->createRequest('POST', 'http://test.com'),
            $client->createRequest('PUT', 'http://test.com'),
        ];
        $t = new TransactionIterator($requests, $client, []);
        $this->assertEquals(0, $t->key());
        $this->assertTrue($t->valid());
        $this->assertEquals('GET', $t->current()->request->getMethod());
        $t->next();
        $this->assertEquals(1, $t->key());
        $this->assertTrue($t->valid());
        $this->assertEquals('POST', $t->current()->request->getMethod());
        $t->next();
        $this->assertEquals(2, $t->key());
        $this->assertTrue($t->valid());
        $this->assertEquals('PUT', $t->current()->request->getMethod());
    }

    public function testCanForeach()
    {
        $c = new Client();
        $requests = [
            $c->createRequest('GET', 'http://test.com'),
            $c->createRequest('POST', 'http://test.com'),
            $c->createRequest('PUT', 'http://test.com'),
        ];

        $t = new TransactionIterator(new \ArrayIterator($requests), $c, []);
        $methods = [];

        foreach ($t as $trans) {
            $this->assertInstanceOf('GuzzleHttp\Transaction', $trans);
            $methods[] = $trans->request->getMethod();
        }

        $this->assertEquals(['GET', 'POST', 'PUT'], $methods);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testValidatesEachElement()
    {
        $c = new Client();
        $requests = ['foo'];
        $t = new TransactionIterator(new \ArrayIterator($requests), $c, []);
        iterator_to_array($t);
    }
}
