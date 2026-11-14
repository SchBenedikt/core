<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Migration;

use AlohaSocial\Core\Migration\MigrationInventory;
use PHPUnit\Framework\TestCase;

use function array_count_values;
use function array_keys;
use function array_map;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class MigrationInventoryTest extends TestCase
{
	public function testEveryDomainAppearsExactlyOnce(): void
	{
		$features = array_map(
			static fn (array $entry): string => $entry['feature'],
			MigrationInventory::entries(),
		);

		self::assertSame(MigrationInventory::DOMAINS, $features);
		self::assertSame([], array_keys(array_filter(
			array_count_values($features),
			static fn (int $count): bool => $count !== 1,
		)));
	}

	public function testNoFeatureIsSilentlyDropped(): void
	{
		foreach (MigrationInventory::entries() as $entry) {
			self::assertContains($entry['retention'], ['preserve', 'convert'], $entry['feature']);
			self::assertNotSame('drop', $entry['retention'], $entry['feature']);
		}
	}

	public function testEveryOpenGapNamesItsIssue(): void
	{
		foreach (MigrationInventory::entries() as $entry) {
			if ($entry['status'] === MigrationInventory::STATUS_GAP
				|| $entry['status'] === MigrationInventory::STATUS_PARTIAL) {
				self::assertIsString($entry['gap_issue'], $entry['feature']);
				self::assertMatchesRegularExpression('/^#\d+$/', $entry['gap_issue'], $entry['feature']);
				continue;
			}

			self::assertNull($entry['gap_issue'], $entry['feature']);
		}
	}

	public function testEveryFeatureNamesItsOwnerAndValidationLevel(): void
	{
		$levels = ['v1', 'v2', 'inventory-only'];

		foreach (MigrationInventory::entries() as $entry) {
			self::assertContains($entry['owner'], [MigrationInventory::OWNER_CORE, MigrationInventory::OWNER_HOST]);
			self::assertContains($entry['status'], [
				MigrationInventory::STATUS_SUPPORTED,
				MigrationInventory::STATUS_PARTIAL,
				MigrationInventory::STATUS_GAP,
				MigrationInventory::STATUS_HOST_ONLY,
			]);
			self::assertContains($entry['validation'], $levels);
			self::assertNotSame('', $entry['destination']);
			self::assertNotSame('', $entry['rollback']);
			self::assertSame($entry['owner'] === MigrationInventory::OWNER_CORE, $entry['url_identity']);
		}
	}

	public function testTheExportValidatedFeaturesAreMarkedWithTheirVersion(): void
	{
		$byFeature = [];
		foreach (MigrationInventory::entries() as $entry) {
			$byFeature[$entry['feature']] = $entry;
		}

		foreach (['accounts', 'posts', 'followers', 'following', 'queue', 'activity_log'] as $feature) {
			self::assertSame('v1', $byFeature[$feature]['validation'], $feature);
		}

		foreach (['post_revisions', 'attachments', 'favourites', 'emoji_reactions'] as $feature) {
			self::assertSame('v2', $byFeature[$feature]['validation'], $feature);
		}

		self::assertSame('supported', $byFeature['quotes']['status']);
		self::assertNull($byFeature['quotes']['gap_issue']);
		self::assertSame('supported', $byFeature['emoji_reactions']['status']);
		self::assertNull($byFeature['emoji_reactions']['gap_issue']);
		self::assertSame('supported', $byFeature['favourites']['status']);
		self::assertNull($byFeature['favourites']['gap_issue']);
	}

	public function testGapsOnlyListFeaturesWithoutACompleteDestination(): void
	{
		$gaps = MigrationInventory::gaps();

		self::assertNotSame([], $gaps);
		foreach ($gaps as $gap) {
			self::assertContains($gap['status'], [
				MigrationInventory::STATUS_GAP,
				MigrationInventory::STATUS_PARTIAL,
			]);
			self::assertNotNull($gap['gap_issue']);
		}
	}

	public function testTheInventorySerializesAsAStableDocument(): void
	{
		$document = json_decode(MigrationInventory::toJson(), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame(MigrationInventory::FORMAT, $document['format']);
		self::assertSame(MigrationInventory::VERSION, $document['version']);
		self::assertCount(count(MigrationInventory::DOMAINS), $document['entries']);
		self::assertSame('preserve', $document['entries'][0]['retention']);
		self::assertFalse($document['entries'][0]['url_identity']);
		self::assertTrue($document['entries'][2]['url_identity']);
	}
}
