<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Event\BeforeEvent;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Event\RequestEvents;

/**
 * @covers Guzzle\Http\Event\BeforeEvent
 */
class BeforeEventTest extends \PHPUnit_Framework_TestCase
{
    public function testInterceptsWithEvent()
    {
        $response = new Response(200);
        $res = null;
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->getRequest()->getEmitter()->on(RequestEvents::COMPLETE, function ($e) use (&$res) {
            $res = $e;
        });
        $e = new BeforeEvent($t);
        $e->intercept($response);
        $this->assertTrue($e->isPropagationStopped());
        $this->assertSame($res->getClient(), $e->getClient());
    }
}
