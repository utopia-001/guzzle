<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\HeadersEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\Stream;

/**
 * HTTP adapter that uses PHP's HTTP stream wrapper
 */
class StreamAdapter implements AdapterInterface
{
    /** @var MessageFactoryInterface */
    private $messageFactory;

    /**
     * @param MessageFactoryInterface $messageFactory
     */
    public function __construct(MessageFactoryInterface $messageFactory)
    {
        $this->messageFactory = $messageFactory;
    }

    public function send(TransactionInterface $transaction)
    {
        // HTTP/1.1 streams using the PHP stream wrapper require a
        // Connection: close header. Setting here so that it is added before
        // emitting the request.before_send event.
        $request = $transaction->getRequest();
        if ($request->getProtocolVersion() == '1.1' &&
            !$request->hasHeader('Connection')
        ) {
            $transaction->getRequest()->setHeader('Connection', 'close');
        }

        RequestEvents::emitBefore($transaction);
        if (!$transaction->getResponse()) {
            $this->createResponse($transaction);
            RequestEvents::emitComplete($transaction);
        }

        return $transaction->getResponse();
    }

    /**
     * @throws \LogicException if you attempt to stream and specify a write_to option
     */
    private function createResponse(TransactionInterface $transaction)
    {
        $request = $transaction->getRequest();
        $stream = $this->createStream($request, $http_response_header);

        if (!$request->getConfig()['stream']) {
            $stream = $this->getSaveToBody($request, $stream);
        }

        // Track the response headers of the request
        $this->createResponseObject($http_response_header, $transaction, $stream);
    }

    /**
     * Drain the steam into the destination stream
     */
    private function getSaveToBody(RequestInterface $request, $stream)
    {
        if ($saveTo = $request->getConfig()['save_to']) {
            // Stream the response into the destination stream
            $saveTo = is_string($saveTo)
                ? Stream::factory(fopen($saveTo, 'r+'))
                : Stream::factory($saveTo);
        } else {
            // Stream into the default temp stream
            $saveTo = Stream::factory();
        }

        while (!feof($stream)) {
            $saveTo->write(fread($stream, 8096));
        }

        fclose($stream);
        $saveTo->seek(0);

        return $saveTo;
    }

    private function createResponseObject(array $headers, TransactionInterface $transaction, $stream)
    {
        $parts = explode(' ', array_shift($headers), 3);
        $options = ['protocol_version' => substr($parts[0], -3)];
        if (isset($parts[2])) {
            $options['reason_phrase'] = $parts[2];
        }

        // Set the size on the stream if it was returned in the response
        $responseHeaders = [];
        foreach ($headers as $header) {
            $headerParts = explode(':', $header, 2);
            $responseHeaders[$headerParts[0]] = isset($headerParts[1]) ? $headerParts[1] : '';
        }

        $response = $this->messageFactory->createResponse($parts[1], $responseHeaders, $stream, $options);
        $transaction->setResponse($response);

        $transaction->getRequest()->getEmitter()->emit(
            'headers',
            new HeadersEvent($transaction)
        );

        return $response;
    }

    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable         $callback Callable to invoke that must return a valid resource
     * @param RequestInterface $request  Request used when throwing exceptions
     * @param array            $options  Options used when throwing exceptions
     *
     * @return resource
     * @throws RequestException on error
     */
    private function createResource(callable $callback, RequestInterface $request, $options)
    {
        // Turn off error reporting while we try to initiate the request
        $level = error_reporting(0);
        $resource = call_user_func($callback);
        error_reporting($level);

        // If the resource could not be created, then grab the last error and throw an exception
        if (!is_resource($resource)) {
            $message = 'Error creating resource. [url] ' . $request->getUrl() . ' ';
            if (isset($options['http']['proxy'])) {
                $message .= "[proxy] {$options['http']['proxy']} ";
            }
            foreach (error_get_last() as $key => $value) {
                $message .= "[{$key}] {$value} ";
            }
            throw new RequestException(trim($message), $request);
        }

        return $resource;
    }

