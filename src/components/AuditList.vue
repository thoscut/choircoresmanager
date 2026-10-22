<script setup lang="ts">
/**
 * Audit log of one copy with its version and work: who, when, which field,
 * old -> new, reason. Newest first.
 */
import type { AuditEntry, Level } from '../types.ts'

import { mdiHistory } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { displayAuditValue, formatDateTime } from '../format.ts'
import { fieldInfo, fieldLabel } from '../store.ts'
import { LEVELS } from '../types.ts'

const PAGE = 50

const props = defineProps<{
	audit: AuditEntry[]
}>()

const limit = ref(PAGE)

const sorted = computed(() => [...props.audit].sort((a, b) => b.time - a.time || b.id - a.id))
const shown = computed(() => sorted.value.slice(0, limit.value))

function isLevel(value: string): value is Level {
	return (LEVELS as string[]).includes(value)
}

/**
 * Translated name of the changed object type.
 *
 * @param type - object type from the log
 */
function objectLabel(type: string): string {
	switch (type) {
	case 'work':
		return t('notenarchiv', 'Work')
	case 'version':
		return t('notenarchiv', 'Version')
	case 'copy':
		return t('notenarchiv', 'Copy')
	case 'file':
		return t('notenarchiv', 'File')
	default:
		return type
	}
}

/**
 * Translated name of a logged action.
 *
 * @param action - action key from the log
 */
function actionLabel(action: string): string {
	switch (action) {
	case 'created':
		return t('notenarchiv', 'Created')
	case 'changed':
		return t('notenarchiv', 'Changed')
	case 'import':
		return t('notenarchiv', 'Import')
	case 'restore':
		return t('notenarchiv', 'Restore')
	case 'not_found':
		return t('notenarchiv', 'File not found')
	case 'file_sync':
		return t('notenarchiv', 'File sync')
	case 'practice_audio':
		return t('notenarchiv', 'Practice files made')
	default:
		return action
	}
}

/**
 * What a practice audio export contained, from its JSON record:
 * "2-2 · Measures 1 to 4 · Tenor 1 · Focus voice louder · WAV · 90 % · with repeats".
 *
 * @param value - new_value of the log entry
 */
function practiceAudioText(value: string): string {
	let details: Record<string, unknown>
	try {
		details = JSON.parse(value)
	} catch {
		return value
	}
	if (details === null || typeof details !== 'object') {
		return value
	}
	const modes: Record<string, string> = {
		all: t('notenarchiv', 'All equal'),
		focus: t('notenarchiv', 'Focus voice louder'),
		solo: t('notenarchiv', 'Focus voice only'),
	}
	const [from, to] = String(details.measures ?? '').split('-')
	const voice = details.voice === '*'
		? t('notenarchiv', 'Every voice and a tutti (ZIP)')
		: (typeof details.voice === 'string' && details.voice !== '' ? details.voice : t('notenarchiv', 'Tutti'))
	return [
		String(details.copy ?? ''),
		t('notenarchiv', 'Measures {from} to {to}', { from: from ?? '', to: to ?? '' }),
		voice,
		modes[String(details.mode)] ?? String(details.mode ?? ''),
		String(details.format ?? '').toUpperCase(),
		typeof details.tempo === 'number' ? `${details.tempo} %` : '',
		details.repeats === false ? t('notenarchiv', 'without repeats') : t('notenarchiv', 'with repeats'),
	].filter((part) => part !== '').join(' · ')
}

/**
 * What was changed: "Version: Rights status" or the action.
 *
 * @param entry - log entry
 */
function subject(entry: AuditEntry): string {
	const object = objectLabel(entry.object_type)
	if (entry.field && isLevel(entry.object_type)) {
		return `${object}: ${fieldLabel(entry.object_type, entry.field)}`
	}
	if (entry.field) {
		return `${object}: ${entry.field}`
	}
	return `${object}: ${actionLabel(entry.action)}`
}

/**
 * A logged value for display.
 *
 * @param entry - log entry
 * @param value - old or new value
 */
function value(entry: AuditEntry, value: string | null): string {
	const info = entry.field && isLevel(entry.object_type) ? fieldInfo(entry.object_type, entry.field) : undefined
	return displayAuditValue(info, value)
}

function who(entry: AuditEntry): string {
	return entry.user_name || entry.user_id || t('notenarchiv', 'System')
}
</script>

<template>
	<div class="audit-list">
		<NcEmptyContent v-if="audit.length === 0"
			:name="t('notenarchiv', 'No changes yet')"
			:description="t('notenarchiv', 'Every change is recorded here: who, when, old and new value, reason.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiHistory" />
			</template>
		</NcEmptyContent>

		<ol v-else class="audit-list__items">
			<li v-for="entry in shown" :key="entry.id" class="audit-list__item">
				<div class="audit-list__head">
					<strong :title="entry.user_id ?? undefined">{{ who(entry) }}</strong>
					<time :datetime="new Date(entry.time * 1000).toISOString()">{{ formatDateTime(entry.time) }}</time>
				</div>
				<div class="audit-list__subject">
					{{ subject(entry) }}
				</div>
				<div v-if="entry.field && (entry.old_value !== null || entry.new_value !== null)" class="audit-list__change">
					<span class="audit-list__old">{{ value(entry, entry.old_value) || t('notenarchiv', '(empty)') }}</span>
					<span aria-hidden="true"> → </span>
					<span class="hidden-visually">{{ t('notenarchiv', 'changed to') }}</span>
					<span class="audit-list__new">{{ value(entry, entry.new_value) || t('notenarchiv', '(empty)') }}</span>
				</div>
				<div v-else-if="entry.new_value" class="audit-list__change">
					{{ entry.action === 'practice_audio' ? practiceAudioText(entry.new_value) : entry.new_value }}
				</div>
				<div v-if="entry.reason" class="audit-list__reason">
					{{ t('notenarchiv', 'Reason: {reason}', { reason: entry.reason }) }}
				</div>
			</li>
		</ol>

		<NcButton v-if="sorted.length > limit" variant="tertiary" @click="limit += PAGE">
			{{ n('notenarchiv', 'Show %n more entry', 'Show %n more entries', Math.min(PAGE, sorted.length - limit)) }}
		</NcButton>
	</div>
</template>

<style scoped>
.audit-list {
	padding-block: calc(2 * var(--default-grid-baseline));
}

.audit-list__items {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.audit-list__item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.audit-list__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.audit-list__head time {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.audit-list__change,
.audit-list__reason {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.audit-list__old {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.audit-list__reason {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
