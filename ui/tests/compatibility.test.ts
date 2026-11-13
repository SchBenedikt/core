/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs';
import {
	assertHostBridgeCompatible,
	createAlohaUi,
	HOST_BRIDGE_CONTRACT_VERSION,
	UI_VERSION,
	type HostBridge,
	type UiCapabilities,
} from '../src/index.js';

interface PackageManifest {
	version: string;
}

interface PackageLock {
	version: string;
	packages: Record<string, PackageManifest>;
}

function readJson<T>(relativePath: string): T {
	return JSON.parse(readFileSync(new URL(relativePath, import.meta.url), 'utf8')) as T;
}

function bridge(overrides: Partial<HostBridge> = {}): HostBridge {
	return {
		apiBase: '/api/v1',
		currentUser: async () => null,
		request: async () => ({ status: 200, headers: {}, body: '' }),
		loginUrl: () => '/login',
		logout: async () => undefined,
		navigate: () => undefined,
		capabilities: () => ({ media: false, atproto: false }),
		...overrides,
	};
}

describe('package versions', () => {
	it('keeps the UI build version and the package lock in sync', () => {
		const packageJson = readJson<PackageManifest>('../package.json');
		const lock = readJson<PackageLock>('../package-lock.json');

		expect(UI_VERSION).toBe(packageJson.version);
		expect(lock.version).toBe(UI_VERSION);
		expect(lock.packages['']?.version).toBe(UI_VERSION);
		expect(HOST_BRIDGE_CONTRACT_VERSION).toBe(1);
	});
});

describe('HostBridge compatibility', () => {
	it('accepts contract 1 and a legacy bridge without contractVersion', () => {
		expect(() => assertHostBridgeCompatible(bridge())).not.toThrow();
		expect(() => assertHostBridgeCompatible(bridge({ contractVersion: 1 }))).not.toThrow();
	});

	it('rejects a newer contract with a clear upgrade message', () => {
		expect(() => assertHostBridgeCompatible(bridge({ contractVersion: 2 }))).toThrow(
			'HostBridge contract 2 is not supported by @alohasocial/ui',
		);
	});

	it('rejects a bridge missing a required method', () => {
		const incomplete = bridge({ request: undefined as unknown as HostBridge['request'] });

		expect(() => assertHostBridgeCompatible(incomplete)).toThrow(
			'HostBridge is missing the required "request" method',
		);
	});

	it('rejects a bridge missing a required capability', () => {
		const incomplete = bridge({
			capabilities: () => ({ media: true } as unknown as UiCapabilities),
		});

		expect(() => assertHostBridgeCompatible(incomplete)).toThrow(
			'HostBridge.capabilities() must return a boolean "atproto" field',
		);
	});

	it('fails at plugin creation instead of mounting an incompatible bridge', () => {
		expect(() => createAlohaUi({ bridge: bridge({ contractVersion: 2 }) })).toThrow(
			'HostBridge contract 2 is not supported',
		);
	});
});
