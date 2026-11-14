<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterKeyword;
use AlohaSocial\Core\Domain\Filter\FilterNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\Mastodon\MastodonFilterPresenter;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Service\ValidationException;
use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;

use function array_is_list;
use function ctype_digit;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function trim;

/**
 * Shared parsing for the v2 filter routes.
 *
 * Mastodon clients send `context[]`, `keywords_attributes` and an expiry in
 * seconds; the legacy `phrase` field is still accepted as one whole-word
 * keyword. Parsing stays here so create and update cannot drift.
 */
abstract class MastodonFilterHandler implements Handler
{
	public function __construct(
		protected readonly FilterService $filters,
		protected readonly MastodonFilterPresenter $presenter,
		protected readonly Clock $clock,
	) {
	}

	/**
	 * @return array{
	 *     name: string,
	 *     action: FilterAction,
	 *     contexts: list<FilterContext>,
	 *     keywords: list<FilterKeyword>,
	 *     expiresAt: ?DateTimeImmutable
	 * }
	 */
	protected function input(Request $request): array
	{
		$body = $request->payload();

		$title = $body['title'] ?? null;
		if (!is_string($title) || trim($title) === '') {
			throw new MastodonRequestException(422, 'validation_failed', 'title is required.');
		}

		$rawAction = $body['filter_action'] ?? 'warn';
		if (!is_string($rawAction)) {
			throw new MastodonRequestException(422, 'validation_failed', 'filter_action must be a string.');
		}

		try {
			$action = FilterAction::fromString($rawAction);
		} catch (InvalidArgumentException) {
			throw new MastodonRequestException(422, 'validation_failed', 'filter_action must be hide or warn.');
		}

		$contexts = [];
		$rawContexts = $body['context'] ?? null;
		if (is_string($rawContexts)) {
			$rawContexts = [$rawContexts];
		}

		if (!is_array($rawContexts) || $rawContexts === []) {
			throw new MastodonRequestException(422, 'validation_failed', 'context is required.');
		}

		foreach ($rawContexts as $rawContext) {
			if (!is_string($rawContext)) {
				throw new MastodonRequestException(422, 'validation_failed', 'context must contain strings.');
			}

			try {
				$contexts[] = FilterContext::fromString($rawContext);
			} catch (InvalidArgumentException) {
				throw new MastodonRequestException(422, 'validation_failed', 'Unknown filter context: ' . $rawContext);
			}
		}

		$keywords = [];
		$rawKeywords = $body['keywords_attributes'] ?? null;
		if (is_array($rawKeywords)) {
			if (isset($rawKeywords['keyword'])) {
				$rawKeywords = [$rawKeywords];
			}

			if (!array_is_list($rawKeywords)) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'keywords_attributes must be a list.',
				);
			}

			foreach ($rawKeywords as $rawKeyword) {
				if (!is_array($rawKeyword) || !isset($rawKeyword['keyword']) || !is_string($rawKeyword['keyword'])) {
					throw new MastodonRequestException(
						422,
						'validation_failed',
						'Every keyword needs a keyword field.',
					);
				}

				$wholeWord = $rawKeyword['whole_word'] ?? false;
				if (!is_bool($wholeWord)) {
					throw new MastodonRequestException(
						422,
						'validation_failed',
						'whole_word must be a boolean.',
					);
				}

				try {
					$keywords[] = new FilterKeyword($rawKeyword['keyword'], $wholeWord);
				} catch (InvalidArgumentException $exception) {
					throw new MastodonRequestException(422, 'validation_failed', $exception->getMessage());
				}
			}
		}

		if ($keywords === [] && isset($body['phrase']) && is_string($body['phrase'])) {
			$keywords[] = new FilterKeyword($body['phrase'], true);
		}

		$expiresAt = null;
		$expiresIn = $body['expires_in'] ?? null;
		if ($expiresIn !== null) {
			if (!is_int($expiresIn) && !(is_string($expiresIn) && ctype_digit($expiresIn))) {
				throw new MastodonRequestException(422, 'validation_failed', 'expires_in must be seconds.');
			}

			$seconds = (int) $expiresIn;
			if ($seconds < 1 || $seconds > 31_536_000) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'expires_in must be between 1 and 31536000 seconds.',
				);
			}

			$expiresAt = $this->clock->now()->modify('+' . $seconds . ' seconds');
		}

		return [
			'name' => $title,
			'action' => $action,
			'contexts' => $contexts,
			'keywords' => $keywords,
			'expiresAt' => $expiresAt,
		];
	}

	/**
	 * @param callable(): mixed $work
	 */
	protected function respondEmpty(callable $work): Response
	{
		try {
			$work();
		} catch (FilterNotFoundException) {
			throw new HttpException(404, 'not_found', 'Filter not found.');
		} catch (MastodonRequestException $exception) {
			throw new HttpException($exception->status, $exception->errorCode, $exception->getMessage());
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(new stdClass());
	}

	/**
	 * @param callable(): array<string, mixed> $work
	 */
	protected function respond(callable $work): Response
	{
		try {
			return Response::json($work());
		} catch (FilterNotFoundException) {
			throw new HttpException(404, 'not_found', 'Filter not found.');
		} catch (MastodonRequestException $exception) {
			throw new HttpException($exception->status, $exception->errorCode, $exception->getMessage());
		} catch (ValidationException $exception) {
			$fields = [];
			foreach ($exception->fields() as $name => $message) {
				$fields[match ($name) {
					'name' => 'title',
					'contexts' => 'context',
					'keywords' => 'keywords_attributes',
					default => $name,
				}] = $message;
			}

			throw HttpException::validationFailed($fields, $exception->getMessage());
		}
	}
}
