<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Choose Nextcloud groups for a role. The groups are searched through the
 * app (`GET /api/settings/groups`): Nextcloud's own list of groups needs
 * further administration privileges, which the groups given the settings
 * page do not have.
 */
import type { GroupOption } from '../../types.ts'

import { t } from '../../l10n.ts'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { errorMessage, searchGroups } from '../../api.ts'

const props = withDefaults(defineProps<{
	/** id of the input, for the label */
	inputId: string
	/** visible label */
	label: string
	/** explanation below the field, read with it */
	description?: string
	/** display names of the groups chosen so far */
	names?: Record<string, string>
	disabled?: boolean
}>(), {
	description: '',
	names: () => ({}),
	disabled: false,
})

/** The chosen group ids. */
const model = defineModel<string[]>({ required: true })

/** Groups found so far, by id */
const found = ref<Record<string, GroupOption>>({})
const loading = ref(false)
const searchError = ref('')

const selected = computed<GroupOption[]>(() => model.value.map((id) => found.value[id] ?? { id, displayName: props.names[id] ?? id }))
const options = computed<GroupOption[]>(() => Object.values(found.value).filter((group) => !model.value.includes(group.id)))

let request = 0
let timer: ReturnType<typeof setTimeout> | undefined

/**
 * Search the groups.
 *
 * @param search - part of the id or the display name
 */
async function load(search: string): Promise<void> {
	const current = ++request
	loading.value = true
	try {
		const groups = await searchGroups(search)
		if (current === request) {
			found.value = { ...found.value, ...Object.fromEntries(groups.map((group) => [group.id, group])) }
			searchError.value = ''
		}
	} catch (error) {
		if (current === request) {
			searchError.value = errorMessage(error)
		}
	} finally {
		if (current === request) {
			loading.value = false
		}
	}
}

/**
 * Search while typing, after a short pause.
 *
 * @param search - text typed so far
 */
function onSearch(search: string): void {
	clearTimeout(timer)
	timer = setTimeout(() => load(search), 250)
}

/**
 * Groups match by display name and id.
 *
 * @param option - a group
 * @param label - its display name
 * @param search - text typed so far
 */
function filterBy(option: GroupOption, label: string, search: string): boolean {
	return `${label} ${option.id}`.toLocaleLowerCase().includes(search.toLocaleLowerCase())
}

/**
 * The selection changed.
 *
 * @param value - chosen groups
 */
function onUpdate(value: GroupOption[] | null): void {
	model.value = (value ?? []).map((group) => group.id)
}

onMounted(() => load(''))
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
	<div class="group-select">
		<NcSelect :model-value="selected"
			:options="options"
			:input-id="inputId"
			:input-label="label"
			:helper-text="description"
			label="displayName"
			:placeholder="t('notenarchiv', 'Choose groups')"
			:filter-by="filterBy"
			:loading="loading"
			:disabled="disabled"
			keep-open
			multiple
			@search="onSearch"
			@update:model-value="onUpdate" />
		<p v-if="searchError" class="group-select__error" role="alert">
			{{ t('notenarchiv', 'The groups could not be searched: {error}', { error: searchError }) }}
		</p>
	</div>
</template>

<style scoped>
/* One width for every role, whatever the groups chosen */
.group-select {
	max-width: 40em;
}

.group-select :deep(.v-select.select) {
	width: 100%;
}

.group-select__error {
	margin: var(--default-grid-baseline) 0 0;
	color: var(--color-error-text, var(--color-error));
}
</style>
