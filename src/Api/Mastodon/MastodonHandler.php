<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Service\Mastodon\MastodonService;
use AlohaSocial\Core\Service\ValidationException;
use stdClass;

use function is_array;
use function is_string;
use function trim;

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
		} catch (ConfigurationException $exception) {
			throw new HttpException(
				503,
				'instance_not_configured',
				'The instance URL is not configured.',
			);
		}
	}

	/**
	 * @param callable(): void $work
	 */
	protected function respondEmpty(callable $work): Response
	{
		$this->run($work);

		return Response::empty();
	}

	/**
	 * Reads a repeatable query parameter such as `types[]`.
	 *
	 * @return list<string>
	 */
	protected function listQuery(Request $request, string $name): array
	{
		$raw = $request->query[$name] ?? null;
		if ($raw === null) {
			return [];
		}

		$values = is_array($raw) ? $raw : [$raw];
		$result = [];
		foreach ($values as $value) {
			if (is_string($value) && trim($value) !== '') {
				$result[] = trim($value);
			}
		}

		return $result;
	}

	/**
	 * A 200 with an empty JSON object, the shape Mastodon uses for DELETE media.
	 *
	 * @param callable(): void $work
	 */
	protected function respondEmptyObject(callable $work): Response
	{
		$this->run($work);

		return Response::json(new stdClass());
	}

	/**
	 * @param callable(): void $work
	 */
	private function run(callable $work): void
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
		} catch (ConfigurationException $exception) {
			throw new HttpException(
				503,
				'instance_not_configured',
				'The instance URL is not configured.',
			);
		}
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

	/**
	 * @param array<string, mixed> $extraQuery
	 */
	protected function nextLink(Request $request, string $lastId, int $limit, array $extraQuery = []): string
	{
		$base = rtrim($this->mastodon->baseUrl(), '/');
		$query = '?limit=' . $limit . '&max_id=' . rawurlencode($lastId);
		if ($extraQuery !== []) {
			$query .= '&' . http_build_query($extraQuery);
		}

		return '<' . $base . $request->path . $query . '>; rel="next"';
	}
}