    /**
     * Create the stream for the request with the context options.
     *
     * @param RequestInterface $request              Request being sent
     * @param mixed            $http_response_header Value is populated by stream wrapper
     *
     * @return resource
     */
    private function createStream(RequestInterface $request, &$http_response_header)
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        $headers = '';
        foreach ($request->getHeaders() as $name => $values) {
            $headers .= $name . ': ' . implode(', ', $values) . "\r\n";
        }

        $params = [];
        $options = ['http' => [
            'method' => $request->getMethod(),
            'header' => trim($headers),
            'protocol_version' => $request->getProtocolVersion(),
            'ignore_errors' => true,
            'follow_location' => 0,
            'content' => (string) $request->getBody()
        ]];

        foreach ($request->getConfig()->toArray() as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $options, $value, $params);
            }
        }

        $context = $this->createResource(function () use ($request, $options, $params) {
            return stream_context_create($options, $params);
        }, $request, $options);

        $url = $request->getUrl();
        // Add automatic gzip decompression
        if (strpos($request->getHeader('Accept-Encoding'), 'gzip') !== false) {
            $url = 'compress.zlib://' . $url;
        }

        return $this->createResource(function () use ($url, &$http_response_header, $context) {
            if (false === strpos($url, 'http')) {
                trigger_error("URL is invalid: {$url}", E_USER_WARNING);
                return null;
            }
            return fopen($url, 'r', null, $context);
        }, $request, $options);
    }

    private function visit_proxy(RequestInterface $request, &$options, $value, &$params)
    {
        if (!is_array($value)) {
            $options['http']['proxy'] = $value;
        } else {
            $scheme = $request->getScheme();
            if (isset($value[$scheme])) {
                $options['http']['proxy'] = $value[$scheme];
            }
        }
    }

    private function visit_timeout(RequestInterface $request, &$options, $value, &$params)
    {
        $options['http']['timeout'] = $value;
    }

    private function visit_verify(RequestInterface $request, &$options, $value, &$params)
    {
        if ($value === true || is_string($value)) {
            $options['http']['verify_peer'] = true;
            if ($value !== true) {
                if (!file_exists($value)) {
                    throw new \RuntimeException("SSL certificate authority file not found: {$value}");
                }
                $options['http']['allow_self_signed'] = true;
                $options['http']['cafile'] = $value;
            }
        } elseif ($value === false) {
            $options['http']['verify_peer'] = false;
        }
    }

    private function visit_cert(RequestInterface $request, &$options, $value, &$params)
    {
        if (is_array($value)) {
            $options['http']['passphrase'] = $value[1];
            $value = $value[0];
        }

        if (!file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }

        $options['http']['local_cert'] = $value;
    }

    private function visit_debug(RequestInterface $request, &$options, $value, &$params)
    {
        static $map = [
            STREAM_NOTIFY_CONNECT       => 'CONNECT',
            STREAM_NOTIFY_AUTH_REQUIRED => 'AUTH_REQUIRED',
            STREAM_NOTIFY_AUTH_RESULT   => 'AUTH_RESULT',
            STREAM_NOTIFY_MIME_TYPE_IS  => 'MIME_TYPE_IS',
            STREAM_NOTIFY_FILE_SIZE_IS  => 'FILE_SIZE_IS',
            STREAM_NOTIFY_REDIRECTED    => 'REDIRECTED',
            STREAM_NOTIFY_PROGRESS      => 'PROGRESS',
            STREAM_NOTIFY_FAILURE       => 'FAILURE',
            STREAM_NOTIFY_COMPLETED     => 'COMPLETED',
            STREAM_NOTIFY_RESOLVE       => 'RESOLVE'
        ];

        static $args = ['severity', 'message', 'message_code',
            'bytes_transferred', 'bytes_max'];

        if (!is_resource($value)) {
            $value = fopen('php://output', 'w');
        }

        $params['notification'] = function () use ($request, $value, $map, $args) {
            $passed = func_get_args();
            $code = array_shift($passed);
            fprintf($value, '<%s> [%s] ', $request->getUrl(), $map[$code]);
            foreach (array_filter($passed) as $i => $v) {
                fwrite($value, $args[$i] . ': "' . $v . '" ');
            }
            fwrite($value, "\n");
        };
    }
}
