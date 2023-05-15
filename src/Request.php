<?php

/**
 * This file is part of Blitz PHP framework - HTTP Client.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\HttpClient;

use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Traits\Conditionable;
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use Closure;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\TransferStats;
use JsonSerializable;
use Kint\Kint;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class Request
{
    use Conditionable;
    use Macroable;

    /**
     * The Guzzle client instance.
     */
    protected ?Client $client = null;

    /**
     * The Guzzle HTTP handler.
     *
     * @var callable
     */
    protected $handler;

    /**
     * The base URL for the request.
     */
    protected string $baseUrl = '';

    /**
     * The request body format.
     */
    protected string $bodyFormat;

    /**
     * The raw body for the request.
     */
    protected string $pendingBody = '';

    /**
     * The pending files for the request.
     */
    protected array $pendingFiles = [];

    /**
     * The request cookies.
     *
     * @var CookieJar
     */
    protected $cookies;

    /**
     * The transfer stats for the request.
     *
     * @var TransferStats
     */
    protected $transferStats;

    /**
     * The request options.
     */
    protected array $options = [];

    /**
     * A callback to run when throwing if a server or client error occurs.
     *
     * @var Closure
     */
    protected $throwCallback;

    /**
     * A callback to check if an exception should be thrown when a server or client error occurs.
     *
     * @var Closure
     */
    protected $throwIfCallback;

    /**
     * The number of times to try the request.
     */
    protected int $tries = 1;

    /**
     * The number of milliseconds to wait between retries.
     */
    protected int $retryDelay = 100;

    /**
     * Whether to throw an exception when all retries fail.
     */
    protected bool $retryThrow = true;

    /**
     * The callback that will determine if the request should be retried.
     *
     * @var callable|null
     */
    protected $retryWhenCallback;

    /**
     * The callbacks that should execute before the request is sent.
     *
     * @var Collection
     */
    protected $beforeSendingCallbacks;

    /**
     * The stub callables that will handle requests.
     *
     * @var Collection|null
     */
    protected $stubCallbacks;

    /**
     * Indicates that an exception should be thrown if any request is not faked.
     */
    protected bool $preventStrayRequests = false;

    /**
     * The middleware callables added by users that will handle requests.
     *
     * @var Collection
     */
    protected $middleware;

    /**
     * Whether the requests should be asynchronous.
     */
    protected bool $async = false;

    /**
     * The pending request promise.
     *
     * @var PromiseInterface
     */
    protected $promise;

    /**
     * The sent request object, if a request has been made.
     *
     * @var RequestManager|null
     */
    protected $request;

    /**
     * The Guzzle request options that are mergable via array_merge_recursive.
     */
    protected array $mergableOptions = [
        'cookies',
        'form_params',
        'headers',
        'json',
        'multipart',
        'query',
    ];

    /**
     * Create a new HTTP Client instance.
     */
    public function __construct(protected ?EventManagerInterface $event = null)
    {
        $this->middleware = new Collection();

        $this->asJson();

        $this->options = [
            'connect_timeout' => 10,
            'http_errors'     => false,
            'timeout'         => 30,
        ];

        $this->beforeSendingCallbacks = Helpers::collect([static function (RequestManager $request, array $options, self $pendingRequest) {
            $pendingRequest->request = $request;
            $pendingRequest->cookies = $options['cookies'];
        }]);
    }

    /**
     * Set the base URL for the pending request.
     */
    public function baseUrl(string $url): self
    {
        $this->baseUrl = $url;

        return $this;
    }

    /**
     * Attach a raw body to the request.
     */
    public function withBody(string $content, string $contentType): self
    {
        $this->bodyFormat('body');

        $this->pendingBody = $content;

        $this->contentType($contentType);

        return $this;
    }

    /**
     * Indicate the request contains JSON.
     */
    public function asJson(): self
    {
        return $this->bodyFormat('json')->contentType('application/json');
    }

    /**
     * Indicate the request contains form parameters.
     */
    public function asForm(): self
    {
        return $this->bodyFormat('form_params')->contentType('application/x-www-form-urlencoded');
    }

    /**
     * Attach a file to the request.
     *
     * @param resource|string $contents
     */
    public function attach(string|array $name, $contents = '', ?string $filename = null, array $headers = []): self
    {
        if (is_array($name)) {
            foreach ($name as $file) {
                $this->attach(...$file);
            }

            return $this;
        }

        $this->asMultipart();

        $this->pendingFiles[] = array_filter([
            'name'     => $name,
            'contents' => $contents,
            'headers'  => $headers,
            'filename' => $filename,
        ]);

        return $this;
    }

    /**
     * Indicate the request is a multi-part form request.
     */
    public function asMultipart(): self
    {
        return $this->bodyFormat('multipart');
    }

    /**
     * Specify the body format of the request.
     */
    public function bodyFormat(string $format): self
    {
        return Helpers::tap($this, function () use ($format) {
            $this->bodyFormat = $format;
        });
    }

    /**
     * Specify the request's content type.
     */
    public function contentType(string $contentType): self
    {
        return $this->withHeaders(['Content-Type' => $contentType]);
    }

    /**
     * Indicate that JSON should be returned by the server.
     */
    public function acceptJson(): self
    {
        return $this->accept('application/json');
    }

    /**
     * Indicate the type of content that should be returned by the server.
     */
    public function accept(string $contentType): self
    {
        return $this->withHeaders(['Accept' => $contentType]);
    }

    /**
     * Add the given headers to the request.
     */
    public function withHeaders(array $headers): self
    {
        return Helpers::tap($this, function () use ($headers) {
            $this->options = array_merge_recursive($this->options, [
                'headers' => $headers,
            ]);
        });
    }

    /**
     * Specify the basic authentication username and password for the request.
     */
    public function withBasicAuth(string $username, string $password): self
    {
        return Helpers::tap($this, function () use ($username, $password) {
            $this->options['auth'] = [$username, $password];
        });
    }

    /**
     * Specify the digest authentication username and password for the request.
     */
    public function withDigestAuth(string $username, string $password): self
    {
        return Helpers::tap($this, function () use ($username, $password) {
            $this->options['auth'] = [$username, $password, 'digest'];
        });
    }

    /**
     * Specify an authorization token for the request.
     */
    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return Helpers::tap($this, function () use ($token, $type) {
            $this->options['headers']['Authorization'] = trim($type . ' ' . $token);
        });
    }

    /**
     * Specify the user agent for the request.
     */
    public function withUserAgent(string $userAgent): self
    {
        return Helpers::tap($this, function () use ($userAgent) {
            $this->options['headers']['User-Agent'] = trim($userAgent);
        });
    }

    /**
     * Specify the cookies that should be included with the request.
     */
    public function withCookies(array $cookies, string $domain): self
    {
        return Helpers::tap($this, function () use ($cookies, $domain) {
            $this->options = array_merge_recursive($this->options, [
                'cookies' => CookieJar::fromArray($cookies, $domain),
            ]);
        });
    }

    /**
     * Specify the maximum number of redirects to allow.
     */
    public function maxRedirects(int $max): self
    {
        return Helpers::tap($this, function () use ($max) {
            $this->options['allow_redirects']['max'] = $max;
        });
    }

    /**
     * Indicate that redirects should not be followed.
     */
    public function withoutRedirecting(): self
    {
        return Helpers::tap($this, function () {
            $this->options['allow_redirects'] = false;
        });
    }

    /**
     * Indicate that TLS certificates should not be verified.
     */
    public function withoutVerifying(): self
    {
        return Helpers::tap($this, function () {
            $this->options['verify'] = false;
        });
    }

    /**
     * Specify the path where the body of the response should be stored.
     *
     * @param resource|string $to
     */
    public function sink($to): self
    {
        return Helpers::tap($this, function () use ($to) {
            $this->options['sink'] = $to;
        });
    }

    /**
     * Specify the timeout (in seconds) for the request.
     */
    public function timeout(int $seconds): self
    {
        return Helpers::tap($this, function () use ($seconds) {
            $this->options['timeout'] = $seconds;
        });
    }

    /**
     * Specify the connect timeout (in seconds) for the request.
     */
    public function connectTimeout(int $seconds): self
    {
        return Helpers::tap($this, function () use ($seconds) {
            $this->options['connect_timeout'] = $seconds;
        });
    }

    /**
     * Specify the number of times the request should be attempted.
     */
    public function retry(int $times, int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true): self
    {
        $this->tries             = $times;
        $this->retryDelay        = $sleepMilliseconds;
        $this->retryThrow        = $throw;
        $this->retryWhenCallback = $when;

        return $this;
    }

    /**
     * Replace the specified options on the request.
     */
    public function withOptions(array $options): self
    {
        return Helpers::tap($this, function () use ($options) {
            $this->options = array_replace_recursive(
                array_merge_recursive($this->options, Arr::only($options, $this->mergableOptions)),
                $options
            );
        });
    }

    /**
     * Add new middleware the client handler stack.
     */
    public function withMiddleware(callable $middleware): self
    {
        $this->middleware->push($middleware);

        return $this;
    }

    /**
     * Add a new "before sending" callback to the request.
     */
    public function beforeSending(callable $callback): self
    {
        return Helpers::tap($this, function () use ($callback) {
            $this->beforeSendingCallbacks[] = $callback;
        });
    }

    /**
     * Throw an exception if a server or client error occurs.
     */
    public function throw(?callable $callback = null): self
    {
        $this->throwCallback = $callback ?: static fn () => null;

        return $this;
    }

    /**
     * Throw an exception if a server or client error occurred and the given condition evaluates to true.
     */
    public function throwIf(callable|bool $condition, ?callable $throwCallback = null): self
    {
        if (is_callable($condition)) {
            $this->throwIfCallback = $condition;
        }

        return $condition ? $this->throw($throwCallback) : $this;
    }

    /**
     * Throw an exception if a server or client error occurred and the given condition evaluates to false.
     */
    public function throwUnless(bool $condition): self
    {
        return $this->throwIf(! $condition);
    }

    /**
     * Dump the request before sending.
     */
    public function dump(): self
    {
        if (! class_exists(Kint::class)) {
            throw new RuntimeException('You must install package kint/kint-php before running the dump of request.');
        }

        $values = func_get_args();

        return $this->beforeSending(static function (RequestManager $request, array $options) use ($values) {
            foreach (array_merge($values, [$request, $options]) as $value) {
                Kint::dump($value);
            }
        });
    }

    /**
     * Dump the request before sending and end the script.
     */
    public function dd(): self
    {
        if (! class_exists(Kint::class)) {
            throw new RuntimeException('You must install package kint/kint-php before running the dump of request.');
        }

        $values = func_get_args();

        return $this->beforeSending(static function (RequestManager $request, array $options) use ($values) {
            foreach (array_merge($values, [$request, $options]) as $value) {
                Kint::dump($value);
            }

            exit(1);
        });
    }

    /**
     * Issue a GET request to the given URL.
     */
    public function get(string $url, array|string|null $query = null): Response
    {
        return $this->send('GET', $url, func_num_args() === 1 ? [] : [
            'query' => $query,
        ]);
    }

    /**
     * Issue a HEAD request to the given URL.
     */
    public function head(string $url, array|string|null $query = null): Response
    {
        return $this->send('HEAD', $url, func_num_args() === 1 ? [] : [
            'query' => $query,
        ]);
    }

    /**
     * Issue a POST request to the given URL.
     */
    public function post(string $url, array $data = []): Response
    {
        return $this->send('POST', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    /**
     * Issue a PATCH request to the given URL.
     */
    public function patch(string $url, array $data = []): Response
    {
        return $this->send('PATCH', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    /**
     * Issue a PUT request to the given URL.
     */
    public function put(string $url, array $data = []): Response
    {
        return $this->send('PUT', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    /**
     * Issue a DELETE request to the given URL.
     */
    public function delete(string $url, array $data = []): Response
    {
        return $this->send('DELETE', $url, empty($data) ? [] : [
            $this->bodyFormat => $data,
        ]);
    }

    /**
     * Send the request to the given URL.
     *
     * @return PromiseInterface|Response
     *
     * @throws Exception
     */
    public function send(string $method, string $url, array $options = [])
    {
        if (! Text::startsWith($url, ['http://', 'https://'])) {
            $url = ltrim(rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/'), '/');
        }

        $options = $this->parseHttpOptions($options);

        [$this->pendingBody, $this->pendingFiles] = ['', []];

        if ($this->async) {
            return $this->makePromise($method, $url, $options);
        }

        $shouldRetry = null;

        return Helpers::retry($this->tries ?? 1, function ($attempt) use ($method, $url, $options, &$shouldRetry) {
            try {
                return Helpers::tap(new Response($this->sendRequest($method, $url, $options)), function (Response $response) use ($attempt, &$shouldRetry) {
                    $this->populateResponse($response);

                    $this->dispatchResponseReceivedEvent($response);

                    if (! $response->successful()) {
                        try {
                            $shouldRetry = $this->retryWhenCallback ? call_user_func($this->retryWhenCallback, $response->toException(), $this) : true;
                        } catch (Exception $exception) {
                            $shouldRetry = false;

                            throw $exception;
                        }

                        if ($this->throwIfCallback === null || ($this->throwIfCallback)($response)) {
                            $response->throw($this->throwCallback);
                        }

                        if ($attempt < $this->tries && $shouldRetry) {
                            $response->throw();
                        }

                        if ($this->tries > 1 && $this->retryThrow) {
                            $response->throw();
                        }
                    }
                });
            } catch (ConnectException $e) {
                $this->dispatchConnectionFailedEvent();

                throw $e;
            }
        }, $this->retryDelay ?? 100, function ($exception) use (&$shouldRetry) {
            $result = $shouldRetry ?? ($this->retryWhenCallback ? call_user_func($this->retryWhenCallback, $exception, $this) : true);

            $shouldRetry = null;

            return $result;
        });
    }

    /**
     * Parse the given HTTP options and set the appropriate additional options.
     */
    protected function parseHttpOptions(array $options): array
    {
        if (isset($options[$this->bodyFormat])) {
            if ($this->bodyFormat === 'multipart') {
                $options[$this->bodyFormat] = $this->parseMultipartBodyFormat($options[$this->bodyFormat]);
            } elseif ($this->bodyFormat === 'body') {
                $options[$this->bodyFormat] = $this->pendingBody;
            }

            if (is_array($options[$this->bodyFormat])) {
                $options[$this->bodyFormat] = array_merge(
                    $options[$this->bodyFormat],
                    $this->pendingFiles
                );
            }
        } else {
            $options[$this->bodyFormat] = $this->pendingBody;
        }

        return Helpers::collect($options)->map(static function ($value, $key) {
            if ($key === 'json' && $value instanceof JsonSerializable) {
                return $value;
            }

            return $value instanceof Arrayable ? $value->toArray() : $value;
        })->all();
    }

    /**
     * Parse multi-part form data.
     *
     * @return array|array[]
     */
    protected function parseMultipartBodyFormat(array $data): array
    {
        return Helpers::collect($data)->map(static fn ($value, $key) => is_array($value) ? $value : ['name' => $key, 'contents' => $value])->values()->all();
    }

    /**
     * Send an asynchronous request to the given URL.
     */
    protected function makePromise(string $method, string $url, array $options = []): PromiseInterface
    {
        return $this->promise = $this->sendRequest($method, $url, $options)->then(function (MessageInterface $message) {
            return Helpers::tap(new Response($message), function ($response) {
                $this->populateResponse($response);
                $this->dispatchResponseReceivedEvent($response);
            });
        })->otherwise(fn (TransferException $e) => $e instanceof RequestException && $e->hasResponse() ? $this->populateResponse(new Response($e->getResponse())) : $e);
    }

    /**
     * Send a request either synchronously or asynchronously.
     *
     * @return MessageInterface|PromiseInterface
     *
     * @throws Exception
     */
    protected function sendRequest(string $method, string $url, array $options = [])
    {
        $clientMethod = $this->async ? 'requestAsync' : 'request';

        $data = $this->parseRequestData($method, $url, $options);

        return $this->buildClient()->{$clientMethod}($method, $url, $this->mergeOptions([
            'blitz_data' => $data,
            'on_stats'   => function ($transferStats) {
                $this->transferStats = $transferStats;
            },
        ], $options));
    }

    /**
     * Get the request data as an array so that we can attach it to the request for convenient assertions.
     */
    protected function parseRequestData(string $method, string $url, array $options): array
    {
        if ($this->bodyFormat === 'body') {
            return [];
        }

        $data = $options[$this->bodyFormat] ?? $options['query'] ?? [];

        $urlString = Text::of($url);

        if (empty($data) && $method === 'GET' && $urlString->contains('?')) {
            $data = (string) $urlString->after('?');
        }

        if (is_string($data)) {
            parse_str($data, $parsedData);

            $data = $parsedData;
        }

        if ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Populate the given response with additional data.
     */
    protected function populateResponse(Response $response): Response
    {
        $response->cookies = $this->cookies;

        $response->transferStats = $this->transferStats;

        return $response;
    }

    /**
     * Build the Guzzle client.
     */
    public function buildClient(): Client
    {
        return $this->client ?? $this->createClient($this->buildHandlerStack());
    }

    /**
     * Determine if a reusable client is required.
     */
    protected function requestsReusableClient(): bool
    {
        return null !== $this->client || $this->async;
    }

    /**
     * Retrieve a reusable Guzzle client.
     */
    protected function getReusableClient(): Client
    {
        return $this->client = $this->client ?: $this->createClient($this->buildHandlerStack());
    }

    /**
     * Create new Guzzle client.
     */
    public function createClient(HandlerStack $handlerStack): Client
    {
        return new Client([
            'handler' => $handlerStack,
            'cookies' => true,
        ]);
    }

    /**
     * Build the Guzzle client handler stack.
     */
    public function buildHandlerStack(): HandlerStack
    {
        return $this->pushHandlers(HandlerStack::create($this->handler));
    }

    /**
     * Add the necessary handlers to the given handler stack.
     */
    public function pushHandlers(HandlerStack $handlerStack): HandlerStack
    {
        return Helpers::tap($handlerStack, function (HandlerStack $stack) {
            $stack->push($this->buildBeforeSendingHandler());
            $stack->push($this->buildRecorderHandler());

            $this->middleware->each(static function ($middleware) use ($stack) {
                $stack->push($middleware);
            });

            $stack->push($this->buildStubHandler());
        });
    }

    /**
     * Build the before sending handler.
     */
    public function buildBeforeSendingHandler(): Closure
    {
        return fn (callable $handler) => fn (RequestInterface $request, array $options) => $handler($this->runBeforeSendingCallbacks($request, $options), $options);
    }

    /**
     * Build the recorder handler.
     */
    public function buildRecorderHandler(): Closure
    {
        return static function ($handler) {
            return static function ($request, $options) use ($handler) {
                $promise = $handler($request, $options);

                return $promise->then(static fn ($response) => $response);
            };
        };
    }

    /**
     * Build the stub handler.
     */
    public function buildStubHandler(): Closure
    {
        return function ($handler) {
            return function ($request, $options) use ($handler) {
                $response = ($this->stubCallbacks ?? Helpers::collect())
                    ->map
                    ->__invoke((new RequestManager($request))->withData($options['blitz_data']), $options)
                    ->filter()
                    ->first();

                if (null === $response) {
                    if ($this->preventStrayRequests) {
                        throw new RuntimeException('Attempted request to [' . (string) $request->getUri() . '] without a matching fake.');
                    }

                    return $handler($request, $options);
                }

                if (is_array($response)) {
                    $response = new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode($response));

                    $response = \GuzzleHttp\Promise\Create::promiseFor($response);
                }

                $sink = $options['sink'] ?? null;

                if ($sink) {
                    $response->then($this->sinkStubHandler($sink));
                }

                return $response;
            };
        };
    }

    /**
     * Get the sink stub handler callback.
     */
    protected function sinkStubHandler(string $sink): Closure
    {
        return static function ($response) use ($sink) {
            $body = $response->getBody()->getContents();

            if (is_string($sink)) {
                file_put_contents($sink, $body);

                return;
            }

            fwrite($sink, $body);
            rewind($sink);
        };
    }

    /**
     * Execute the "before sending" callbacks.
     */
    public function runBeforeSendingCallbacks(RequestInterface $request, array $options): RequestInterface
    {
        return Helpers::tap($request, function (RequestInterface &$request) use ($options) {
            $this->beforeSendingCallbacks->each(function ($callback) use (&$request, $options) {
                $callbackResult = $callback(
                    (new RequestManager($request))->withData($options['blitz_data']),
                    $options,
                    $this
                );

                if ($callbackResult instanceof RequestInterface) {
                    $request = $callbackResult;
                } elseif ($callbackResult instanceof RequestManager) {
                    $request = $callbackResult->toPsrRequest();
                }
            });
        });
    }

    /**
     * Replace the given options with the current request options.
     *
     * @param array ...$options
     */
    public function mergeOptions(...$options): array
    {
        return array_replace_recursive(
            array_merge_recursive($this->options, Arr::only($options, $this->mergableOptions)),
            ...$options
        );
    }

    /**
     * Register a stub callable that will intercept requests and be able to return stub responses.
     */
    public function stub(callable $callback): self
    {
        $this->stubCallbacks = Helpers::collect($callback);

        return $this;
    }

    /**
     * Indicate that an exception should be thrown if any request is not faked.
     */
    public function preventStrayRequests(bool $prevent = true): self
    {
        $this->preventStrayRequests = $prevent;

        return $this;
    }

    /**
     * Toggle asynchronicity in requests.
     */
    public function async(bool $async = true): self
    {
        $this->async = $async;

        return $this;
    }

    /**
     * Retrieve the pending request promise.
     */
    public function getPromise(): ?PromiseInterface
    {
        return $this->promise;
    }

    /**
     * Dispatch the RequestSending event if a dispatcher is available.
     */
    protected function dispatchRequestSendingEvent(): void
    {
        if ($this->event) {
            $this->event->trigger('http-client.request::sending', static::class, [$this->request]);
        }
    }

    /**
     * Dispatch the ResponseReceived event if a dispatcher is available.
     */
    protected function dispatchResponseReceivedEvent(Response $response): void
    {
        if ($this->event && $this->request) {
            $this->event->trigger('http-client.response::receive', static::class, [$this->request, $response]);
        }
    }

    /**
     * Dispatch the ConnectionFailed event if a dispatcher is available.
     */
    protected function dispatchConnectionFailedEvent(): void
    {
        if ($this->event && $this->request) {
            $this->event->trigger('http-client.request::connectionFailled', static::class, [$this->request]);
        }
    }

    /**
     * Set the client instance.
     */
    public function setClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Create a new client instance using the given handler.
     */
    public function setHandler(callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Get the pending request options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
