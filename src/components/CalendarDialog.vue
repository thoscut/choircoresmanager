<script setup lang="ts">
/**
 * Add a concert, or all upcoming concerts, to one of the user's Nextcloud
 * calendars. The user picks a calendar that can take new entries; entries
 * that are already in one of the user's calendars are updated there instead
 * of being added twice (the server finds them, the browser writes them with
 * the user's own session). The result is reported to the view, which shows
 * it in a status message.
 */
import type { CalendarMessage } from '../calendar.ts'
import type { UserCalendar } from '../types.ts'

import { t } from '../l10n.ts'
import { onMounted, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ActionDialog from './ActionDialog.vue'
import { addToCalendar, errorMessage, fetchCalendars, writeCalendarObject } from '../api.ts'
import { calendarMessage } from '../calendar.ts'

const props = defineProps<{
	/** the concert, or null for all upcoming concerts */
	eventId: number | null
	/** add the preparation deadlines as all-day entries */
	deadlines: boolean
}>()

const emit = defineEmits<{
	done: [message: CalendarMessage]
	close: []
}>()

const calendars = ref<UserCalendar[]>([])
const chosen = ref('')
const loading = ref(true)
const busy = ref(false)
const error = ref('')

onMounted(async () => {
	try {
		calendars.value = await fetchCalendars()
		chosen.value = calendars.value[0]?.uri ?? ''
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
})

async function submit(): Promise<void> {
	if (chosen.value === '') {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const result = await addToCalendar(props.eventId, chosen.value, props.deadlines)
		let updated = 0
		let failed = 0
		for (const update of result.updates) {
			try {
				await writeCalendarObject(update.path, update.ics)
				updated++
			} catch {
				failed++
			}
		}
		emit('done', calendarMessage(result, updated, failed))
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="eventId === null ? t('notenarchiv', 'Add upcoming concerts to my calendar') : t('notenarchiv', 'Add to my calendar')"
		:submit-label="t('notenarchiv', 'Add to calendar')"
		:busy="busy"
		:submit-disabled="loading || chosen === ''"
		:error="error"
		@submit="submit"
		@close="emit('close')">
		<p v-if="loading" class="calendar-dialog__loading">
			<NcLoadingIcon :size="20" />
			{{ t('notenarchiv', 'Loading your calendars …') }}
		</p>
		<NcNoteCard v-else-if="calendars.length === 0 && error === ''" type="info">
			{{ t('notenarchiv', 'You have no calendar that can take new entries. Create one in the Calendar app, or download the calendar file instead.') }}
		</NcNoteCard>
		<fieldset v-else-if="calendars.length > 0" class="calendar-dialog__calendars">
			<legend>{{ t('notenarchiv', 'Calendar') }}</legend>
			<NcCheckboxRadioSwitch v-for="calendar in calendars"
				:key="calendar.uri"
				v-model="chosen"
				:value="calendar.uri"
				type="radio"
				name="calendar-dialog-calendar">
				<span class="calendar-dialog__calendar">
					<span v-if="calendar.color"
						class="calendar-dialog__color"
						:style="{ backgroundColor: calendar.color }"
						aria-hidden="true" />
					{{ calendar.name }}
				</span>
			</NcCheckboxRadioSwitch>
		</fieldset>
		<p class="action-dialog__hint">
			{{ deadlines
				? t('notenarchiv', 'The preparation deadlines (10, 8 and 6 weeks before) are added as separate all-day entries.')
				: t('notenarchiv', 'Only the concerts are added, without the preparation deadlines.') }}
		</p>
		<p class="action-dialog__hint">
			{{ t('notenarchiv', 'An entry that is already in one of your calendars is updated there, not added twice. Changes you made to it in the calendar are replaced.') }}
		</p>
	</ActionDialog>
</template>

<style scoped>
.calendar-dialog__loading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.calendar-dialog__calendars {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	border: none;
}

.calendar-dialog__calendars legend {
	font-weight: bold;
}

.calendar-dialog__calendar {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.calendar-dialog__color {
	display: inline-block;
	width: 12px;
	height: 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 50%;
}
</style>
