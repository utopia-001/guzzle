<?php

namespace Guzzle\Tests\Service;

use Guzzle\Service\ResourceIterator;
use Guzzle\Tests\Service\Mock\MockResourceIterator;

/**
 * @group server
 */
class ResourceIteratorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\ResourceIterator::getAllEvents
     */
    public function testDescribesEvents()
    {
        $this->assertInternalType('array', ResourceIterator::getAllEvents());
    }

    /**
      * @covers Guzzle\Service\ResourceIterator
     */
    public function testConstructorConfiguresDefaults()
    {
        $ri = $this->getMockForAbstractClass('Guzzle\\Service\\ResourceIterator', array(
            $this->getServiceBuilder()->get('mock')->getCommand('iterable_command'),
            array(
                'limit' => 10,
                'page_size' => 3
            )
        ), 'MockIterator');

        $this->assertEquals(false, $ri->getNextToken());
        $this->assertEquals(false, $ri->current());
    }

    /**
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testSendsRequestsForNextSetOfResources()
    {
        // Queue up an array of responses for iterating
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\", \"i\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 41\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"j\"] }"
        ));

        // Create a new resource iterator using the IteraableCommand mock
        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock')->getCommand('iterable_command'), array(
            'page_size' => 3
        ));

        // Ensure that no requests have been sent yet
        $this->assertEquals(0, count($this->getServer()->getReceivedRequests(false)));

        //$this->assertEquals(array('d', 'e', 'f', 'g', 'h', 'i', 'j'), $ri->toArray());
        $ri->toArray();
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(3, count($requests));

        $this->assertEquals(3, $requests[0]->getQuery()->get('page_size'));
        $this->assertEquals(3, $requests[1]->getQuery()->get('page_size'));
        $this->assertEquals(3, $requests[2]->getQuery()->get('page_size'));

        // Reset and resend
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\", \"i\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 41\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"j\"] }",
        ));

        $d = array();
        reset($ri);
        foreach ($ri as $data) {
            $d[] = $data;
        }
        $this->assertEquals(array('d', 'e', 'f', 'g', 'h', 'i', 'j'), $d);
    }

    /**
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testCalculatesPageSize()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\", \"i\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"j\", \"k\"] }"
        ));

        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock')->getCommand('iterable_command'), array(
            'page_size' => 3,
            'limit' => 7
        ));

        $this->assertEquals(array('d', 'e', 'f', 'g', 'h', 'i', 'j'), $ri->toArray());
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(3, count($requests));
        $this->assertEquals(3, $requests[0]->getQuery()->get('page_size'));
        $this->assertEquals(3, $requests[1]->getQuery()->get('page_size'));
        $this->assertEquals(1, $requests[2]->getQuery()->get('page_size'));
    }

    /**
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testUseAsArray()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"g\", \"h\", \"i\"] }"
        ));

        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock')->getCommand('iterable_command'));

        // Ensure that the key is never < 0
        $this->assertEquals(0, $ri->key());
        $this->assertEquals(0, count($ri));

        // Ensure that the iterator can be used as KVP array
        $data = array();
        foreach ($ri as $key => $value) {
            $data[$key] = $value;
        }

        // Ensure that the iterate is countable
        $this->assertEquals(6, count($ri));
        $this->assertEquals(array('d', 'e', 'f', 'g', 'h', 'i'), $data);
    }

    /**
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testBailsWhenSendReturnsNoResults()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\n\r\n{ \"next_token\": \"\", \"resources\": [] }"
        ));

        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock')->getCommand('iterable_command'));

        // Ensure that the iterator can be used as KVP array
        $data = $ri->toArray();

        // Ensure that the iterate is countable
        $this->assertEquals(3, count($ri));
        $this->assertEquals(array('d', 'e', 'f'), $data);
    }

    /**
     * @covers Guzzle\Service\ResourceIterator::set
     * @covers Guzzle\Service\ResourceIterator::get
     */
    public function testHoldsDataOptions()
    {
        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock')->getCommand('iterable_command'));
        $this->assertNull($ri->get('foo'));
        $this->assertSame($ri, $ri->set('foo', 'bar'));
        $this->assertEquals('bar', $ri->get('foo'));
    }

    /**
     * @covers Guzzle\Service\ResourceIterator::setLimit
     * @covers Guzzle\Service\ResourceIterator::setPageSize
     */
    public function testSettingLimitOrPageSizeClearsData()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"d\", \"e\", \"f\"] }"
        ));

        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock')->getCommand('iterable_command'));
        $ri->toArray();
        $this->assertNotEmpty($this->readAttribute($ri, 'resources'));

        $ri->setLimit(10);
        $this->assertEmpty($this->readAttribute($ri, 'resources'));

        $ri->toArray();
        $this->assertNotEmpty($this->readAttribute($ri, 'resources'));
        $ri->setPageSize(10);
        $this->assertEmpty($this->readAttribute($ri, 'resources'));
    }
}
