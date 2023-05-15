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

use ArrayAccess;
use BlitzPHP\Traits\Http\DeterminesStatusCode;
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use Closure;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class Response implements ArrayAccess
{
    use DeterminesStatusCode, Macroable {
        __call as macroCall;
    }

    /**
     * The decoded JSON response.
     */
    protected array $decoded = [];

    /**
     * The request cookies.
     *
     * @var \GuzzleHttp\Cookie\CookieJar
     */
    public $cookies;

    /**
     * The transfer stats for the request.
     *
     * @var \GuzzleHttp\TransferStats|null
     */
    public $transferStats;

    /**
     * Create a new response instance.
     *
     * @param ResponseInterface $response The underlying PSR response.
     */
    public function __construct(protected ResponseInterface $response)
    {
    }

    /**
     * Get the body of the response.
     */
    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * Get the JSON decoded body of the response as an array or scalar value.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ([] === $this->decoded) {
            $this->decoded = json_decode($this->body(), true);
        }

        if (null === $key) {
            return $this->decoded;
        }

        return Arr::dataGet($this->decoded, $key, $default);
    }

    /**
     * Get the JSON decoded body of the response as an object.
     */
    public function object(): object
    {
        return json_decode($this->body(), false);
    }

    /**
     * Get the JSON decoded body of the response as a collection.
     */
    public function collect(?string $key = null): Collection
    {
        return Collection::make($this->json($key));
    }

    /**
     * Get a header from the response.
     */
    public function header(string $header): string
    {
        return $this->response->getHeaderLine($header);
    }

    /**
     * Get the headers from the response.
     */
    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    /**
     * Get the status code of the response.
     */
    public function status(): int
    {
        return (int) $this->response->getStatusCode();
    }

    /**
     * Get the reason phrase of the response.
     */
    public function reason(): string
    {
        return $this->response->getReasonPhrase();
    }

    /**
     * Get the effective URI of the response.
     */
    public function effectiveUri(): ?UriInterface
    {
        return $this->transferStats?->getEffectiveUri();
    }

    /**
     * Determine if the request was successful.
     */
    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    /**
     * Determine if the response was a redirect.
     */
    public function redirect(): bool
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    /**
     * Determine if the response indicates a client or server error occurred.
     */
    public function failed(): bool
    {
        return $this->serverError() || $this->clientError();
    }

    /**
     * Determine if the response indicates a client error occurred.
     */
    public function clientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    /**
     * Determine if the response indicates a server error occurred.
     */
    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    /**
     * Execute the given callback if there was a server or client error.
     */
    public function onError(callable $callback): self
    {
        if ($this->failed()) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Get the response cookies.
     *
     * @return \GuzzleHttp\Cookie\CookieJar
     */
    public function cookies()
    {
        return $this->cookies;
    }

    /**
     * Get the handler stats of the response.
     */
    public function handlerStats(): array
    {
        return $this->transferStats?->getHandlerStats() ?? [];
    }

    /**
     * Close the stream and any underlying resources.
     */
    public function close(): self
    {
        $this->response->getBody()->close();

        return $this;
    }

    /**
     * Get the underlying PSR response for the response.
     */
    public function toPsrResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Create an exception if a server or client error occurred.
     *
     * @return RequestException|null
     */
    public function toException()
    {
        if ($this->failed()) {
            return new RequestException($this);
        }

        return null;
    }

    /**
     * Throw an exception if a server or client error occurred.
     *
     * @throws RequestException
     */
    public function throw(?Closure $callback = null): self
    {
        if ($this->failed()) {
            throw Helpers::tap($this->toException(), function ($exception) use ($callback) {
                if ($callback && is_callable($callback)) {
                    $callback($this, $exception);
                }
            });
        }

        return $this;
    }

    /**
     * Throw an exception if a server or client error occurred and the given condition evaluates to true.
     *
     * @throws RequestException
     */
    public function throwIf(Closure|bool $condition, ?Closure $throwCallback = null): self
    {
        return Helpers::value($condition, $this) ? $this->throw($throwCallback) : $this;
    }

    /**
     * Throw an exception if the response status code matches the given code.
     *
     * @throws RequestException
     */
    public function throwIfStatus(callable|int $statusCode): self
    {
        if (is_callable($statusCode)
            && $statusCode($this->status(), $this)) {
            return $this->throw();
        }

        return $this->status() === $statusCode ? $this->throw() : $this;
    }

    /**
     * Throw an exception unless the response status code matches the given code.
     *
     * @throws RequestException
     */
    public function throwUnlessStatus(callable|int $statusCode): self
    {
        if (is_callable($statusCode)
            && ! $statusCode($this->status(), $this)) {
            return $this->throw();
        }

        return $this->status() === $statusCode ? $this : $this->throw();
    }

    /**
     * Throw an exception if the response status code is a 4xx level code.
     *
     * @throws RequestException
     */
    public function throwIfClientError(): self
    {
        return $this->clientError() ? $this->throw() : $this;
    }

    /**
     * Throw an exception if the response status code is a 5xx level code.
     *
     * @throws RequestException
     */
    public function throwIfServerError(): self
    {
        return $this->serverError() ? $this->throw() : $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->json()[$offset]);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $offset
     */
    public function offsetGet($offset): mixed
    {
        return $this->json()[$offset];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $offset
     *
     * @throws LogicException
     */
    public function offsetSet($offset, $value): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * {@inheritDoc}
     *
     * @param string $offset
     *
     * @throws LogicException
     */
    public function offsetUnset($offset): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * Get the body of the response.
     */
    public function __toString(): string
    {
        return $this->body();
    }

    /**
     * Dynamically proxy other methods to the underlying response.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return static::hasMacro($method)
                    ? $this->macroCall($method, $parameters)
                    : $this->response->{$method}(...$parameters);
    }
}
