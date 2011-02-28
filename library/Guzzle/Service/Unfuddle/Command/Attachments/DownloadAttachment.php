<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Attachments;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Download an Unfuddle attachment
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle type required="true" doc="Type of attachment (messages, tickets, tickets_comments, messages_comment, notebooks)"
 * @guzzle type_id required="true" doc="ID of the type"
 * @guzzle id required="true" doc="ID of the attachment to download"
 */
class DownloadAttachment extends AbstractAttachmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        parent::build();
        $this->request->getQuery()->set('download', false);
    }
}