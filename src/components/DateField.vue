<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Date input (value YYYY-MM-DD, "" = empty) or time input (value HH:MM) in
 * the look of the other text fields. NcTextField renders the native input
 * of the browser, which is accessible and works with the keyboard; its type
 * definitions only do not list "date" and "time". Other attributes (min,
 * max, required, helper-text, …) are passed on to the input.
 */
import { computed } from 'vue'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const model = defineModel<string>({ required: true })

const props = withDefaults(defineProps<{
	label: string
	/** 'time' for a time of day */
	type?: 'date' | 'time'
}>(), {
	type: 'date',
})

/** The input type, outside the typed props of NcTextField. */
const inputType = computed<Record<string, string>>(() => ({ type: props.type }))
</script>

<template>
	<NcTextField v-model="model" v-bind="inputType" :label="label" />
</template>
