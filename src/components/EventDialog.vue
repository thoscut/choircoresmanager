<script setup lang="ts">
/**
 * Create or edit a concert or a song list. Song lists have a period
 * ("2025/1", "2018-Advent") instead of a date and are no proof of
 * performance.
 */
import type { ChoirEvent, EventInput, EventKind } from '../types.ts'

import { t } from '../l10n.ts'
import { reactive, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { createEvent, errorMessage, updateEvent } from '../api.ts'

const props = defineProps<{
	kind: EventKind
	/** event to edit; missing for a new one */
	event?: ChoirEvent | null
}>()

const emit = defineEmits<{
	saved: [event: ChoirEvent]
	close: []
}>()

const draft = reactive({
	title: props.event?.title ?? '',
	date: props.event?.event_date ?? '',
	location: props.event?.location ?? '',
	period: props.event?.period ?? '',
	isPublic: props.event?.is_public ?? true,
	boardAgreed: props.event?.board_agreed ?? false,
	notes: props.event?.notes ?? '',
})

const busy = ref(false)
const error = ref('')

async function save(): Promise<void> {
	if (draft.title.trim() === '') {
		error.value = t('notenarchiv', 'Please enter a title.')
		return
	}
	const input: EventInput = {
		title: draft.title.trim(),
		event_date: draft.date || null,
		location: draft.location.trim() || null,
		period: draft.period.trim() || null,
		is_public: draft.isPublic,
		board_agreed: draft.boardAgreed,
		notes: draft.notes.trim() || null,
	}
	busy.value = true
	error.value = ''
	try {
		const saved = props.event ? await updateEvent(props.event.id, input) : await createEvent(props.kind, input)
		emit('saved', saved)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="event
			? (kind === 'concert' ? t('notenarchiv', 'Edit concert') : t('notenarchiv', 'Edit song list'))
			: (kind === 'concert' ? t('notenarchiv', 'New concert') : t('notenarchiv', 'New song list'))"
		:submit-label="t('notenarchiv', 'Save')"
		:busy="busy"
		:error="error"
		@submit="save"
		@close="emit('close')">
		<NcTextField v-model="draft.title" :label="t('notenarchiv', 'Title (required)')" required />
		<div class="event-dialog__row">
			<DateField v-model="draft.date" :label="t('notenarchiv', 'Date')" />
			<NcTextField v-if="kind === 'song_list'"
				v-model="draft.period"
				:label="t('notenarchiv', 'Period, e.g. 2025/1 or 2018-Advent')" />
			<NcTextField v-else v-model="draft.location" :label="t('notenarchiv', 'Location')" />
		</div>
		<template v-if="kind === 'concert'">
			<NcCheckboxRadioSwitch v-model="draft.isPublic" type="switch">
				{{ t('notenarchiv', 'Public concert') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="draft.boardAgreed" type="switch">
				{{ t('notenarchiv', 'The board has agreed') }}
			</NcCheckboxRadioSwitch>
		</template>
		<NcTextArea v-model="draft.notes"
			:label="t('notenarchiv', 'Notes')"
			resize="vertical"
			rows="3" />
	</ActionDialog>
</template>

<style scoped>
.event-dialog__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.event-dialog__row > * {
	flex: 1 1 12em;
}
</style>
