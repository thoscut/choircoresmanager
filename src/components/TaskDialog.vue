<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * New question or task for a copy, addressed to a role. Measures are
 * printed numbers ("0" = pickup); the score view fills in the range and the
 * focus voice.
 */
import type { Role, TaskCategory, TaskInput, TaskKind } from '../types.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, reactive, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { createTask, errorMessage } from '../api.ts'
import { roleLabel, TASK_ADDRESSEES, TASK_CATEGORIES, TASK_KINDS, taskCategoryLabel, taskKindLabel } from '../labels.ts'

export interface TaskPrefill {
	page?: number | null
	measureFrom?: string | null
	measureTo?: string | null
	voice?: string | null
	verse?: number | null
}

const props = defineProps<{
	copyId: number
	shelfmark: string
	prefill?: TaskPrefill | null
}>()

const emit = defineEmits<{
	created: []
	close: []
}>()

const draft = reactive({
	kind: 'question' as TaskKind,
	addressee: 'conductor' as Role,
	question: '',
	page: props.prefill?.page ? String(props.prefill.page) : '',
	measureFrom: props.prefill?.measureFrom ?? '',
	measureTo: props.prefill?.measureTo ?? '',
	voice: props.prefill?.voice ?? '',
	verse: props.prefill?.verse ? String(props.prefill.verse) : '',
	category: null as TaskCategory | null,
	origin: '',
	due: '',
})

const busy = ref(false)
const error = ref('')

interface Option<T> {
	id: T
	label: string
}

const addresseeOptions = computed<Option<Role>[]>(() => TASK_ADDRESSEES.map((role) => ({ id: role, label: roleLabel(role) })))
const categoryOptions = computed<Option<TaskCategory>[]>(() => TASK_CATEGORIES.map((category) => ({ id: category, label: taskCategoryLabel(category) })))

const addressee = computed({
	get: () => addresseeOptions.value.find((option) => option.id === draft.addressee) ?? null,
	set: (option: Option<Role> | null) => {
		draft.addressee = option?.id ?? 'conductor'
	},
})

const category = computed({
	get: () => categoryOptions.value.find((option) => option.id === draft.category) ?? null,
	set: (option: Option<TaskCategory> | null) => {
		draft.category = option?.id ?? null
	},
})

const MEASURE = /^[0-9A-Za-z.]{1,16}$/

/**
 * Positive whole number or null; undefined if invalid.
 *
 * @param value - input
 */
function positive(value: string): number | null | undefined {
	if (value.trim() === '') {
		return null
	}
	const number = Number(value)
	return Number.isInteger(number) && number > 0 ? number : undefined
}

async function save(): Promise<void> {
	if (draft.question.trim() === '') {
		error.value = t('cantoria', 'Please describe the question or task.')
		return
	}
	const page = positive(draft.page)
	const verse = positive(draft.verse)
	if (page === undefined || verse === undefined) {
		error.value = t('cantoria', 'Page and verse must be whole numbers.')
		return
	}
	for (const measure of [draft.measureFrom, draft.measureTo]) {
		if (measure.trim() !== '' && !MEASURE.test(measure.trim())) {
			error.value = t('cantoria', 'Measure numbers may only contain digits, letters and dots.')
			return
		}
	}
	const task: TaskInput = {
		kind: draft.kind,
		addressee: draft.addressee,
		question: draft.question.trim(),
		page,
		verse,
		measure_from: draft.measureFrom.trim() || null,
		measure_to: draft.measureTo.trim() || null,
		voice: draft.voice.trim() || null,
		category: draft.category,
		origin: draft.origin.trim() || null,
		due: draft.due || null,
	}
	busy.value = true
	error.value = ''
	try {
		await createTask(props.copyId, task)
		showSuccess(t('cantoria', 'The task has been created.'))
		emit('created')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="t('cantoria', 'New task for {shelfmark}', { shelfmark })"
		:submit-label="t('cantoria', 'Create task')"
		:busy="busy"
		:error="error"
		size="large"
		@submit="save"
		@close="emit('close')">
		<fieldset class="task-dialog__kinds">
			<legend>{{ t('cantoria', 'Kind') }}</legend>
			<NcCheckboxRadioSwitch v-for="kind in TASK_KINDS"
				:key="kind"
				v-model="draft.kind"
				type="radio"
				:value="kind"
				name="task-kind">
				{{ taskKindLabel(kind) }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcSelect v-model="addressee"
			:input-label="t('cantoria', 'For the role')"
			:options="addresseeOptions"
			:clearable="false"
			:searchable="false" />
		<NcTextArea v-model="draft.question"
			:label="t('cantoria', 'Question or task (required)')"
			required
			resize="vertical"
			rows="4" />
		<fieldset class="task-dialog__place">
			<legend>{{ t('cantoria', 'Place in the score') }}</legend>
			<div class="task-dialog__row">
				<NcTextField v-model="draft.page"
					type="number"
					min="1"
					:label="t('cantoria', 'Page')" />
				<NcTextField v-model="draft.measureFrom" :label="t('cantoria', 'From measure')" />
				<NcTextField v-model="draft.measureTo" :label="t('cantoria', 'To measure')" />
			</div>
			<div class="task-dialog__row">
				<NcTextField v-model="draft.voice" :label="t('cantoria', 'Voice')" />
				<NcTextField v-model="draft.verse"
					type="number"
					min="1"
					:label="t('cantoria', 'Verse')" />
			</div>
			<p class="action-dialog__hint">
				{{ t('cantoria', 'Measures as printed; the pickup is measure 0.') }}
			</p>
		</fieldset>
		<div class="task-dialog__row">
			<NcSelect v-model="category"
				:input-label="t('cantoria', 'Category (optional)')"
				:options="categoryOptions"
				:searchable="false" />
			<DateField v-model="draft.due" :label="t('cantoria', 'Due date (optional)')" />
		</div>
		<NcTextField v-model="draft.origin" :label="t('cantoria', 'Origin (optional), e.g. transcription round 37')" />
	</ActionDialog>
</template>

<style scoped>
.task-dialog__kinds,
.task-dialog__place {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	border: none;
}

.task-dialog__kinds {
	flex-direction: row;
	flex-wrap: wrap;
	gap: 0 calc(4 * var(--default-grid-baseline));
}

.task-dialog__kinds legend,
.task-dialog__place legend {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
}

.task-dialog__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.task-dialog__row > * {
	flex: 1 1 10em;
}
</style>
