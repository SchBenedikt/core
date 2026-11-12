<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\MultipartFormData;
use AlohaSocial\Core\Http\Request;

use function array_key_exists;
use function is_string;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

/**
 * The parsed, optional description of a media metadata update.
 *
 * Mastodon clients send this request as form data, JSON or multipart. The
 * core has no focal-point or replacement-thumbnail metadata, so `focus` is
 * ignored and binary fields are refused instead of being silently dropped.
 * The presence flag matters: an absent description leaves the current value
 * unchanged, while an empty one clears it.
 */
final readonly class MediaUpdateInput
{
	private const MAX_BODY_BYTES = 131_072;

	public function __construct(
		public bool $hasDescription,
		public ?string $description,
	) {
	}

	/**
	 * @throws HttpException when the body is malformed or asks for an unsupported binary update
	 */
	public static function fromRequest(Request $request): self
	{
		if (strlen($request->body) > self::MAX_BODY_BYTES) {
			throw new HttpException(413, 'payload_too_large', 'The metadata update is too large.');
		}

		$contentType = strtolower($request->header('content-type') ?? '');
		if (str_contains($contentType, 'multipart/form-data')) {
			$form = MultipartFormData::fromRequest($request);
			self::rejectBinaryField($form->file('file') !== null, 'file');
			self::rejectBinaryField($form->file('thumbnail') !== null, 'thumbnail');

			$hasDescription = array_key_exists('description', $form->fields);
			$description = $hasDescription ? $form->field('description') : null;
			if (!$hasDescription && array_key_exists('alt_text', $form->fields)) {
				$hasDescription = true;
				$description = $form->field('alt_text');
			}

			return new self($hasDescription, $hasDescription ? self::normalize($description) : null);
		}

		$body = $request->payload();
		self::rejectBinaryField(array_key_exists('file', $body), 'file');
		self::rejectBinaryField(array_key_exists('thumbnail', $body), 'thumbnail');

		$hasDescription = array_key_exists('description', $body) || array_key_exists('alt_text', $body);
		if (!$hasDescription) {
			return new self(false, null);
		}

		$value = array_key_exists('description', $body) ? $body['description'] : ($body['alt_text'] ?? null);
		if ($value !== null && !is_string($value)) {
			throw HttpException::validationFailed(
				['description' => 'description must be a string or null.'],
				'description must be a string or null.',
			);
		}

		return new self(true, self::normalize($value));
	}

	private static function rejectBinaryField(bool $present, string $field): void
	{
		if ($present) {
			throw new HttpException(
				422,
				'unsupported_parameter',
				'Updating ' . $field . ' bytes is not available in this compatibility slice.',
			);
		}
	}

	private static function normalize(?string $description): ?string
	{
		if ($description === null) {
			return null;
		}

		$description = trim($description);

		return $description === '' ? null : $description;
	}
}
