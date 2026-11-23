<script lang="ts">
let composerCounter = 0;
</script>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { UiApiError } from '../api-error.js';
import { useComposer } from '../composables/use-composer.js';
import { MAX_CONTENT_WARNING_LENGTH, MAX_POST_LENGTH } from '../core-api.js';
import { useHostBridge } from '../plugin.js';
import type { Post, PostVisibility } from '../types.js';

const props = withDefaults(defineProps<{
	inReplyToId?: string | null;
	defaultVisibility?: PostVisibility;
}>(), {
	inReplyToId: null,
	defaultVisibility: 'public',
});

const emit = defineEmits<{ created: [post: Post] }>();

const bridge = useHostBridge();
const { submitting, error, submit, resetError } = useComposer();
const fieldId = `aloha-composer-${++composerCounter}`;

const signedIn = ref<boolean | null>(null);
const content = ref('');
const contentWarning = ref('');
const showWarning = ref(false);
const visibility = ref<PostVisibility>(
	props.defaultVisibility === 'direct' ? 'public' : props.defaultVisibility,
);
const success = ref(false);

const contentLength = computed(() => Array.from(content.value).length);
const warningLength = computed(() => Array.from(contentWarning.value).length);
const tooLong = computed(() => contentLength.value > MAX_POST_LENGTH);
const warningTooLong = computed(() => warningLength.value > MAX_CONTENT_WARNING_LENGTH);
const canSubmit = computed(() => (
	signedIn.value === true
	&& content.value.trim() !== ''
	&& !tooLong.value
	&& !warningTooLong.value
	&& !submitting.value
));
const errorFields = computed(() => (error.value instanceof UiApiError ? error.value.fields : {}));
const signInUrl = computed(() => bridge.loginUrl(currentPath()));

onMounted(async () => {
	try {
		signedIn.value = (await bridge.currentUser()) !== null;
	} catch {
		// A host session lookup failure means the composer cannot know whether
		// a write is allowed; showing the sign-in state is safer than a form
		// that is guaranteed to answer 401.
		signedIn.value = false;
	}
});

async function onSubmit(): Promise<void> {
	success.value = false;
	resetError();

	if (!canSubmit.value) {
		return;
	}

	const post = await submit({
		content: content.value,
		visibility: visibility.value,
		contentWarning: showWarning.value ? contentWarning.value : null,
		inReplyToId: props.inReplyToId,
	});

	if (post === null) {
		return;
	}

	content.value = '';
	contentWarning.value = '';
	showWarning.value = false;
	success.value = true;
	emit('created', post);
}

function currentPath(): string {
	if (typeof window === 'undefined') {
		return '/';
	}

	return window.location.pathname + window.location.search;
}
</script>

<template>
	<section class="aloha-composer" aria-label="Write a post" :aria-busy="submitting">
		<p v-if="signedIn === null" class="aloha-composer__status" role="status" aria-live="polite">
			Checking your session…
		</p>

		<p v-else-if="signedIn === false" class="aloha-composer__status">
			<a :href="signInUrl">Sign in to post</a>
		</p>

		<form v-else class="aloha-composer__form" @submit.prevent="onSubmit">
			<label :for="`${fieldId}-content`" class="aloha-composer__label">What's new?</label>
			<textarea
				:id="`${fieldId}-content`"
				v-model="content"
				class="aloha-composer__content"
				rows="4"
				:aria-invalid="tooLong"
				:aria-describedby="`${fieldId}-count`"
			/>
			<p
				:id="`${fieldId}-count`"
				class="aloha-composer__count"
				:class="{ 'aloha-composer__count--over': tooLong }"
			>
				{{ contentLength }} / {{ MAX_POST_LENGTH }}
			</p>

			<label class="aloha-composer__toggle">
				<input v-model="showWarning" type="checkbox" />
				Content warning
			</label>

			<div v-if="showWarning" class="aloha-composer__field">
				<label :for="`${fieldId}-warning`">Content warning</label>
				<input
					:id="`${fieldId}-warning`"
					v-model="contentWarning"
					type="text"
					:aria-invalid="warningTooLong"
				/>
				<p class="aloha-composer__count">{{ warningLength }} / {{ MAX_CONTENT_WARNING_LENGTH }}</p>
			</div>

			<div class="aloha-composer__field">
				<label :for="`${fieldId}-visibility`">Visibility</label>
				<select :id="`${fieldId}-visibility`" v-model="visibility">
					<option value="public">Public</option>
					<option value="unlisted">Unlisted</option>
					<option value="followers">Followers only</option>
				</select>
			</div>

			<p v-if="error" class="aloha-composer__error" role="alert">
				{{ error.message }}
				<span v-for="(message, field) in errorFields" :key="field" class="aloha-composer__field-error">
					{{ field }}: {{ message }}
				</span>
			</p>

			<p v-if="success" class="aloha-composer__success" role="status" aria-live="polite">
				Posted.
			</p>

			<button class="aloha-composer__submit" type="submit" :disabled="!canSubmit">
				{{ submitting ? 'Posting…' : 'Post' }}
			</button>
		</form>
	</section>
</template>

<style scoped>
.aloha-composer {
	display: grid;
	gap: 0.75rem;
}

.aloha-composer__form {
	display: grid;
	gap: 0.75rem;
	padding: 1rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
}

.aloha-composer__label {
	font-weight: 700;
}

.aloha-composer__content,
.aloha-composer__field input,
.aloha-composer__field select {
	width: 100%;
	box-sizing: border-box;
	padding: 0.6rem 0.7rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	font: inherit;
	background: var(--aloha-input-background, transparent);
	color: inherit;
}

.aloha-composer__content {
	resize: vertical;
	min-height: 6rem;
}

.aloha-composer__content[aria-invalid='true'],
.aloha-composer__field input[aria-invalid='true'] {
	border-color: var(--aloha-error-text, #9f1d20);
}

.aloha-composer__count {
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
	font-size: 0.8125rem;
}

.aloha-composer__count--over {
	color: var(--aloha-error-text, #9f1d20);
}

.aloha-composer__toggle {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.aloha-composer__field {
	display: grid;
	gap: 0.35rem;
}

.aloha-composer__error {
	display: grid;
	gap: 0.25rem;
	margin: 0;
	color: var(--aloha-error-text, #9f1d20);
}

.aloha-composer__field-error {
	font-size: 0.875rem;
}

.aloha-composer__success {
	margin: 0;
	color: var(--aloha-success-text, #1f6b45);
}

.aloha-composer__submit {
	justify-self: start;
	padding: 0.55rem 1rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	background: var(--aloha-button-background, transparent);
	cursor: pointer;
}

.aloha-composer__submit:disabled {
	cursor: not-allowed;
	opacity: 0.6;
}

.aloha-composer__submit:focus-visible,
.aloha-composer__content:focus-visible,
.aloha-composer__field input:focus-visible,
.aloha-composer__field select:focus-visible {
	outline: 2px solid var(--aloha-focus-color, #2457a6);
	outline-offset: 2px;
}

.aloha-composer__status {
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
}
</style>
