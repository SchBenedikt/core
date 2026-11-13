/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export { hostBridgeKey, createAlohaUi, useHostBridge, type AlohaUiOptions } from './plugin.js';
export type { HostBridge } from './host-bridge.js';
export type {
	Attachment,
	Poll,
	PollOption,
	Post,
	UiCapabilities,
	UiMethod,
	UiRequest,
	UiResponse,
	UiUser,
} from './types.js';
export { coreRoutes } from './routes.js';
export {
	CoreApi,
	DEFAULT_TIMELINE_LIMIT,
	MAX_TIMELINE_LIMIT,
	normalizeTimelineLimit,
	type PublicTimelineOptions,
} from './core-api.js';
export { UiApiError, UiTransportError, isUiFailure, type UiErrorFields, type UiFailure } from './api-error.js';
export { useCoreApi } from './composables/use-core-api.js';
export { usePostDetail, type PostDetailState } from './composables/use-post-detail.js';
export { usePublicTimeline, type PublicTimelineState } from './composables/use-public-timeline.js';
export { default as PostCard } from './components/PostCard.vue';
export { default as PostDetail } from './components/PostDetail.vue';
export { default as PublicTimeline } from './components/PublicTimeline.vue';
export {
	FakeHostBridge,
	fakeRequestKey,
	type FakeHostBridgeOptions,
	type FakeResponder,
	type FakeResponse,
} from './testing/fake-host-bridge.js';
