/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { inject, type App, type InjectionKey, type Plugin } from 'vue';
import type { HostBridge } from './host-bridge.js';

export const hostBridgeKey: InjectionKey<HostBridge> = Symbol('alohasocial.host-bridge');

export interface AlohaUiOptions {
	bridge: HostBridge;
}

/**
 * The host installs one bridge at its page boundary. Components never import
 * a singleton, read a global or construct a transport themselves.
 */
export function createAlohaUi(options: AlohaUiOptions): Plugin {
	if (!options.bridge) {
		throw new Error('createAlohaUi() needs a HostBridge.');
	}

	return {
		install(app: App): void {
			app.provide(hostBridgeKey, options.bridge);
		},
	};
}

/**
 * A component calls this in setup(). A missing provider is a host wiring bug,
 * not a state the shared component can recover from.
 */
export function useHostBridge(): HostBridge {
	const bridge = inject(hostBridgeKey);

	if (!bridge) {
		throw new Error('No Aloha Social HostBridge was provided; install createAlohaUi() at the page boundary.');
	}

	return bridge;
}
