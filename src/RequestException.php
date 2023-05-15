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

use Exception;
use GuzzleHttp\Psr7\Message;

class RequestException extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @param Response $response The response instance.
     */
    public function __construct(public Response $response)
    {
        parent::__construct($this->prepareMessage($response), $response->status());
    }

    /**
     * Prepare the exception message.
     */
    protected function prepareMessage(Response $response): string
    {
        $message = "HTTP request returned status code {$response->status()}";

        $summary = Message::bodySummary($response->toPsrResponse());

        return null === $summary ? $message : $message .= ":\n{$summary}\n";
    }
}
