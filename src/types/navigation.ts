import type { Component } from 'vue'
import type { RouteRecordRaw } from 'vue-router'

export interface NavigationItem {
	path: string
	name: string
	displayName: string
	component: Component | (() => Promise<Component>)
	icon?: Component | null
	/** Only shown to users who can edit */
	editorOnly?: boolean
}

export interface RouteMeta {
	displayName: string
}

export type NavigationRoute = RouteRecordRaw & {
	path: string
	name: string
	component: Component | (() => Promise<Component>)
	meta: RouteMeta
}
