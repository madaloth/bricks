/**
 * Bricks Choices.js Integration
 *
 * Wrapper for Choices.js library to integrate with Bricks filters and forms
 *
 * @since 2.3
 */

/**
 * Prevent Choices.js from opening a focused, closed dropdown before the browser reloads.
 *
 * Choices.js opens non-search selects for almost every keydown. Stop these events before
 * they reach its container listener, but preserve the browser action itself.
 *
 * @see https://github.com/Choices-js/Choices/issues/1431
 */
function bricksChoicesReloadGuard(event) {
	const target = event.target

	if (!(target instanceof Element) || !target.closest('.bricks-choices')) {
		return
	}

	const isReloadKey =
		event.key === 'F5' || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'r')
	const isReloadModifier = event.key === 'Control' || event.key === 'Meta'

	if (isReloadKey || isReloadModifier) {
		event.stopPropagation()
	}
}

document.addEventListener('keydown', bricksChoicesReloadGuard, true)

class BricksChoices {
	constructor(element, options = {}) {
		this.element = element
		this.isMultiple = element.multiple || false
		this.isFilterElement = element.closest('[data-brx-filter]') !== null
		this.choices = null
		this.multiplePlaceholderElement = null
		this.eventHandlers = {}
		this.listboxId = ''

		// Default Bricks options
		this.defaultOptions = {
			searchEnabled: false,
			searchPlaceholderValue: window.bricksData.i18n?.choicesjs?.search || 'Search',
			itemSelectText: '', // Disable default "Press to select" text
			removeItemButton: true,
			removeItemIconText: () =>
				window.bricksData.i18n?.choicesjs?.removeItemIconText || 'Remove item',
			removeItemLabelText: (value, rawValue, item) => {
				const itemLabel = this.getPlainText(item?.label || rawValue || value)

				return `${window.bricksData.i18n?.choicesjs?.removeItemIconText || 'Remove item'}: ${itemLabel}`
			},
			noResultsText: window.bricksData.i18n?.choicesjs?.noResultsText || 'No results found',
			noChoicesText:
				window.bricksData.i18n?.choicesjs?.noChoicesText || 'No choices to choose from',
			shouldSort: false,
			allowHTML: true, // Allow count HTML
			classNames: {
				containerOuter: ['bricks-choices'],
				containerInner: ['bricks-choices__inner'],
				input: ['bricks-choices__input'],
				inputCloned: ['bricks-choices__input--cloned'],
				list: ['bricks-choices__list'],
				listItems: ['bricks-choices__list--multiple'],
				listSingle: ['bricks-choices__list--single'],
				listDropdown: ['bricks-choices__list--dropdown'],
				item: ['bricks-choices__item'],
				itemSelectable: ['bricks-choices__item--selectable'],
				itemDisabled: ['bricks-choices__item--disabled'],
				itemChoice: ['bricks-choices__item--choice'],
				description: ['bricks-choices__description'],
				placeholder: ['bricks-choices__placeholder'],
				group: ['bricks-choices__group'],
				groupHeading: ['bricks-choices__heading'],
				button: ['bricks-choices__button'],
				activeState: ['is-active'],
				focusState: ['is-focused'],
				openState: ['is-open'],
				disabledState: ['is-disabled'],
				highlightedState: ['is-highlighted'],
				selectedState: ['is-selected'],
				flippedState: ['is-flipped'],
				loadingState: ['is-loading'],
				invalidState: ['is-invalid'],
				notice: ['bricks-choices__notice'],
				addChoice: ['bricks-choices__item--selectable', 'add-choice'],
				noResults: ['has-no-results'],
				noChoices: ['has-no-choices']
			}
		}

		// Merge user options with defaults
		this.options = { ...this.defaultOptions, ...options }

		this.init()
	}

