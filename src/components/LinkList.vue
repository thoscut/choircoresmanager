<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Active links: who can open them (archive roles, choir members or the
 * public), what they share (the whole piece or measures, with the scan or
 * not), until when they are valid, how often they were opened (a count
 * only, no names). The creator and the catalog role can revoke a link and
 * copy a link for choir members or a public link; the server sends its
 * token only to them.
 */
import type { ExcerptLink } from '../types.ts'

import { mdiContentCopy, mdiLinkOff } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ActionDialog from './ActionDialog.vue'
import { errorMessage, linkUrl, revokeLink } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { formatDate, formatDateTime } from '../format.ts'
import { referenceWords } from '../labels.ts'
import { scopeText } from '../sharing/linkScope.ts'
import { entriesById, isLibrarian, openEntry } from '../store.ts'

withDefaults(defineProps<{
	links: ExcerptLink[]
	/** show the shelf mark and title of each link (list across copies) */
	showCopy?: boolean
}>(), {
	showCopy: false,
})

const emit = defineEmits<{
	changed: []
}>()

/**
 * Where the link points.
 *
 * @param link - excerpt link
 */
function place(link: ExcerptLink): string {
	return scopeText({
		measureFrom: link.measure_from,
		measureTo: link.measure_to,
		voice: link.voice,
		verse: link.verse,
	}, referenceWords(), t('notenarchiv', 'Whole piece'))
}

/**
 * Who can open the link, as text (not only as a color).
 *
 * @param link - excerpt link
 */
function circleLabel(link: ExcerptLink): string {
	switch (link.circle) {
	case 'members':
		return t('notenarchiv', 'For choir members')
	case 'public':
		return t('notenarchiv', 'Public')
	default:
		return t('notenarchiv', 'Internal')
	}
}

/**
 * @param link - excerpt link
 */
function canRevoke(link: ExcerptLink): boolean {
	return isLibrarian.value || link.own
}

/**
 * @param link - excerpt link
 */
async function copy(link: ExcerptLink): Promise<void> {
	if (link.token !== null && await copyText(linkUrl(link.token, link.circle))) {
		showSuccess(t('notenarchiv', 'The link has been copied.'))
	} else {
		showError(t('notenarchiv', 'The link could not be copied. Please select and copy it by hand.'))
	}
}

const revoking = ref<ExcerptLink | null>(null)
const busy = ref(false)
const error = ref('')

async function revoke(): Promise<void> {
	if (!revoking.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		await revokeLink(revoking.value.id)
		showSuccess(t('notenarchiv', 'The link has been revoked.'))
		revoking.value = null
		emit('changed')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="link-list">
		<p v-if="links.length === 0" class="link-list__empty">
			{{ t('notenarchiv', 'No active links.') }}
		</p>
		<ul v-else class="link-list__items">
			<li v-for="link in links" :key="link.id" class="link-list__item">
				<div class="link-list__body">
					<p class="link-list__title">
						<template v-if="showCopy">
							<button type="button" class="link-list__copy-button" @click="openEntry(link.copy_id)">
								{{ entriesById.get(link.copy_id)?.shelfmark ?? link.copy_id }}
							</button>
							{{ entriesById.get(link.copy_id)?.title ?? '' }} ·
						</template>
						{{ place(link) }}
					</p>
					<p v-if="link.note" class="link-list__note">
						{{ link.note }}
					</p>
					<p class="link-list__meta">
						<span class="link-list__circle">{{ circleLabel(link) }}</span>
						· {{ t('notenarchiv', 'Valid until {date}', { date: formatDate(link.expires ?? '') }) }}
						· {{ t('notenarchiv', 'created {date}', { date: formatDateTime(link.created) }) }}
						· {{ n('notenarchiv', 'opened %n time', 'opened %n times', link.hits) }}
						<template v-if="link.tempo">
							· {{ t('notenarchiv', 'tempo {percent} %', { percent: link.tempo }) }}
						</template>
						<template v-if="link.scan_file_id">
							· {{ t('notenarchiv', 'with scan (PDF)') }}
						</template>
					</p>
				</div>
				<div class="link-list__actions no-print">
					<NcButton v-if="link.token !== null"
						variant="tertiary"
						:aria-label="t('notenarchiv', 'Copy link')"
						@click="copy(link)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentCopy" />
						</template>
					</NcButton>
					<NcButton v-if="canRevoke(link)"
						variant="tertiary"
						:aria-label="t('notenarchiv', 'Revoke link')"
						@click="revoking = link; error = ''">
						<template #icon>
							<NcIconSvgWrapper :path="mdiLinkOff" />
						</template>
					</NcButton>
				</div>
			</li>
		</ul>

		<ActionDialog v-if="revoking !== null"
			:name="t('notenarchiv', 'Revoke link')"
			:submit-label="t('notenarchiv', 'Revoke')"
			variant="error"
			:busy="busy"
			:error="error"
			@submit="revoke"
			@close="revoking = null">
			<p>{{ t('notenarchiv', 'The link stops working immediately for everyone. This cannot be undone.') }}</p>
			<p class="link-list__title">
				{{ place(revoking) }}
			</p>
		</ActionDialog>
	</div>
</template>

<style scoped>
.link-list__items {
	display: flex;
	flex-direction: column;
}

.link-list__item {
	display: flex;
	justify-content: space-between;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.link-list__item:last-child {
	border-bottom: none;
}

.link-list__body {
	min-width: 0;
}

.link-list__body p {
	margin: 0;
}

.link-list__title {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.link-list__copy-button {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.link-list__copy-button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.link-list__note {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.link-list__meta,
.link-list__empty {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.link-list__circle {
	font-weight: bold;
}

.link-list__actions {
	display: flex;
	flex-shrink: 0;
	align-items: flex-start;
}
</style>
