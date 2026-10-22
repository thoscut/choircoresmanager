<script setup lang="ts">
/**
 * Persons involved in the version (composer, lyricist, arranger, …) and
 * the protection proposal derived from their life dates. The proposal is
 * display only: it never changes the rights status and is no legal advice.
 */
import type { Contribution, Detail, Person } from '../types.ts'

import { mdiAccountPlusOutline, mdiAlertOutline, mdiCheckCircleOutline, mdiDeleteOutline, mdiHelpCircleOutline, mdiLockOutline, mdiPencilOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ActionDialog from './ActionDialog.vue'
import PersonDialog from './PersonDialog.vue'
import { addContribution, errorMessage, fetchPersons, removeContribution, updateContribution } from '../api.ts'
import { formatDate } from '../format.ts'
import { contributionRoleLabel, lifeDates, personOptionLabel, protectionStatusLabel } from '../labels.ts'
import { navigate, selectedPersonId } from '../navigation.ts'
import { isLibrarian, meta } from '../store.ts'

const props = defineProps<{
	detail: Detail
}>()

const emit = defineEmits<{
	saved: []
}>()

const protection = computed(() => props.detail.protection)
const contributions = computed(() => props.detail.contributions)

const protectionIcon = computed(() => {
	switch (protection.value?.status) {
	case 'free':
		return mdiCheckCircleOutline
	case 'protected':
		return mdiLockOutline
	default:
		return mdiHelpCircleOutline
	}
})

/**
 * Open the person in the register.
 *
 * @param personId - person id
 */
function showPerson(personId: number): void {
	selectedPersonId.value = personId
	navigate('persons')
}

// ---- add, edit, remove ----

interface Option {
	id: string
	label: string
}

const roleOptions = computed<Option[]>(() => Object.keys(meta.value?.contributionRoles ?? {}).map((role) => ({ id: role, label: contributionRoleLabel(role) })))

const dialog = ref<'add' | 'edit' | 'remove' | null>(null)
const editing = ref<Contribution | null>(null)
const persons = ref<Person[]>([])
const personOption = ref<{ id: number, label: string } | null>(null)
const roleOption = ref<Option | null>(null)
const textForComposition = ref(false)
const busy = ref(false)
const error = ref('')
const creatingPerson = ref(false)

const personOptions = computed(() => persons.value
	.map((person) => ({ id: person.id, label: `${person.name} (${lifeDates(person)})` }))
	.sort((a, b) => a.label.localeCompare(b.label)))

async function startAdd(): Promise<void> {
	dialog.value = 'add'
	editing.value = null
	personOption.value = null
	roleOption.value = roleOptions.value.find((option) => option.id === 'composer') ?? roleOptions.value[0] ?? null
	textForComposition.value = false
	error.value = ''
	try {
		persons.value = await fetchPersons('')
	} catch (caught) {
		error.value = errorMessage(caught)
	}
}

/**
 * @param contribution - contribution to edit
 */
function startEdit(contribution: Contribution): void {
	dialog.value = 'edit'
	editing.value = contribution
	roleOption.value = roleOptions.value.find((option) => option.id === contribution.role) ?? null
	textForComposition.value = contribution.text_for_composition === true
	error.value = ''
}

/**
 * @param contribution - contribution to remove
 */
function startRemove(contribution: Contribution): void {
	dialog.value = 'remove'
	editing.value = contribution
	error.value = ''
}

/**
 * A new person was created from the dialog: choose it.
 *
 * @param person - the new person
 */
function onPersonCreated(person: Person): void {
	creatingPerson.value = false
	persons.value = [...persons.value, person]
	personOption.value = { id: person.id, label: `${person.name} (${lifeDates(person)})` }
}

async function submit(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		if (dialog.value === 'add') {
			if (!personOption.value || !roleOption.value) {
				error.value = t('notenarchiv', 'Please choose a person and a role.')
				return
			}
			await addContribution(Number(props.detail.version.id), personOption.value.id, roleOption.value.id, textForComposition.value)
			showSuccess(t('notenarchiv', 'The person has been added.'))
		} else if (dialog.value === 'edit' && editing.value) {
			await updateContribution(editing.value.id, { role: roleOption.value?.id ?? editing.value.role, text_for_composition: textForComposition.value })
			showSuccess(t('notenarchiv', 'The contribution has been changed.'))
		} else if (dialog.value === 'remove' && editing.value) {
			await removeContribution(editing.value.id)
			showSuccess(t('notenarchiv', 'The contribution has been removed.'))
		}
		dialog.value = null
		emit('saved')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

const dialogTitle = computed(() => {
	switch (dialog.value) {
	case 'add':
		return t('notenarchiv', 'Add a person')
	case 'edit':
		return t('notenarchiv', 'Change the contribution')
	default:
		return t('notenarchiv', 'Remove the contribution')
	}
})
</script>

<template>
	<section class="contributions" aria-labelledby="contributions-heading">
		<h3 id="contributions-heading">
			{{ t('notenarchiv', 'Persons involved and protection') }}
		</h3>

		<div v-if="protection" class="contributions__protection" :class="`contributions__protection--${protection.status}`">
			<p class="contributions__status">
				<NcIconSvgWrapper :path="protectionIcon" :size="20" inline />
				<strong>{{ protectionStatusLabel(protection.status) }}</strong>
			</p>
			<p v-if="protection.reasonText">
				{{ protection.reasonText }}
			</p>
			<ul v-if="protection.blocking.length > 0" class="contributions__blocking">
				<li v-for="blocker in protection.blocking" :key="blocker.personId">
					<NcIconSvgWrapper :path="mdiAlertOutline" :size="18" inline />
					<span>{{ blocker.reasonText || blocker.name }}</span>
				</li>
			</ul>
			<p class="contributions__notice">
				{{ protection.notice }}
			</p>
		</div>

		<p v-if="contributions.length === 0" class="contributions__muted">
			{{ t('notenarchiv', 'No persons are recorded for this version.') }}
		</p>
		<ul v-else class="contributions__list">
			<li v-for="contribution in contributions" :key="contribution.id" class="contributions__item">
				<div class="contributions__body">
					<p class="contributions__name">
						<button v-if="contribution.person"
							type="button"
							class="contributions__person"
							@click="showPerson(contribution.person_id)">
							{{ contribution.person.name }}
						</button>
						<span v-if="contribution.person" class="contributions__muted">({{ lifeDates(contribution.person) }})</span>
					</p>
					<p class="contributions__muted">
						{{ contributionRoleLabel(contribution.role) }}
						<template v-if="contribution.text_for_composition">
							· {{ t('notenarchiv', 'Text written for this composition') }}
						</template>
					</p>
					<p v-if="contribution.person" class="contributions__muted">
						{{ personOptionLabel('identification', contribution.person.identification) }}
						<template v-if="contribution.person.checked_at">
							· {{ t('notenarchiv', 'checked on {date}', { date: formatDate(contribution.person.checked_at) }) }}
						</template>
						<template v-else>
							· {{ t('notenarchiv', 'not checked') }}
						</template>
					</p>
				</div>
				<div v-if="isLibrarian" class="contributions__actions">
					<NcButton variant="tertiary"
						:aria-label="t('notenarchiv', 'Change the contribution of {name}', { name: contribution.person?.name ?? '' })"
						@click="startEdit(contribution)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencilOutline" />
						</template>
					</NcButton>
					<NcButton variant="tertiary"
						:aria-label="t('notenarchiv', 'Remove {name} from this version', { name: contribution.person?.name ?? '' })"
						@click="startRemove(contribution)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
					</NcButton>
				</div>
			</li>
		</ul>
		<div v-if="isLibrarian" class="contributions__buttons">
			<NcButton @click="startAdd">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAccountPlusOutline" />
				</template>
				{{ t('notenarchiv', 'Add a person') }}
			</NcButton>
		</div>

		<ActionDialog v-if="dialog !== null"
			:name="dialogTitle"
			:submit-label="dialog === 'remove' ? t('notenarchiv', 'Remove') : t('notenarchiv', 'Save')"
			:variant="dialog === 'remove' ? 'error' : 'primary'"
			:busy="busy"
			:error="error"
			@submit="submit"
			@close="dialog = null">
			<template v-if="dialog === 'remove'">
				<p>{{ t('notenarchiv', 'Remove {name} from this version? The person stays in the register.', { name: editing?.person?.name ?? '' }) }}</p>
			</template>
			<template v-else>
				<template v-if="dialog === 'add'">
					<NcSelect v-model="personOption"
						:input-label="t('notenarchiv', 'Person')"
						:options="personOptions" />
					<NcButton variant="tertiary" @click="creatingPerson = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAccountPlusOutline" />
						</template>
						{{ t('notenarchiv', 'Person not in the register: create') }}
					</NcButton>
				</template>
				<NcSelect v-model="roleOption"
					:input-label="t('notenarchiv', 'Role')"
					:options="roleOptions"
					:clearable="false"
					:searchable="false" />
				<NcCheckboxRadioSwitch v-model="textForComposition" type="switch">
					{{ t('notenarchiv', 'Text written for this composition') }}
				</NcCheckboxRadioSwitch>
				<p class="action-dialog__hint">
					{{ t('notenarchiv', 'Documents § 65 (3) of the German copyright act; it does not change the proposal.') }}
				</p>
			</template>
		</ActionDialog>

		<PersonDialog v-if="creatingPerson"
			@saved="onPersonCreated"
			@close="creatingPerson = false" />
	</section>
</template>

<style scoped>
.contributions {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.contributions h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.contributions p {
	margin: 0;
}

.contributions__protection {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-inline-start-width: 4px;
	border-radius: var(--border-radius-large, 8px);
}

.contributions__protection--free {
	border-inline-start-color: var(--color-success-text, var(--color-success));
}

.contributions__protection--protected {
	border-inline-start-color: var(--color-error-text, var(--color-error));
}

.contributions__protection--unknown {
	border-inline-start-color: var(--color-warning-text, var(--color-warning));
}

.contributions__status {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.contributions__blocking li {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.contributions__blocking :deep(.icon-vue) {
	flex-shrink: 0;
	color: var(--color-warning-text, var(--color-warning));
}

.contributions__notice,
.contributions__muted {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.contributions__list {
	display: flex;
	flex-direction: column;
}

.contributions__item {
	display: flex;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	padding-block: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
}

.contributions__item:last-child {
	border-bottom: none;
}

.contributions__body {
	min-width: 0;
}

.contributions__name {
	display: flex;
	flex-wrap: wrap;
	gap: 0 var(--default-grid-baseline);
	align-items: baseline;
}

.contributions__person {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-align: start;
	text-decoration: underline;
	cursor: pointer;
}

.contributions__person:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.contributions__actions {
	display: flex;
	flex-shrink: 0;
}

.contributions__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}
</style>
