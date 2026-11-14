<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterKeyword;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_is_list;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function strtolower;
use function trim;

/**
 * The neutral filter write body, parsed in one place.
 *
 * Both create and update replace the whole rule, so a missing field is an
 * error rather than a silent partial update: a reader who meant to change the
 * action must not accidentally drop the keyword list.
 */
final class FilterRequestParser
{
	/**
	 * Whether the reader explicitly asked to see warned content.
	 */
	public static function includeFiltered(Request $request): bool
	{
		$value = $request->queryParam('include_filtered');

		return is_string($value)
			&& in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
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
	public static function parse(Request $request): array
	{
		$body = $request->json();

		$name = $body['name'] ?? null;
		if (!is_string($name)) {
			throw HttpException::validationFailed(['name' => 'name must be a string.']);
		}

		$rawAction = $body['action'] ?? null;
		if (!is_string($rawAction)) {
			throw HttpException::validationFailed(['action' => 'action must be a string.']);
		}

		try {
			$action = FilterAction::fromString($rawAction);
		} catch (InvalidArgumentException) {
			throw HttpException::validationFailed(['action' => 'action must be hide or warn.']);
		}

		$rawContexts = $body['contexts'] ?? null;
		if (!is_array($rawContexts) || !array_is_list($rawContexts)) {
			throw HttpException::validationFailed(['contexts' => 'contexts must be a list of strings.']);
		}

		$contexts = [];
		foreach ($rawContexts as $rawContext) {
			if (!is_string($rawContext)) {
				throw HttpException::validationFailed(['contexts' => 'contexts must be a list of strings.']);
			}

			try {
				$contexts[] = FilterContext::fromString($rawContext);
			} catch (InvalidArgumentException) {
				throw HttpException::validationFailed([
					'contexts' => 'contexts must contain only ' . implode(', ', FilterContext::values()) . '.',
				]);
			}
		}

		$rawKeywords = $body['keywords'] ?? null;
		if (!is_array($rawKeywords) || !array_is_list($rawKeywords)) {
			throw HttpException::validationFailed([
				'keywords' => 'keywords must be a list of {text, whole_word} objects.',
			]);
		}

		$keywords = [];
		foreach ($rawKeywords as $rawKeyword) {
			if (!is_array($rawKeyword) || !isset($rawKeyword['text']) || !is_string($rawKeyword['text'])) {
				throw HttpException::validationFailed([
					'keywords' => 'keywords must be a list of {text, whole_word} objects.',
				]);
			}

			$wholeWord = $rawKeyword['whole_word'] ?? false;
			if (!is_bool($wholeWord)) {
				throw HttpException::validationFailed(['keywords' => 'whole_word must be a boolean.']);
			}

			try {
				$keywords[] = new FilterKeyword($rawKeyword['text'], $wholeWord);
			} catch (InvalidArgumentException $exception) {
				throw HttpException::validationFailed(['keywords' => $exception->getMessage()]);
			}
		}

		$expiresAt = null;
		$rawExpiresAt = $body['expires_at'] ?? null;
		if ($rawExpiresAt !== null) {
			if (!is_string($rawExpiresAt) || trim($rawExpiresAt) === '') {
				throw HttpException::validationFailed(['expires_at' => 'expires_at must be a timestamp or null.']);
			}

			try {
				$expiresAt = Time::parseIso8601($rawExpiresAt);
			} catch (InvalidArgumentException) {
				throw HttpException::validationFailed(['expires_at' => 'expires_at must be an ISO 8601 timestamp.']);
			}
		}

		return [
			'name' => $name,
			'action' => $action,
			'contexts' => $contexts,
			'keywords' => $keywords,
			'expiresAt' => $expiresAt,
		];
	}
}
