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
use function array_values;
use function count;
use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

/**
 * The parsed, optional description and focal point of a media metadata update.
 *
 * Mastodon clients send this request as form data, JSON or multipart. Binary
 * fields are refused instead of being silently dropped. The presence flags
 * matter: an absent description or focus leaves the current value unchanged,
 * while an empty description clears it and a null focus pair resets the crop.
 */
final readonly class MediaUpdateInput
{
	private const MAX_BODY_BYTES = 131_072;

	public function __construct(
		public bool $hasDescription,
		public ?string $description,
		public bool $hasFocus = false,
		public ?float $focusX = null,
		public ?float $focusY = null,
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

			[$hasFocus, $focusX, $focusY] = array_key_exists('focus', $form->fields)
				? self::focus($form->fields['focus'])
				: [false, null, null];

			return new self(
				$hasDescription,
				$hasDescription ? self::normalize($description) : null,
				$hasFocus,
				$focusX,
				$focusY,
			);
		}

		$body = $request->payload();
		self::rejectBinaryField(array_key_exists('file', $body), 'file');
		self::rejectBinaryField(array_key_exists('thumbnail', $body), 'thumbnail');

		$hasDescription = array_key_exists('description', $body) || array_key_exists('alt_text', $body);
		$description = null;
		if ($hasDescription) {
			$value = array_key_exists('description', $body) ? $body['description'] : ($body['alt_text'] ?? null);
			if ($value !== null && !is_string($value)) {
				throw HttpException::validationFailed(
					['description' => 'description must be a string or null.'],
					'description must be a string or null.',
				);
			}

			$description = self::normalize($value);
		}

		[$hasFocus, $focusX, $focusY] = array_key_exists('focus', $body)
			? self::focus($body['focus'])
			: [false, null, null];

		return new self($hasDescription, $description, $hasFocus, $focusX, $focusY);
	}

	/**
	 * @return array{0: bool, 1: ?float, 2: ?float}
	 */
	private static function focus(mixed $value): array
	{
		if ($value === null || $value === '') {
			return [true, null, null];
		}

		$pair = is_string($value) ? explode(',', $value, 2) : (is_array($value) ? array_values($value) : null);
		if ($pair === null || count($pair) !== 2 || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
			throw HttpException::validationFailed(
				['focus' => 'focus must be two numbers between -1 and 1, like "-0.5,0.5".'],
			);
		}

		$focusX = (float) $pair[0];
		$focusY = (float) $pair[1];
		if ($focusX < -1.0 || $focusX > 1.0 || $focusY < -1.0 || $focusY > 1.0) {
			throw HttpException::validationFailed(
				['focus' => 'focus must be two numbers between -1 and 1, like "-0.5,0.5".'],
			);
		}

		return [true, $focusX, $focusY];
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