	init() {
		// Wait for DOM to be ready
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', () => this.initChoices())
		} else {
			this.initChoices()
		}
	}

	initChoices() {
		// Destroy existing instance if exists
		if (this.choices) {
			this.unbindEvents()

			if (this.multiplePlaceholderElement) {
				this.multiplePlaceholderElement.remove()
				this.multiplePlaceholderElement = null
			}

			this.choices.destroy()
		}

		this.removePreInitShell()

		// Initialize Choices.js
		this.choices = new BrxChoices(this.element, this.options)
		this.setupAccessibility()
		this.setupMultiplePlaceholder()
		this.updateMultipleInputPlaceholder(false)

		// Bind events
		this.bindEvents()

		// Store instance on element for later access
		this.element._bricksChoices = this
	}

	/**
	 * Replace the server-rendered first-paint shell without exposing the native select.
	 * #86cb4nn6f; @since 2.4
	 */
	removePreInitShell() {
		const preInitShell = this.element.closest('.bricks-choices--pre-init')

		if (!preInitShell) {
			return
		}

		preInitShell.before(this.element)
		preInitShell.remove()
	}

	bindEvents() {
		if (!this.choices) return

		this.unbindEvents()

		this.eventHandlers = {
			change: (event) => {
				this.toggleMultiplePlaceholder()
				this.updateMultipleInputPlaceholder(this.choices?.dropdown?.isActive)

				// Trigger native change event for Bricks filters
				if (this.isFilterElement) {
					this.handleFilterChange(event)
				}
			},
			choice: (event) => {
				// Custom event when choice is selected
				this.handleChoice(event)
			},
			showDropdown: () => {
				this.updateExpandedState(true)
				this.updateMultipleInputPlaceholder(true)
			},
			hideDropdown: () => {
				this.updateExpandedState(false)
				this.updateMultipleInputPlaceholder(false)
			}
		}

		Object.entries(this.eventHandlers).forEach(([eventName, eventHandler]) => {
			this.element.addEventListener(eventName, eventHandler)
		})
	}

	unbindEvents() {
		Object.entries(this.eventHandlers).forEach(([eventName, eventHandler]) => {
			this.element.removeEventListener(eventName, eventHandler)
		})

		this.eventHandlers = {}
	}

	handleFilterChange(event) {
		// Let Bricks filter system handle the change
		// The native change event will be picked up by bricksSelectFilterFn
	}

	handleChoice(event) {
		// Additional custom handling if needed
	}

	getPlainText(value) {
		let text = value

		if (value && typeof value === 'object') {
			text = value.raw || value.trusted || value.escaped || ''
		}

		return `${text || ''}`.replace(/<[^>]+>/g, '').trim()
	}

	getAccessibleName() {
		return {
			labelId: this.element.getAttribute('aria-labelledby') || '',
			label: this.element.getAttribute('aria-label') || ''
		}
	}

	setAccessibleName(element) {
		if (!element) {
			return
		}

		const { labelId, label } = this.getAccessibleName()

		if (labelId) {
			element.setAttribute('aria-labelledby', labelId)
			element.removeAttribute('aria-label')
		} else if (label) {
			element.setAttribute('aria-label', label)
		}
	}

	setupAccessibility() {
		const containerOuter = this.choices?.containerOuter?.element
		const input = this.choices?.input?.element
		const listbox = this.choices?.choiceList?.element

		if (!containerOuter || !listbox) {
			return
		}

		const idBase = this.element.id || this.element.dataset?.scriptId || 'select'
		this.listboxId = listbox.id || `${idBase}-listbox`
		listbox.id = this.listboxId

		containerOuter.setAttribute('aria-controls', this.listboxId)
		input?.setAttribute('aria-controls', this.listboxId)
		this.setAccessibleName(containerOuter)
		this.setAccessibleName(input)
		this.setAccessibleName(listbox)
	}

	updateExpandedState(isExpanded) {
		if (!this.multiplePlaceholderElement) {
			return
		}

		this.multiplePlaceholderElement.setAttribute('aria-expanded', isExpanded ? 'true' : 'false')
	}

	updateMultipleInputPlaceholder(isExpanded) {
		if (!this.choices || !this.isMultiple || !this.options.searchEnabled) {
			return
		}

		const input = this.choices.input?.element

		if (!input) {
			return
		}

		const hasValue = Array.from(this.element.selectedOptions || []).some(
			(option) => option.value !== ''
		)
		const selectionPlaceholder =
			this.options.placeholderValue || this.element.dataset?.placeholder || ''
		const searchPlaceholder = this.options.searchPlaceholderValue || ''

		// Choices.js otherwise reuses the selection placeholder for its multiple-select search input.
		input.placeholder = isExpanded ? searchPlaceholder : hasValue ? '' : selectionPlaceholder
		this.choices.input.setWidth()
	}

	/**
	 * Update choices dynamically (for AJAX filter updates)
	 */
	updateChoices(newChoices) {
		if (!this.choices) return

		this.choices.clearStore()
		this.choices.setChoices(newChoices, 'value', 'label', true)
		this.toggleMultiplePlaceholder()
		this.updateMultipleInputPlaceholder(this.choices.dropdown?.isActive)
	}

	/**
	 * Get current value(s)
	 */
	getValue() {
		if (!this.choices) return null
		return this.choices.getValue()
	}

	/**
	 * Set value(s)
	 */
	setValue(value) {
		if (!this.choices) return
		this.choices.setChoiceByValue(value)
		this.updateMultipleInputPlaceholder(this.choices.dropdown?.isActive)
	}

	/**
	 * Destroy instance
	 */
	destroy() {
		this.unbindEvents()

		if (this.multiplePlaceholderElement) {
			this.multiplePlaceholderElement.remove()
			this.multiplePlaceholderElement = null
		}

		if (this.choices) {
			this.choices.destroy()
			this.choices = null
		}
	}

	/**
	 * Enable
	 */
	enable() {
		if (this.choices) {
			this.choices.enable()
		}
	}

	/**
	 * Disable
	 */
	disable() {
		if (this.choices) {
			this.choices.disable()
		}
	}

	setupMultiplePlaceholder() {
		const placeholderText = this.options.placeholderValue || this.element.dataset?.placeholder || ''

		if (!this.choices || !this.isMultiple || this.options.searchEnabled || !placeholderText) {
			return
		}

		if (this.multiplePlaceholderElement) {
			this.multiplePlaceholderElement.remove()
		}

		const containerInner = this.choices.containerInner?.element

		if (!containerInner) {
			return
		}

		const placeholder = document.createElement('div')
		placeholder.className = 'bricks-choices__placeholder bricks-choices__placeholder--multiple'
		placeholder.textContent = placeholderText
		placeholder.tabIndex = 0
		placeholder.setAttribute('role', 'button')
		placeholder.setAttribute('aria-haspopup', 'listbox')
		placeholder.setAttribute('aria-expanded', 'false')

		if (this.listboxId) {
			placeholder.setAttribute('aria-controls', this.listboxId)
		}

		this.setAccessibleName(placeholder)

		const openDropdown = () => {
			if (!this.choices || this.choices.containerOuter?.isDisabled) {
				return
			}

			this.choices.showDropdown()
		}

		placeholder.addEventListener('click', openDropdown)
		placeholder.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault()
				openDropdown()
			}
		})

		containerInner.appendChild(placeholder)

		this.multiplePlaceholderElement = placeholder
		this.toggleMultiplePlaceholder()
	}

	toggleMultiplePlaceholder() {
		if (!this.multiplePlaceholderElement) {
			return
		}

		const hasValue = Array.from(this.element.selectedOptions || []).some(
			(option) => option.value !== ''
		)

		this.multiplePlaceholderElement.style.display = hasValue ? 'none' : ''
	}
}

/**
 * Auto-initialize Choices.js on elements with data attribute
 * Currently used in the builder for filter-select elements
 */
const bricksChoicesFn = new BricksFunction({
	parentNode: document,
	selector: 'select[data-brx-choices="true"]:not([data-brx-filter])',
	forceReinit: (element, index) => {
		return !bricksIsFrontend
	},
	windowVariableCheck: ['BrxChoices'],
	eachElement: (element) => {
		const instanceId = element.dataset?.scriptId || false

		if (!instanceId) {
			return
		}

		// Destroy existing splideJS instance
		if (window.bricksData.choicesInstances.hasOwnProperty(instanceId)) {
			window.bricksData.choicesInstances[instanceId].destroy()
		}
		// Get custom options from data attribute
		const customOptions = element.dataset.brxChoicesOptions
			? JSON.parse(element.dataset.brxChoicesOptions)
			: {}

		// Initialize
		const choicesInstance = new BricksChoices(element, customOptions)

		// Save instance reference in window.bricksData
		window.bricksData.choicesInstances[instanceId] = choicesInstance
	}
})

function bricksChoices() {
	bricksChoicesFn.run()
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', bricksChoices)
