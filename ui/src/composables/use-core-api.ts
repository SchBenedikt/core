/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { CoreApi } from '../core-api.js';
import { useHostBridge } from '../plugin.js';

export function useCoreApi(): CoreApi {
	return new CoreApi(useHostBridge());
}
