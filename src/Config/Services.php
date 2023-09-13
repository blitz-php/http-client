<?php

/**
 * This file is part of Blitz PHP framework - HTTP Client.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\HttpClient\Config;

use BlitzPHP\Container\Services as BaseServices;
use BlitzPHP\HttpClient\Request;

class Services extends BaseServices
{
    /**
     * Le client HTTP fourni une interface simple pour interagir avec d'autres serveurs.
     * Typiquement a traver des APIs.
     */
    public static function httpclient(?string $baseUrl = null, bool $shared = true): Request
    {
        if (true === $shared && isset(static::$instances[Request::class])) {
            return static::$instances[Request::class]->baseUrl((string) $baseUrl);
        }

        return static::$instances[Request::class] = static::factory(Request::class, ['event' => static::event()])->baseUrl((string) $baseUrl);
    }
}
