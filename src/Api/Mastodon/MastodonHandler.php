<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Service\Mastodon\MastodonService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * Shared error mapping and pagination links for the compatibility handlers.
 */
abstract class MastodonHandler implements Handler
{
	public function __construct(
		protected readonly MastodonService $mastodon,
	) {
	}

	/**
	 * @param callable(): array<string, mixed> $work
	 */
	protected function respond(callable $work): Response
	{
		try {
			return Response::json($work());
		} catch (MastodonRequestException $exception) {
			throw new HttpException(
				$exception->status,
				$exception->errorCode,
				$exception->getMessage(),
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($this->fields($exception->fields()), $exception->getMessage());
		}
	}

	/**
	 * @param callable(): void $work
	 */
	protected function respondEmpty(callable $work): Response
	{
		try {
			$work();
		} catch (MastodonRequestException $exception) {
			throw new HttpException(
				$exception->status,
				$exception->errorCode,
				$exception->getMessage(),
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($this->fields($exception->fields()), $exception->getMessage());
		}

		return Response::empty();
	}

	/**
	 * Core field names in a Mastodon client's vocabulary.
	 *
	 * The validation rules stay in `PostService`; only the labels in the error
	 * fields are translated for the compatibility surface.
	 *
	 * @param array<string, string> $fields
	 *
	 * @return array<string, string>
	 */
	private function fields(array $fields): array
	{
		$names = [
			'content' => 'status',
			'content_warning' => 'spoiler_text',
			'attachment_ids' => 'media_ids',
			'alt_text' => 'description',
		];

		$translated = [];
		foreach ($fields as $name => $message) {
			$translated[$names[$name] ?? $name] = $message;
		}

		return $translated;
	}

	protected function nextLink(Request $request, string $lastId, int $limit): string
	{
		$base = rtrim($this->mastodon->baseUrl(), '/');
		$query = '?limit=' . $limit . '&max_id=' . rawurlencode($lastId);

		return '<' . $base . $request->path . $query . '>; rel="next"';
	}
}
