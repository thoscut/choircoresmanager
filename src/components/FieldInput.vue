<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Input for one field, chosen by the field type from the field directory.
 * Works on a draft value: text for text/longtext/number/date ("" = empty),
 * an option key or null for choices, true/false for yes/no fields.
 */
import type { FieldInfo } from '../types.ts'

import { computed, useId } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { optionLabel, parseIsoDate, toIsoDate } from '../format.ts'

export type DraftValue = string | boolean | null

const model = defineModel<DraftValue>({ required: true })

const props = withDefaults(defineProps<{
	info: FieldInfo
	/** Translated label */
	label: string
	/** Error text; marks the input as invalid */
	error?: string
	/** Hint below the input */
	hint?: string
	/** Whether an empty value is allowed (choices get a clear button) */
	clearable?: boolean
}>(), {
	error: '',
	hint: '',
	clearable: true,
})

const helperText = computed(() => props.error || props.hint)

/** aria-invalid of the input: set only while there is an error (WCAG 3.3.1) */
const invalid = computed(() => (props.error !== '' ? 'true' : undefined))

/** id of the hint below inputs that do not link it themselves */
const hintId = useId()

/** maxlength attribute, if the server limits the length */
const maxLength = computed(() => (typeof props.info.max === 'number' && props.info.max > 0 ? props.info.max : undefined))

const text = computed({
	get: () => (typeof model.value === 'string' ? model.value : ''),
	set: (value: string | number) => {
		model.value = String(value)
	},
})

const checked = computed({
	get: () => model.value === true,
	set: (value: boolean) => {
		model.value = value
	},
})

const date = computed({
	get: () => (typeof model.value === 'string' ? parseIsoDate(model.value) : null),
	set: (value: Date | null) => {
		model.value = value ? toIsoDate(value) : ''
	},
})

interface Option {
	id: string
	label: string
}

const options = computed<Option[]>(() => Object.keys(props.info.options ?? {})
	.map((key) => ({ id: key, label: optionLabel(props.info, key) })))

const choice = computed({
	get: () => (typeof model.value === 'string' && model.value !== '' ? model.value : null),
	set: (value: string | null) => {
		model.value = value
	},
})

/**
 * NcSelect works on option objects; the draft stores the key.
 *
 * @param option - selected option
 */
function reduceId(option: Option): string {
	return option.id
}
</script>

<template>
	<div class="field-input">
		<NcTextArea v-if="info.type === 'longtext'"
			v-model="text"
			:label="label"
			:aria-invalid="invalid"
			:error="error !== ''"
			:helper-text="helperText"
			resize="vertical"
			rows="4"
			:maxlength="maxLength" />

		<NcTextField v-else-if="info.type === 'number'"
			v-model="text"
			type="number"
			inputmode="numeric"
			step="1"
			:label="label"
			:aria-invalid="invalid"
			:error="error !== ''"
			:helper-text="helperText" />

		<template v-else-if="info.type === 'date'">
			<NcDateTimePickerNative v-model="date"
				type="date"
				:label="label"
				:aria-invalid="invalid"
				:aria-describedby="helperText ? hintId : undefined" />
			<p v-if="helperText"
				:id="hintId"
				class="field-input__hint"
				:class="{ 'field-input__hint--error': error !== '' }">
				{{ helperText }}
			</p>
		</template>

		<NcSelect v-else-if="info.type === 'choice'"
			v-model="choice"
			:input-label="label"
			:options="options"
			:reduce="reduceId"
			:clearable="clearable"
			:error="error !== ''"
			:helper-text="helperText" />

		<template v-else-if="info.type === 'boolean'">
			<NcCheckboxRadioSwitch v-model="checked" type="switch">
				{{ label }}
				<!-- linked to the switch with aria-describedby -->
				<template v-if="helperText" #description>
					<span :class="{ 'field-input__hint--error': error !== '' }">{{ helperText }}</span>
				</template>
			</NcCheckboxRadioSwitch>
		</template>

		<NcTextField v-else
			v-model="text"
			:label="label"
			:maxlength="maxLength"
			:aria-invalid="invalid"
			:error="error !== ''"
			:helper-text="helperText" />
	</div>
</template>

<style scoped>
.field-input__hint {
	margin: var(--default-grid-baseline) 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.field-input__hint--error {
	color: var(--color-error-text, var(--color-error));
}
</style>
