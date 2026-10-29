<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Create or edit a person of the register. The fields come from
 * `meta.personFields`. Changing name, GND, Wikidata, life dates or the
 * identification removes the mark "checked"; the server records every
 * change.
 */
import type { DraftValue } from './FieldInput.vue'
import type { FieldInfo, FieldValue, Person } from '../types.ts'

import { t } from '../l10n.ts'
import { computed, reactive, ref } from 'vue'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import FieldInput from './FieldInput.vue'
import { createPerson, errorMessage, updatePerson } from '../api.ts'
import { translateLabel } from '../format.ts'
import { meta } from '../store.ts'

const props = defineProps<{
	/** person to edit; missing for a new person */
	person?: Person | null
	/** name to start with (new person from a search) */
	initialName?: string
}>()

const emit = defineEmits<{
	saved: [person: Person]
	close: []
}>()

/** Writable fields of a person, in the order of the server. */
const fields = computed(() => Object.entries(meta.value?.personFields ?? {})
	.filter(([, info]) => info.write.some((role) => meta.value?.roles.includes(role)))
	.map(([key, info]) => ({ key, info })))

/**
 * Value of the person as draft value.
 *
 * @param info - field info
 * @param value - stored value
 */
function toDraft(info: FieldInfo, value: unknown): DraftValue {
	if (info.type === 'boolean') {
		return value === true
	}
	if (value === null || value === undefined) {
		return info.type === 'choice' ? null : ''
	}
	return String(value)
}

const draft = reactive<Record<string, DraftValue>>({})
for (const { key, info } of fields.value) {
	draft[key] = toDraft(info, props.person ? (props.person as unknown as Record<string, unknown>)[key] : (key === 'name' ? props.initialName ?? '' : null))
}

const reason = ref('')
const busy = ref(false)
const error = ref('')

/**
 * Draft value as value for the server.
 *
 * @param info - field info
 * @param value - draft value
 */
function fromDraft(info: FieldInfo, value: DraftValue): FieldValue {
	if (info.type === 'boolean') {
		return value === true
	}
	if (value === null || value === false || (typeof value === 'string' && value.trim() === '')) {
		return null
	}
	return String(value).trim()
}

async function save(): Promise<void> {
	if (typeof draft.name !== 'string' || draft.name.trim() === '') {
		error.value = t('cantoria', 'Please enter a name.')
		return
	}
	const values: Record<string, FieldValue> = {}
	for (const { key, info } of fields.value) {
		const value = fromDraft(info, draft[key])
		const before = props.person ? fromDraft(info, toDraft(info, (props.person as unknown as Record<string, unknown>)[key])) : null
		if (!props.person || value !== before) {
			values[key] = value
		}
	}
	busy.value = true
	error.value = ''
	try {
		const saved = props.person
			? await updatePerson(props.person.id, values, reason.value.trim() === '' ? null : reason.value.trim())
			: await createPerson(values)
		emit('saved', saved)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="person ? t('cantoria', 'Edit person') : t('cantoria', 'New person')"
		:submit-label="t('cantoria', 'Save')"
		:busy="busy"
		:error="error"
		size="large"
		@submit="save"
		@close="emit('close')">
		<p class="action-dialog__hint">
			{{ t('cantoria', 'Dates as YYYY-MM-DD or YYYY (negative before Christ). Changing name, GND, Wikidata, life dates or identification removes the mark "checked".') }}
		</p>
		<div class="person-dialog__fields">
			<FieldInput v-for="field in fields"
				:key="field.key"
				v-model="draft[field.key]"
				:class="{ 'person-dialog__wide': field.info.type === 'longtext' || field.key === 'name' || field.key === 'source' }"
				:info="field.info"
				:label="translateLabel(field.info.label)"
				:clearable="field.key !== 'identification'" />
		</div>
		<NcTextField v-if="person"
			v-model="reason"
			:label="t('cantoria', 'Reason for the change (optional)')" />
	</ActionDialog>
</template>

<style scoped>
/* Two columns, so that each date sits beside its precision; dense: the
   identification fills the gap next to the life dates as found */
.person-dialog__fields {
	display: grid;
	grid-auto-flow: row dense;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 20em), 1fr));
	gap: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

/* the selects fill their column like the text fields */
.person-dialog__fields :deep(.v-select.select) {
	width: 100%;
	min-width: 0;
	max-width: none;
}

.person-dialog__wide {
	grid-column: 1 / -1;
}
</style>
