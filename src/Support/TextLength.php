<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Support;

use function count;
use function mb_strlen;
use function preg_match_all;
use function rtrim;

/**
 * Post-length accounting that matches what Mastodon clients show their users.
 *
 * The instance endpoint advertises `characters_reserved_per_url`, so a
 * browser composer counts every URL as a fixed number of characters no
 * matter how long the link actually is. Counting raw characters instead
 * would reject posts a client believed fit — and accept ones it did not.
 * Local write validation therefore uses this weighted length; remote and
 * search content keeps raw `mb_strlen`, because a federated post was
 * already measured by its origin.
 */
final class TextLength
{
    /** The `characters_reserved_per_url` value the instance endpoint advertises. */
    public const MASTODON_URL_LENGTH = 23;

    private const URL_PATTERN = '/https?:\/\/[^\s<>"\'()\[\]{}]+/iu';

    /**
     * Length of the text with each URL counted as MASTODON_URL_LENGTH
     * characters. Trailing sentence punctuation is not part of the link.
     */
    public static function mastodonWeighted(string $text): int
    {
        $raw = mb_strlen($text);
        if ($raw === 0 || preg_match_all(self::URL_PATTERN, $text, $matches) === 0) {
            return $raw;
        }

        $urlCharacters = 0;
        foreach ($matches[0] as $url) {
            $urlCharacters += mb_strlen(rtrim($url, ".,;:!?)]}\"'"));
        }

        return $raw - $urlCharacters + count($matches[0]) * self::MASTODON_URL_LENGTH;
    }
}
