<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Mock;

/**
 * A mock command class to test that commands sent through
 * \Guzzle\Common\Filter\Chain::process() are successfully modified
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockFilterCommand
{
    public $value = '';
}