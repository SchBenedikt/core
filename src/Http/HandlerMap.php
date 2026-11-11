<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use InvalidArgumentException;

/**
 * A resolver built from lazy handler factories.
 *
 * Handlers are created when their route is hit, so a request only pays for
 * the services it actually uses.
 */
final readonly class HandlerMap implements HandlerResolver
{
	/**
	 * @var array<class-string<Handler>, callable(): Handler>
	 */
	private array $factories;

	/**
	 * @param array<class-string<Handler>, callable(): Handler> $factories
	 */
	public function __construct(array $factories = [])
	{
		$this->factories = $factories;
	}

	/**
	 * @param class-string<Handler> $handler
	 * @param callable(): Handler    $factory
	 */
	public static function of(string $handler, callable $factory): self
	{
		return (new self())->with($handler, $factory);
	}

	/**
	 * @param class-string<Handler> $handler
	 * @param callable(): Handler    $factory
	 */
	public function with(string $handler, callable $factory): self
	{
		$factories = $this->factories;
		$factories[$handler] = $factory;

		return new self($factories);
	}

	public function resolve(string $handler): Handler
	{
		$factory = $this->factories[$handler] ?? null;
		if ($factory === null) {
			throw new InvalidArgumentException('No handler is registered for ' . $handler . '.');
		}

		$instance = $factory();
		if (!$instance instanceof Handler) {
			throw new InvalidArgumentException('The factory for ' . $handler . ' did not return a handler.');
		}

		return $instance;
	}

	/**
	 * @return list<class-string<Handler>>
	 */
	public function registered(): array
	{
		return array_keys($this->factories);
	}
}
