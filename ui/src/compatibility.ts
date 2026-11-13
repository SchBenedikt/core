/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge } from './host-bridge.js';
import { HOST_BRIDGE_CONTRACT_VERSION, UI_VERSION } from './version.js';

const requiredMethods = [
	'currentUser',
	'request',
	'loginUrl',
	'logout',
	'navigate',
	'capabilities',
] as const;

const requiredCapabilities = ['media', 'atproto'] as const;

/**
 * Fail fast at the host page boundary when a bridge is too old, too new or
 * incomplete. A missing `contractVersion` is deliberately accepted as contract
 * 1 so existing bridges do not need a new mandatory property.
 */
export function assertHostBridgeCompatible(bridge: HostBridge): void {
	if (typeof bridge.apiBase !== 'string' || bridge.apiBase.length === 0) {
		throw new Error(
			`HostBridge.apiBase must be a non-empty string; @alohasocial/ui ${UI_VERSION} implements contract ${HOST_BRIDGE_CONTRACT_VERSION}.`,
		);
	}

	for (const method of requiredMethods) {
		if (typeof bridge[method] !== 'function') {
			throw new Error(
				`HostBridge is missing the required "${method}" method; @alohasocial/ui ${UI_VERSION} implements contract ${HOST_BRIDGE_CONTRACT_VERSION}.`,
			);
		}
	}

	const contractVersion = bridge.contractVersion === undefined
		? HOST_BRIDGE_CONTRACT_VERSION
		: bridge.contractVersion;

	if (!Number.isInteger(contractVersion) || contractVersion !== HOST_BRIDGE_CONTRACT_VERSION) {
		throw new Error(
			`HostBridge contract ${String(contractVersion)} is not supported by @alohasocial/ui ${UI_VERSION} (contract ${HOST_BRIDGE_CONTRACT_VERSION}). Upgrade @alohasocial/ui and alohasocial/core together.`,
		);
	}

	const capabilities = bridge.capabilities();
	for (const capability of requiredCapabilities) {
		if (typeof capabilities?.[capability] !== 'boolean') {
			throw new Error(
				`HostBridge.capabilities() must return a boolean "${capability}" field; use false when the host does not provide it.`,
			);
		}
	}
}
