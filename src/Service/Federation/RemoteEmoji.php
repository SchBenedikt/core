<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Service\ReactionException;

use function is_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function trim;

/**
 * The one place that decides whether an emoji is a reaction.
 *
 * Inbound a peer can send a Unicode emoji or a custom `:shortcode:`. A
 * shortcode is only accepted with its own `Emoji` tag whose icon is an
 * absolute https URL: without that pair the value is an unverified name, and
 * storing it would let a peer mint arbitrary "custom emoji" without a source.
 * The core stores that icon URL but never proxies, inlines or hotlinks it; a
 * host that renders custom emoji has to run it through its own media policy.
 *
 * Outbound only a Unicode emoji is allowed, because the core has no verified
 * local custom-emoji registry. Sending a shortcode would ask peers to render
 * an image the core cannot vouch for.
 */
final class RemoteEmoji
{
	public const MAX_LENGTH = 64;

	public const MAX_ICON_LENGTH = 512;

	/** `:name:` or `:name@host:` with a bounded, URL-safe name. */
	private const SHORTCODE = '/^:[A-Za-z0-9_]{1,50}(?:@[A-Za-z0-9._-]+)?:$/';

	/** At least one character that cannot be a plain word masquerading as a reaction. */
	private const EMOJI_CHARACTER = '/[\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{23FF}\x{24C2}\x{25AA}-\x{25FE}\x{2600}-\x{27BF}\x{2934}\x{2935}\x{2B00}-\x{2BFF}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1FAFF}\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';

	/**
	 * @param array<string, mixed> $activity
	 *
	 * @return array{emoji: string, icon_url: ?string}
	 *
	 * @throws InboxRejectedException when the reaction is not a valid emoji
	 */
	public static function fromActivity(array $activity): array
	{
		$raw = $activity['content'] ?? $activity['_misskey_reaction'] ?? $activity['name'] ?? null;
		if (!is_string($raw)) {
			throw new InboxRejectedException('invalid_emoji', 'An EmojiReact needs a string emoji.');
		}

		$value = trim($raw);
		if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
			throw new InboxRejectedException(
				'invalid_emoji',
				'An emoji reaction needs a non-empty emoji of at most 64 characters.',
			);
		}

		if (preg_match(self::SHORTCODE, $value) === 1) {
			$icon = self::iconFor($activity, $value);
			if ($icon === null) {
				throw new InboxRejectedException(
					'invalid_emoji',
					'A custom emoji needs a matching Emoji tag with an https icon.',
				);
			}

			return ['emoji' => $value, 'icon_url' => $icon];
		}

		if (!self::isUnicodeEmoji($value)) {
			throw new InboxRejectedException(
				'invalid_emoji',
				'An emoji reaction needs an emoji character, not text or markup.',
			);
		}

		return ['emoji' => $value, 'icon_url' => null];
	}

	/**
	 * The emoji a Misskey-style `Like` carries in `_misskey_reaction`.
	 *
	 * Misskey (and forks) encode an emoji reaction as a plain Like with the
	 * emoji in a namespaced field instead of an EmojiReact. Three spellings
	 * are in the wild. Unlike {@see fromActivity()} a `:shortcode:` is
	 * accepted as text even without a verified Emoji tag: Misskey does not
	 * tag Like reactions, and the stored value is only ever rendered as a
	 * label — the icon stays null unless a real tag verifies it, so no
	 * unverified remote image is ever requested.
	 *
	 * @param array<string, mixed> $activity
	 *
	 * @return array{emoji: string, icon_url: ?string}|null null when the
	 *         activity carries no usable reaction value
	 */
	public static function misskeyReaction(array $activity): ?array
	{
		$raw = $activity['_misskey_reaction']
			?? $activity['misskey:_misskey_reaction']
			?? $activity['https://misskey-hub.net/ns#_misskey_reaction']
			?? null;
		if (!is_string($raw)) {
			return null;
		}

		$value = trim($raw);
		if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
			return null;
		}

		if (preg_match(self::SHORTCODE, $value) === 1) {
			return ['emoji' => $value, 'icon_url' => self::iconFor($activity, $value)];
		}

		return self::isUnicodeEmoji($value) ? ['emoji' => $value, 'icon_url' => null] : null;
	}

	/**
	 * @throws ReactionException when the value cannot be sent by this instance
	 */
	public static function forOutbound(string $emoji): string
	{
		$value = trim($emoji);
		if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
			throw new ReactionException(
				'invalid_emoji',
				'An emoji reaction needs a non-empty emoji of at most 64 characters.',
			);
		}

		if (preg_match(self::SHORTCODE, $value) === 1) {
			throw new ReactionException(
				'invalid_emoji',
				'Custom emoji shortcodes cannot be sent: this instance has no verified local emoji source.',
			);
		}

		if (!self::isUnicodeEmoji($value)) {
			throw new ReactionException('invalid_emoji', 'An emoji reaction needs an emoji character.');
		}

		return $value;
	}

	private static function isUnicodeEmoji(string $value): bool
	{
		return preg_match(self::EMOJI_CHARACTER, $value) === 1
			&& preg_match('/[\x00-\x1F\x7F<>]/u', $value) !== 1;
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private static function iconFor(array $activity, string $shortcode): ?string
	{
		$tags = $activity['tag'] ?? null;
		if (!is_array($tags)) {
			return null;
		}

		foreach ($tags as $tag) {
			if (!is_array($tag) || ($tag['type'] ?? null) !== 'Emoji') {
				continue;
			}

			$name = $tag['name'] ?? null;
			if (!is_string($name)) {
				continue;
			}

			$normalized = str_starts_with($name, ':') ? $name : ':' . $name . ':';
			if ($normalized !== $shortcode) {
				continue;
			}

			$icon = $tag['icon'] ?? null;
			$url = is_array($icon) ? ($icon['url'] ?? null) : null;
			if (is_string($url) && self::isSafeIconUrl($url)) {
				return $url;
			}
		}

		return null;
	}

	private static function isSafeIconUrl(string $url): bool
	{
		if ($url === '' || mb_strlen($url) > self::MAX_ICON_LENGTH) {
			return false;
		}

		$parts = parse_url($url);

		return $parts !== false
			&& ($parts['scheme'] ?? null) === 'https'
			&& ($parts['host'] ?? '') !== ''
			&& !isset($parts['user'])
			&& !isset($parts['pass']);
	}
}
