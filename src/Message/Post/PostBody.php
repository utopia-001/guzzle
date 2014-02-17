<?php

namespace GuzzleHttp\Message\Post;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\QueryAggregator\PhpAggregator;
use GuzzleHttp\QueryAggregator\QueryAggregatorInterface;
use GuzzleHttp\Query;

/**
 * Holds POST fields and files and creates a streaming body when read methods are called on the object.
 */
class PostBody implements PostBodyInterface
{
    /** @var StreamInterface */
    private $body;

    /** @var QueryAggregatorInterface */
    private $aggregator;

    private $fields = [];
    private $files = [];
    private $forceMultipart = false;

    /**
     * Applies request headers to a request based on the POST state
     *
     * @param RequestInterface $request Request to update
     */
    public function applyRequestHeaders(RequestInterface $request)
    {
        if ($this->files || $this->forceMultipart) {
            $request->setHeader('Content-Type', 'multipart/form-data; boundary=' . $this->getBody()->getBoundary());
        } elseif ($this->fields) {
            $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        if ($size = $this->getSize()) {
            $request->setHeader('Content-Length', $size);
        }
    }

    /**
     * Set to true to force a multipart upload even if there are no files
     *
     * @param bool $force Set to true to force multipart uploads or false to remove this flag
     *
     * @return self
     */
    public function forceMultipartUpload($force)
    {
        $this->forceMultipart = $force;

        return $this;
    }

    /**
     * Set the aggregation strategy that will be used to turn multi-valued fields into a string
     *
     * @param QueryAggregatorInterface $aggregator
     */
    final public function setAggregator(QueryAggregatorInterface $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    public function setField($name, $value)
    {
        $this->fields[$name] = $value;
        $this->mutate();

        return $this;
    }

    public function replaceFields(array $fields)
    {
        $this->fields = $fields;
        $this->mutate();

        return $this;
    }

    public function getField($name)
    {
        return isset($this->fields[$name]) ? $this->fields[$name] : null;
    }

    public function removeField($name)
    {
        unset($this->fields[$name]);
        $this->mutate();

        return $this;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function hasField($name)
    {
        return isset($this->fields[$name]);
    }

    public function getFiles()
    {
        return $this->files;
    }

    public function addFile(PostFileInterface $file)
    {
        $this->files[] = $file;
        $this->mutate();

        return $this;
    }

    public function clearFiles()
    {
        $this->files = [];
        $this->mutate();

        return $this;
    }

    /**
     * Returns the numbers of fields + files
     *
     * @return int
     */
    public function count()
    {
        return count($this->files) + count($this->fields);
    }

    public function __toString()
    {
        return (string) $this->getBody();
    }

    public function getContents($maxLength = -1)
    {
        return $this->getBody()->getContents();
    }

    public function close()
    {
        return $this->body ? $this->body->close() : true;
    }

    public function detach()
    {
        $this->body = null;
        $this->fields = $this->files = [];

        return $this;
    }

    public function eof()
    {
        return $this->getBody()->eof();
    }

    public function tell()
    {
        return $this->body ? $this->body->tell() : 0;
    }

    public function isSeekable()
    {
        return true;
    }

    public function isReadable()
    {
        return true;
    }

    public function isWritable()
    {
        return false;
    }

    public function getSize()
    {
        return $this->getBody()->getSize();
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->getBody()->seek($offset, $whence);
    }

    public function read($length)
    {
        return $this->getBody()->read($length);
    }

    public function write($string)
    {
        return false;
    }

    /**
     * Return a stream object that is built from the POST fields and files. If one has already been
     * created, the previously created stream will be returned.
     */
    protected function getBody()
    {
        if ($this->body) {
            return $this->body;
        } elseif ($this->files || $this->forceMultipart) {
            return $this->body = $this->createMultipart();
        } elseif ($this->fields) {
            return $this->body = $this->createUrlEncoded();
        } else {
            return $this->body = Stream::factory();
        }
    }

    /**
     * Get the aggregator used to join multi-valued field parameters
     *
     * @return QueryAggregatorInterface
     */
    final protected function getAggregator()
    {
        if (!$this->aggregator) {
            $this->aggregator = new PhpAggregator();
        }

        return $this->aggregator;
    }

    /**
     * Creates a multipart/form-data body stream
     *
     * @return MultipartBody
     */
    private function createMultipart()
    {
        $fields = $this->fields;
        $query = (new Query())
            ->setEncodingType(false)
            ->setAggregator($this->getAggregator());

        // Account for fields with an array value
        foreach ($fields as $name => &$field) {
            if (is_array($field)) {
                $field = (string) $query->replace([$name => $field]);
            }
        }

        return new MultipartBody($fields, $this->files);
    }

    /**
     * Creates an application/x-www-form-urlencoded stream body
     *
     * @return StreamInterface
     */
    private function createUrlEncoded()
    {
        $query = (new Query($this->fields))
            ->setAggregator($this->getAggregator())
            ->setEncodingType(Query::RFC1738);

        return Stream::factory($query);
    }

    /**
     * Get rid of any cached data
     */
    private function mutate()
    {
        $this->body = null;
    }
}
