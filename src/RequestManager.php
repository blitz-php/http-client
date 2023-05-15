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
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use LogicException;
use Psr\Http\Message\RequestInterface;

class RequestManager implements ArrayAccess
{
    use Macroable;

    /**
     * The decoded payload for the request.
     */
    protected array $data = [];

    /**
     * Create a new request instance.
     *
     * @param RequestInterface $request The underlying PSR request.
     */
    public function __construct(protected RequestInterface $request)
    {
    }

    /**
     * Get the request method.
     */
    public function method(): string
    {
        return $this->request->getMethod();
    }

    /**
     * Get the URL of the request.
     */
    public function url(): string
    {
        return (string) $this->request->getUri();
    }

    /**
     * Determine if the request has a given header.
     */
    public function hasHeader(string $key, mixed $value = null): bool
    {
        if (null === $value) {
            return ! empty($this->request->getHeaders()[$key]);
        }

        $headers = $this->headers();

        if (! Arr::has($headers, $key)) {
            return false;
        }

        $value = is_array($value) ? $value : [$value];

        return empty(array_diff($value, $headers[$key]));
    }

    /**
     * Determine if the request has the given headers.
     */
    public function hasHeaders(array|string $headers): bool
    {
        if (is_string($headers)) {
            $headers = [$headers => null];
        }

        foreach ($headers as $key => $value) {
            if (! $this->hasHeader($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the values for the header with the given name.
     */
    public function header(string $key): array
    {
        return Arr::get($this->headers(), $key, []);
    }

    /**
     * Get the request headers.
     */
    public function headers()
    {
        return $this->request->getHeaders();
    }

    /**
     * Get the body of the request.
     */
    public function body(): string
    {
        return (string) $this->request->getBody();
    }

    /**
     * Determine if the request contains the given file.
     */
    public function hasFile(string $name, ?string $value = null, ?string $filename = null): bool
    {
        if (! $this->isMultipart()) {
            return false;
        }

        return Helpers::collect($this->data)->reject(static function ($file) use ($name, $value, $filename) {
            return $file['name'] !== $name
                || ($value && $file['contents'] !== $value)
                || ($filename && $file['filename'] !== $filename);
        })->count() > 0;
    }

    /**
     * Get the request's data (form parameters or JSON).
     */
    public function data(): array
    {
        if ($this->isForm()) {
            return $this->parameters();
        }
        if ($this->isJson()) {
            return $this->json();
        }

        return $this->data ?? [];
    }

    /**
     * Get the request's form parameters.
     */
    protected function parameters(): array
    {
        if (! $this->data) {
            parse_str($this->body(), $parameters);

            $this->data = $parameters;
        }

        return $this->data;
    }

    /**
     * Get the JSON decoded body of the request.
     */
    protected function json(): array
    {
        if (! $this->data) {
            $this->data = json_decode($this->body(), true);
        }

        return $this->data;
    }

    /**
     * Determine if the request is simple form data.
     */
    public function isForm(): bool
    {
        return $this->hasHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    /**
     * Determine if the request is JSON.
     */
    public function isJson(): bool
    {
        return $this->hasHeader('Content-Type')
               && str_contains($this->header('Content-Type')[0], 'json');
    }

    /**
     * Determine if the request is multipart.
     */
    public function isMultipart(): bool
    {
        return $this->hasHeader('Content-Type')
               && str_contains($this->header('Content-Type')[0], 'multipart');
    }

    /**
     * Set the decoded data on the request.
     */
    public function withData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get the underlying PSR compliant request instance.
     */
    public function toPsrRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->data()[$offset]);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $offset
     */
    public function offsetGet($offset): mixed
    {
        return $this->data()[$offset];
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
        throw new LogicException('Request data may not be mutated using array access.');
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
        throw new LogicException('Request data may not be mutated using array access.');
    }
}
