// Minimal type declarations for the parts of the Verovio toolkit used by the MusicXML player

declare module 'verovio/wasm' {
	export default function createVerovioModule(moduleArg?: Record<string, unknown>): Promise<unknown>
}

declare module 'verovio/esm' {
	export interface VerovioTimemapEntry {
		tstamp: number
		qstamp: number
		tempo?: number
		on?: string[]
		off?: string[]
		restsOn?: string[]
		restsOff?: string[]
		/** Id of the measure starting at this time, with the timemap option includeMeasures */
		measureOn?: string
	}

	export interface VerovioElementsAtTime {
		page: number
		notes: string[]
		chords: string[]
		rests: string[]
		measure: string
	}

	export interface VerovioMidiValues {
		pitch: number
		time: number
		duration: number
	}

	export class VerovioToolkit {
		constructor(module: unknown)
		destroy(): void
		setOptions(options: Record<string, unknown>): void
		loadData(data: string): boolean
		loadZipDataBuffer(data: ArrayBuffer): boolean
		getPageCount(): number
		redoLayout(options?: Record<string, unknown>): void
		renderToSVG(pageNo?: number, xmlDeclaration?: boolean): string
		renderToTimemap(options?: Record<string, unknown>): VerovioTimemapEntry[]
		getMEI(options?: Record<string, unknown>): string
		getMIDIValuesForElement(xmlId: string): VerovioMidiValues
		getElementsAtTime(millisec: number): VerovioElementsAtTime
		getTimeForElement(xmlId: string): number
		getPageWithElement(xmlId: string): number
		getLog(): string
	}
}
