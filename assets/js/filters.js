// Extend bricksUtils const in frontend.js
if (window.bricksUtils) {
	// Replace all live search term with the searchValue {live_search_term} DD
	window.bricksUtils.updateLiveSearchTerm = function (targetQueryId, searchValue) {
		// Replace any existing span[data-brx-ls-term] innerHTML with the searchValue
		const liveSearchTerms = document.querySelectorAll(`span[data-brx-ls-term="${targetQueryId}"]`)

		liveSearchTerms.forEach((term) => {
			term.textContent = searchValue
		})
	}

	// Hide: Live search wrapper
	window.bricksUtils.hideLiveSearchWrapper = function (targetQueryId) {
		const liveSearchWrappers = document.querySelectorAll(`[data-brx-ls-wrapper="${targetQueryId}"]`)
		liveSearchWrappers.forEach((wrapper) => {
			wrapper.classList.remove('brx-ls-active')
		})
	}

	// Show: Lve search wrapper
	window.bricksUtils.showLiveSearchWrapper = function (targetQueryId) {
		const liveSearchWrappers = document.querySelectorAll(`[data-brx-ls-wrapper="${targetQueryId}"]`)
		liveSearchWrappers.forEach((wrapper) => {
			wrapper.classList.add('brx-ls-active')
		})
	}

	window.bricksUtils.hideOrShowLiveSearchWrapper = function (queryId) {
		// Get queryLoopInstance from window.bricksData.queryLoopInstances
		const queryLoopInstance = window.bricksData.queryLoopInstances[queryId] || false

		// Exit if no queryLoopInstance or not a live search
		if (!queryLoopInstance || !queryLoopInstance.isLiveSearch) {
			return
		}

		const activeFilters = bricksUtils.getSelectedFiltersForQuery(queryId)
		let hasOtherActiveFilters = false

		const searchFilters = Object.values(window.bricksData.filterInstances || {}).filter(
			(filter) => {
				return filter.targetQueryId === queryId && filter.filterType === 'search'
			}
		)

		if (Object.keys(activeFilters).length > 0) {
			// Ensure the activeFilters are not from search filters, use searchFilters to check if the activeFilters are from search filters or not. (#86c8rmnq7; @since 2.3)
			hasOtherActiveFilters = Object.keys(activeFilters).some((filterId) => {
				return !searchFilters.some((searchFilter) => searchFilter.filterId === filterId)
			})
		}

		// Check if any search filter has a non-empty value, have to fulfil minCharacters if set
		const hasActiveSearch = searchFilters.some((filter) => {
			// Check current element input value instead of instance currentValue
			const value = filter.filterElement ? filter.filterElement.value : ''
			// Check minCharacters
			const filterMinChars = filter.filterMinChars || 3
			if (filterMinChars > 0) {
				return value.trim().length >= filterMinChars
			}
			return value.trim() !== ''
		})

		// Show the live search wrapper if there is any active search or active filter, otherwise hide it
		if (hasActiveSearch || hasOtherActiveFilters) {
			bricksUtils.showLiveSearchWrapper(queryId)
		} else {
			bricksUtils.hideLiveSearchWrapper(queryId)
		}
	}

	// Normalize reset exclusion IDs from PHP before comparing them to filterInstance.filterId. (#86ca6wqtg; @since 2.4)
	window.bricksUtils.getFilterExcludeIds = function (filterInstance) {
		let excludeIds = filterInstance?.excludeIds || []

		if (typeof excludeIds === 'string') {
			excludeIds = excludeIds.split(',')
		}

		if (!Array.isArray(excludeIds)) {
			return []
		}

		return excludeIds
			.map((id) => {
				return `${id}`.trim().replace('brxe-', '').replace('#', '')
			})
			.filter((id) => id !== '')
	}

	// Update selected filters for a targetQuery (@since 1.11)
	window.bricksUtils.updateSelectedFilters = function (
		targetQueryId,
		filterInstance,
		customURL = false
	) {
		// Get filterId
		const filterId = filterInstance.filterId || false
		const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

		if (!filterId || !targetQueryId || !queryInstance) {
			return
		}

		const targetIsLiveSearch = queryInstance?.isLiveSearch || false

		// STEP: Register selectedFilters based on targetQueryId
		if (!window.bricksData.selectedFilters[targetQueryId]) {
			window.bricksData.selectedFilters[targetQueryId] = {}
		}

		// Use customURL if it's provided, currently only used in pagination filter
		let newUrl = customURL ? customURL : window.location.origin + window.location.pathname

		// Function to check if this filterInstance has no value
		const isFilterInstanceEmpty = (filterInstance) => {
			return (
				filterInstance.currentValue === '' ||
				(filterInstance.currentValue === 0 && filterInstance.filterType === 'pagination') ||
				(Array.isArray(filterInstance.currentValue) && filterInstance.currentValue.length === 0) ||
				(filterInstance.filterType === 'range' &&
					(JSON.stringify(filterInstance.currentValue) ===
						JSON.stringify([filterInstance.min, filterInstance.max]) ||
						!filterInstance.hasChanged))
			)
		}

		// Function to rearrange the selectedFilters if some of them deleted, do not use inside the loop
		const rearrangeSelectedFilters = (targetQueryId) => {
			if (!window.bricksData.selectedFilters[targetQueryId]) {
				return
			}
			let newSelectedFilters = {}
			let filterIndex = 0
			Object.keys(window.bricksData.selectedFilters[targetQueryId]).forEach((key) => {
				const filterId = window.bricksData.selectedFilters[targetQueryId][key]
				newSelectedFilters[filterIndex] = filterId
				filterIndex++
			})

			window.bricksData.selectedFilters[targetQueryId] = newSelectedFilters
		}

		// STEP: Register filterId following the sequence of selected filters
		if (isFilterInstanceEmpty(filterInstance)) {
			// Find the filterId from the selectedFilters and remove it
			Object.keys(window.bricksData.selectedFilters[targetQueryId]).forEach((key) => {
				if (window.bricksData.selectedFilters[targetQueryId][key] === filterId) {
					delete window.bricksData.selectedFilters[targetQueryId][key]

					// Remove page/n from the URL if the filter is a pagination filter
					const thisFilter = window.bricksData.filterInstances[filterId] || false
					if (thisFilter && thisFilter.filterType === 'pagination') {
						newUrl = newUrl.replace(/\/page\/[0-9]+/g, '')
					}
				}
			})

			// Maybe something is deleted, rearrange the selectedFilters
			rearrangeSelectedFilters(targetQueryId)
		} else {
			// Check if the filterId is already in the selectedFilters
			const isFilterIdExist = Object.values(
				window.bricksData.selectedFilters[targetQueryId]
			).includes(filterId)

			if (!isFilterIdExist) {
				// Register the filterId with a key to record the sequence of selected filters
				let filterIndex = Object.keys(window.bricksData.selectedFilters[targetQueryId]).length
				window.bricksData.selectedFilters[targetQueryId][filterIndex] = filterId
			}
		}

		// Remove all pagination filter from selectedFilters if current filter is not a pagination filter (Reset to page 1)
		if (
			filterInstance.filterType !== 'pagination' &&
			window.bricksData.selectedFilters[targetQueryId]
		) {
			let paginationRemoved = false

			Object.keys(window.bricksData.selectedFilters[targetQueryId]).forEach((key) => {
				const filterId = window.bricksData.selectedFilters[targetQueryId][key]
				const filterInstance = window.bricksData.filterInstances[filterId] || false

				if (filterInstance && filterInstance.filterType === 'pagination') {
					delete window.bricksData.selectedFilters[targetQueryId][key]
					// Reset the pagination filter value
					bricksUtils.resetFilterValue(filterInstance)
					paginationRemoved = true
				}
			})

			// Maybe something is deleted, rearrange the selectedFilters
			rearrangeSelectedFilters(targetQueryId)

			// Remove page/n from the URL if pagination filter is removed
			if (paginationRemoved && !targetIsLiveSearch) {
				newUrl = newUrl.replace(/\/page\/[0-9]+/g, '')
			}
		}

		// Handle apply filter or search filter with redirect action, need to consider all filters current value to build the URL params, not just the current changed filter (#86c0kgcyf)
		if (
			filterInstance.filterType === 'apply' ||
			(filterInstance.filterType === 'search' && filterInstance?.filterEnterAction === 'redirect')
		) {
			// Rebuid the selectedFilters based on all filters current values
			window.bricksData.selectedFilters[targetQueryId] = {}

			let allFilters = bricksUtils.getFiltersForQuery(targetQueryId)
			allFilters.forEach((fInstance) => {
				// Skip if this filter current value is empty
				if (isFilterInstanceEmpty(fInstance)) {
					return
				}

				// Check if the filterId is already in the selectedFilters
				const isFilterIdExist = Object.values(
					window.bricksData.selectedFilters[targetQueryId]
				).includes(fInstance.filterId)

				if (!isFilterIdExist) {
					// Register the filterId with a key to record the sequence of selected filters
					let filterIndex = Object.keys(window.bricksData.selectedFilters[targetQueryId]).length
					window.bricksData.selectedFilters[targetQueryId][filterIndex] = fInstance.filterId
				}
			})
		}

		// Handle reset filter
		if (filterInstance.filterType === 'reset') {
			const excludeFilterIds = bricksUtils.getFilterExcludeIds(filterInstance)

			// Reset clears selected filter state, then re-adds excluded filters so AJAX and URL params keep their context. (#86ca6wqtg; @since 2.4)
			window.bricksData.selectedFilters[targetQueryId] = {}

			if (excludeFilterIds.length) {
				bricksUtils.getFiltersForQuery(targetQueryId).forEach((fInstance) => {
					if (!excludeFilterIds.includes(fInstance.filterId) || isFilterInstanceEmpty(fInstance)) {
						return
					}

					let filterIndex = Object.keys(window.bricksData.selectedFilters[targetQueryId]).length
					window.bricksData.selectedFilters[targetQueryId][filterIndex] = fInstance.filterId
				})
			}
		}

		// Early return if query disabled URL params
		if (queryInstance?.disableUrlParams) {
			return
		}

		// Handle URL Params
		if (targetIsLiveSearch) {
			// Use current original full URL for pushState
			newUrl = window.location.href
		} else {
			// Build URL params from selectedFilters for the targetQueryId
			let params = bricksUtils.buildFilterUrlParams(targetQueryId)

			if (params) {
				// In case newUrl already contains user-defined params, merge with filter params instead of replacing
				const urlObj = bricksUtils.mergeUrlWithParams(newUrl, params)
				newUrl = urlObj.pathname + urlObj.search
			}
		}

		// STEP: Update PushState
		bricksUtils.updatePushState(targetQueryId, newUrl)
	}

	// Reset specific filterInstance by value or all values (@since 1.11)
	window.bricksUtils.resetFilterValue = function (filter, targetValue = false) {
		const filterType = filter.filterType
		const element = filter.filterElement
		const originalValue = filter.originalValue
		const targetQueryId = filter.targetQueryId

		switch (filterType) {
			case 'search':
				element.value = originalValue
				filter.currentValue = originalValue

				// Hide or show search icon based on the originalValue
				bricksUtils.updateSearchFilterIconVisibility(filter)

				// Update the live search term
				bricksUtils.updateLiveSearchTerm(targetQueryId, originalValue)
				break
			case 'select':
				const isMultiple = filter.isMultiple || false

				if (isMultiple) {
					// Multiple select (Choices.js)

					if (targetValue !== false) {
						// Remove specific value from multi-select
						filter.choicesInstance.choices.removeActiveItemsByValue(targetValue)

						// Update currentValue
						filter.currentValue = filter.currentValue.filter((value) => value !== targetValue)
					} else {
						// Reset all values
						filter.choicesInstance.choices.removeActiveItems()

						// Update currentValue
						filter.currentValue = originalValue
					}
				} else {
					// Single select
					element.value = originalValue
					filter.currentValue = originalValue
				}

				break
			case 'pagination':
				filter.currentValue = originalValue
				break
			case 'radio':
				element.value = originalValue
				filter.currentValue = originalValue
				// Find all child radio inputs and uncheck them if it's not the originalValue
				const radioInputs = element.querySelectorAll('input')
				radioInputs.forEach((radioInput) => {
					if (radioInput.value !== originalValue) {
						radioInput.checked = false
					} else {
						radioInput.checked = true
					}
				})
				break
			case 'checkbox':
				const updateCheckboxes = (checkboxInputs, valueSet) => {
					checkboxInputs.forEach((checkboxInput) => {
						checkboxInput.checked = valueSet.has(checkboxInput.value)
					})
				}

				if (targetValue !== false) {
					// Remove the targetValue from the currentValue
					filter.currentValue = filter.currentValue.filter((value) => value !== targetValue)

					// Find all child checkbox inputs and update their checked status
					const checkboxInputs = element.querySelectorAll('input')
					updateCheckboxes(checkboxInputs, new Set([targetValue]))
				} else {
					filter.currentValue = [...originalValue]

					// Find all child checkbox inputs and update their checked status
					const checkboxInputs = element.querySelectorAll('input')
					updateCheckboxes(checkboxInputs, new Set(originalValue))
				}

				break

			case 'datepicker':
				// Get the flatpickr instance
				const flatpickrInstance = filter.datepicker || false

				if (!flatpickrInstance) {
					return
				}

				// Reset the flatpickr instance value
				flatpickrInstance.clear()

				flatpickrInstance.setDate(originalValue, false) // No fire change event
				filter.currentValue = originalValue

				bricksUtils.updateDatepickerFilterIconVisibility(filter)
				break

			case 'range':
				filter.currentValue = [...originalValue]
				filter.hasChanged = false
				break
		}
	}

	// Build URL params from selectedFilters (@since 1.11)
	window.bricksUtils.buildFilterUrlParams = function (targetQueryId) {
		// STEP: Update selected filters: eg. ?brx_filter_id=filterValue based on filterId sequence
		let brxUrlParams = new URLSearchParams()
		let oriUrlParams = new URLSearchParams(window.location.search)
		let otherParams = {}

		// STEP: Collect all URL params that are not related to the filter
		oriUrlParams.forEach((value, key) => {
			// Remove everything from array keys, e.g., category[0] or category[abc] becomes category[] (#86c0kyubb)
			let paramKey = key.replace(/\[.*?\]/g, '[]')
			// Remove [] from the key from the key
			paramKey = paramKey.replace('[]', '')

			// paramKey is clean, check if it's not related to the filter
			if (!paramKey.includes('brx_') && !window.bricksData.filterNiceNames.includes(paramKey)) {
				// Not related to the filter, save original key and value to otherParams
				otherParams[key] = value
			}
		})

		// STEP: Generate other URL params that are not related to the filter
		Object.keys(otherParams).forEach((key) => {
			brxUrlParams.append(key, otherParams[key])
		})

		// STEP: Follow the sequence of selected filters to build the URL params
		if (Object.keys(window.bricksData.selectedFilters[targetQueryId]).length > 0) {
			Object.keys(window.bricksData.selectedFilters[targetQueryId]).forEach((key) => {
				const filterId = window.bricksData.selectedFilters[targetQueryId][key]
				const filterInstance = window.bricksData.filterInstances[filterId] || false
				if (!filterInstance) {
					return
				}
				let value = filterInstance.currentValue
				let urlKey = filterInstance.filterNiceName || `brx_${filterId}`

				// Only add the filterValue if it's not empty
				if (value === '' || (Array.isArray(value) && value.length === 0)) {
					return
				}

				// Exclude pagination filter because it's included in base path
				if (filterInstance.filterType === 'pagination') {
					return
				}

				// Check if the filterValue is an array,
				if (Array.isArray(value)) {
					// Add [] to the key to indicate it's an array
					urlKey = `${urlKey}[]`
					value.forEach((v) => {
						brxUrlParams.append(urlKey, v)
					})
				} else {
					brxUrlParams.append(urlKey, value)
				}
			})
		}

		return brxUrlParams.toString() ?? ''
	}

	// Merge URL params into URL, preserving existing user-defined params (@since 2.4)
	window.bricksUtils.mergeUrlWithParams = function (baseUrl, params = '') {
		const urlObj = new URL(baseUrl, window.location.origin)
		const newParams = new URLSearchParams(params)
		newParams.forEach((value, key) => urlObj.searchParams.append(key, value))
		return urlObj
	}

	// Update pushState with targetQueryId and URL (@since 1.11)
	window.bricksUtils.updatePushState = function (targetQueryId, url) {
		if (!targetQueryId || !url) {
			return
		}

		// Record all filterInstances currentValue and selectedFilters into the history state
		let instancesValue = {}
		let allTargetQueryIds = bricksUtils.currentPageTargetQueryIds()

		// Save each filter current value in instancesValue for each targetQueryId
		allTargetQueryIds.forEach((targetQueryId) => {
			instancesValue[targetQueryId] = {}

			let allFilters = bricksUtils.getFiltersForQuery(targetQueryId)
			allFilters.forEach((filterInstance) => {
				instancesValue[targetQueryId][filterInstance.filterId] = filterInstance.currentValue
			})
		})

		// clone the selectedFilters
		let selectedFiltersState = window.bricksData.selectedFilters

		window.history.pushState(
			{
				isBricksFilter: true,
				targetQueryId: targetQueryId,
				selectedFilters: selectedFiltersState,
				instancesValue: instancesValue
			},
			'',
			url
		)
	}

	// Get all filters for a specific query (@since 1.11)
	window.bricksUtils.getFiltersForQuery = function (targetQueryId, property = false) {
		if (
			!window.bricksData.filterInstances ||
			Object.keys(window.bricksData.filterInstances).length < 1
		) {
			return []
		}

		// Find all filters with the same targetQueryId, to dynamically update filters DOM, bricksData.filterInstances is Object
		const filters =
			Object.values(window.bricksData.filterInstances).filter((filter) => {
				return filter.targetQueryId === targetQueryId
			}) || []

		if (property) {
			return filters.map((filter) => {
				return filter[property]
			})
		}

		return filters
	}

	// Get all targetQueryId exists from all filters (@since 1.11)
	window.bricksUtils.currentPageTargetQueryIds = function () {
		if (
			!window.bricksData.filterInstances ||
			Object.keys(window.bricksData.filterInstances).length < 1
		) {
			return []
		}

		// Find all targetQueryId, to dynamically update filters DOM, bricksData.filterInstances is Object
		const targetQueryIds = Object.values(window.bricksData.filterInstances).reduce(
			(acc, filter) => {
				if (!acc.includes(filter.targetQueryId)) {
					acc.push(filter.targetQueryId)
				}

				return acc
			},
			[]
		)

		return targetQueryIds
	}

	// Get all selected filters for a specific query (@since 1.11)
	window.bricksUtils.getSelectedFiltersForQuery = function (targetQueryId) {
		if (
			!window.bricksData.selectedFilters ||
			!window.bricksData.selectedFilters[targetQueryId] ||
			!window.bricksData.filterInstances ||
			Object.keys(window.bricksData.filterInstances).length < 1
		) {
			return []
		}

		// Loop through selectedFilters and build allFilters array, key: filter.filterId, value: filter.currentValue
		let selectedFilters = Object.values(window.bricksData.selectedFilters[targetQueryId]).reduce(
			(acc, filterId) => {
				let filter = window.bricksData.filterInstances[filterId] || false

				if (filter) {
					acc[filter.filterId] = filter.currentValue
				}
				return acc
			},
			{}
		)

		return selectedFilters
	}

	// Hide reset filter button if there is no active filters immediately before fetching filter results for better UX. (#86c3zpqyj) (@since 2.2)
	window.bricksUtils.maybeHideResetFilterButton = function (targetQueryId) {
		if (!targetQueryId) {
			return
		}

		// Get all selected filters for the targetQueryId
		const selectedFilters = bricksUtils.getSelectedFiltersForQuery(targetQueryId)

		// Find all reset filter instances for the targetQueryId with hideOnNoFilter = true
		const resetFilterInstances = bricksUtils.getFiltersForQuery(targetQueryId).filter((filter) => {
			return filter.filterType === 'reset' && filter.hideOnNoFilter === true
		})

		resetFilterInstances.forEach((filterInstance) => {
			const excludeFilterIds = bricksUtils.getFilterExcludeIds(filterInstance)

			// Excluded filters can remain active after reset, so only non-excluded filters make the reset button useful. (#86ca6wqtg; @since 2.4)
			const hasResettableFilters = Object.keys(selectedFilters).some((filterId) => {
				return !excludeFilterIds.includes(filterId)
			})

			if (hasResettableFilters) {
				return
			}

			const element = filterInstance.filterElement
			if (element) {
				element.classList.add('brx-no-active-filter')
			}
		})
	}

	// Fetch filter results for a specific query (@since 1.11)
	// Returning a promise (used for dispatching submit filter) (@since 2.4)
	window.bricksUtils.fetchFilterResults = function (targetQueryId, isPopState = false) {
		if (!targetQueryId || !window.bricksData.queryLoopInstances[targetQueryId]) {
			return Promise.resolve()
		}

		bricksUtils.maybeHideResetFilterButton(targetQueryId)

		return bricksGetQueryResult(targetQueryId, isPopState)
			.then((res) => {
				bricksDisplayQueryResult(targetQueryId, res)
			})
			.catch((err) => {
				// Only show error if the xhr is not aborted
				if (!window.bricksData.queryLoopInstances[targetQueryId].xhrAborted) {
					console.log('bricksGetQueryResult:error', err)
				}
			})
	}

	// Get dynamic tags for a specific query - currently only active filters tags (@since 2.0)
	window.bricksUtils.getDynamicTagsForParse = function (targetQueryId) {
		const dynamicTags = []

		// Retrive all dynamic tags from window.bricksData.activeFiltersCountInstances where targetQueryId is the same
		const activeFiltersCountDDs = Object.values(
			window.bricksData.activeFiltersCountInstances
		).filter((instance) => {
			// targetQueryId is same and dynamicTag starts with active_filters_count
			return (
				instance?.targetQueryId === targetQueryId &&
				instance?.dynamicTag.startsWith('active_filters_count')
			)
		})

		activeFiltersCountDDs.forEach((instance) => {
			dynamicTags.push(instance.dynamicTag)
		})

		return dynamicTags
	}

	// Update parsed dynamic tags for a specific query (@since 2.0)
	window.bricksUtils.updateParsedDynamicTags = function (targetQueryId, parsedDynamicTags) {
		// Retrive all dynamic tags from window.bricksData.activeFiltersCountInstances where targetQueryId is the same
		const activeFiltersCountDDs = Object.values(
			window.bricksData.activeFiltersCountInstances
		).filter((instance) => {
			// targetQueryId is same and dynamicTag starts with active_filters_count
			return (
				instance?.targetQueryId === targetQueryId &&
				instance?.dynamicTag.startsWith('active_filters_count')
			)
		})

		// Loop through all activeFiltersCountDDs and find the dynamicTag in parsedDynamicTags, update the element innerHTML if found
		activeFiltersCountDDs.forEach((instance) => {
			const dynamicTag = instance.dynamicTag
			const element = instance.element

			if (parsedDynamicTags[dynamicTag] && element.isConnected) {
				element.innerHTML = parsedDynamicTags[dynamicTag]
			}
		})
	}

	// Update Search Filter Icon Visibility (@since 2.2)
	window.bricksUtils.updateSearchFilterIconVisibility = function (
		filterInstance,
		searchValue = null
	) {
		// Ensure it's a search filter
		if (filterInstance.filterType !== 'search') {
			return
		}
		const element = filterInstance.filterElement
		const searchIcon = element.nextElementSibling || false

		if (!searchIcon) {
			return
		}

		const compareValue = searchValue !== null ? searchValue : filterInstance.currentValue

		if (compareValue === '') {
			searchIcon.classList.remove('brx-show')
		} else {
			searchIcon.classList.add('brx-show')
		}
	}

	// Update Datepicker Filter Icon Visibility (@since 2.2)
	window.bricksUtils.updateDatepickerFilterIconVisibility = function (
		filterInstance,
		dateValue = null
	) {
		// Ensure it's a datepicker filter
		if (filterInstance.filterType !== 'datepicker') {
			return
		}

		const element = filterInstance.filterElement
		const datepickerIcon = element.parentElement?.querySelector('.icon') || false

		if (!datepickerIcon) {
			return
		}

		const compareValue = dateValue !== null ? dateValue : filterInstance.currentValue

		if (compareValue === '') {
			datepickerIcon.classList.remove('brx-show')
		} else {
			datepickerIcon.classList.add('brx-show')
		}
	}
}

// Init all filters settings and save them into window.bricksData.filterInstances
const bricksFiltersFn = new BricksFunction({
	parentNode: document,
	selector: '[data-brx-filter]',
	frontEndOnly: true,
	// forceReinit: (element, index) => {
	// 	return !bricksIsFrontend
	// },
	eachElement: (element) => {
		const filterSettings = JSON.parse(element.dataset?.brxFilter) || false

		// No filter settings: Skip
		if (!filterSettings) {
			return
		}

		const filterId = filterSettings?.filterId || false
		const targetQueryId = filterSettings?.targetQueryId || false
		const filterType = filterSettings?.filterType || false
		const filterAction = filterSettings?.filterAction || false
		const niceName = filterSettings?.filterNiceName || ''

		if (!filterId || !targetQueryId || !filterType || !filterAction) {
			return
		}

		// STEP: Save settings into window.bricksData.filterInstances
		if (!window.bricksData.filterInstances) {
			window.bricksData.filterInstances = {}
		}

		// Register instance if it's not registered
		if (!window.bricksData.filterInstances[filterId]) {
			// Add filterElement to filterSettings
			filterSettings.filterElement = element
			filterSettings.currentValue = ''
			filterSettings.originalValue = ''

			switch (filterType) {
				case 'search':
					// Overwrite currentValue if the input has value
					if (element.value) {
						filterSettings.currentValue = element.value
					}

					break
				case 'select':
					filterSettings.isChoiceJS = element?.dataset?.brxChoices === 'true' || false
					// Check if multiple is enabled
					filterSettings.isMultiple = filterSettings.isChoiceJS && element.hasAttribute('multiple')

					// Overwrite currentValue if the input has value
					if (filterSettings.isMultiple) {
						// Multiple select (Similar to checkbox)
						const selectedOptions = Array.from(element.selectedOptions).map(
							(option) => option.value
						)

						if (selectedOptions.length) {
							filterSettings.currentValue = selectedOptions
						} else {
							filterSettings.currentValue = []
						}

						filterSettings.originalValue = []
					} else {
						// Single select
						if (element.value) {
							filterSettings.currentValue = element.value
						}
					}

					break
				case 'reset':
					if (element.dataset.brxHideOnNoFilter === 'true') {
						filterSettings.hideOnNoFilter = true
					}
					break
				case 'apply':
					break
				case 'active-filters':
					break
				case 'radio':
					// Overwrite currentValue if the input has value
					let radioValue = element.querySelector('input:checked')

					if (radioValue) {
						filterSettings.currentValue = radioValue.value
					}

					break
				case 'checkbox':
					// Overwrite currentValue if the input has value
					const checkboxValue = element.querySelectorAll('input:checked')

					if (checkboxValue.length) {
						let currentValue = Array.from(checkboxValue).map((input) => {
							return input.value
						})

						filterSettings.currentValue = currentValue
					} else {
						filterSettings.currentValue = []
					}

					filterSettings.originalValue = []

					break
				case 'pagination':
					/**
					 * Pagination is special, no nice name and not register in filter element DB, only can get currentValue from URL.
					 * Not in selectedFilters on page load too.
					 */
					const currentPage = bricksUtils.getPageNumberFromUrl(window.location.href)
					filterSettings.currentValue = currentPage

					// Additional step to save this filter into selectedFilters if current page is not 1
					if (currentPage > 1) {
						if (!window.bricksData.selectedFilters[targetQueryId]) {
							window.bricksData.selectedFilters[targetQueryId] = {}
						}

						// Check if the filterId is already in the selectedFilters
						const isFilterIdExist = Object.values(
							window.bricksData.selectedFilters[targetQueryId]
						).includes(filterId)

						if (!isFilterIdExist) {
							// Register the filterId with a key to record the sequence of selected filters
							let filterIndex = Object.keys(window.bricksData.selectedFilters[targetQueryId]).length
							window.bricksData.selectedFilters[targetQueryId][filterIndex] = filterId
						}
					}

					break
				case 'datepicker':
					// Overwrite currentValue if the datepicker has defaultValue
					let flatpickrOptions = element.dataset.bricksDatepickerOptions || false

					if (flatpickrOptions) {
						flatpickrOptions = JSON.parse(flatpickrOptions)
						// check if the datepicker has a default value
						if (flatpickrOptions.defaultDate) {
							// if is an array, join with comma
							if (Array.isArray(flatpickrOptions.defaultDate)) {
								flatpickrOptions.defaultDate = flatpickrOptions.defaultDate.join(',')
							}

							filterSettings.currentValue = flatpickrOptions.defaultDate
						}
					}
					break

				case 'range':
					// Overwrite currentValue if the input has value
					const rangeValueLow = element.querySelector('input.min[type="number"]') || 0
					const rangeValueHigh = element.querySelector('input.max[type="number"]') || 0
					let currentValue = [filterSettings.min, filterSettings.max]

					if (rangeValueLow) {
						currentValue[0] = parseFloat(rangeValueLow.value)
					}

					if (rangeValueHigh) {
						currentValue[1] = parseFloat(rangeValueHigh.value)
					}

					filterSettings.currentValue = [...currentValue]
					filterSettings.originalValue = [filterSettings.min, filterSettings.max]

					// Determine if the range has changed from the original min/max (#86c3vf095; @since 2.2)
					if (currentValue[0] !== filterSettings.min || currentValue[1] !== filterSettings.max) {
						filterSettings.hasChanged = true
					} else {
						filterSettings.hasChanged = false
					}
					break
			}

			// Save all filter settings from JSON.parse as individual filter instance
			window.bricksData.filterInstances[filterId] = filterSettings

			// Collect all possible nice names from the filter element
			if (
				niceName !== '' &&
				window.bricksData.filterNiceNames &&
				!window.bricksData.filterNiceNames.includes(niceName)
			) {
				window.bricksData.filterNiceNames.push(niceName)
			}
		}

		// Set data-brx-filter to true, beautify the HTML
		element.dataset.brxFilter = true
	}
})

function bricksFilters() {
	bricksFiltersFn.run()
}

// Search filter
const bricksSearchFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-search input[data-brx-filter]',
	frontEndOnly: true,
	eachElement: (element) => {
		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === element
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax' // NOTE: Always 'ajax' in beta
		const filterApplyOn = filterInstance?.filterApplyOn || 'change'
		const filterInputDebounce = filterInstance?.filterInputDebounce || 500
		const filterMinChars = filterInstance?.filterMinChars || 3
		const filterEnterAction = filterInstance?.filterEnterAction || 'default'
		const filterEnterTriggerSubmit = filterInstance?.filterEnterTriggerSubmit || false
		const redirectTo = filterInstance?.redirectTo || false
		const newTab = filterInstance?.newTab || false

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			// Search input
			const search = (e = {}, options = {}) => {
				const { forceSubmit = false, preventRedirect = false } = options
				const searchValue = element.value
				const isEnter = e.key === 'Enter' || forceSubmit
				const isEnterKey = e.key === 'Enter'

				bricksUtils.updateSearchFilterIconVisibility(filterInstance, searchValue)

				// Should continue with the search even if the searchValue is the same as currentValue when because it might be a XHR abort on previous request
				// Return: searchValue is the same as currentValue
				// if (!isEnter && searchValue === filterInstance.currentValue) {
				// 	return
				// }

				// Save searchValue as currentValue
				filterInstance.currentValue = searchValue

				// Get query instance and check if it's a live search
				const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

				if (!queryInstance) {
					return
				}

				const fetchResults = () => {
					if (!isEnterKey || !filterEnterTriggerSubmit) {
						return bricksUtils.fetchFilterResults(targetQueryId)
					}

					// Dispatch Filter Submit interaction events for opted-in Enter-key submissions (@since 2.4)
					document.dispatchEvent(
						new CustomEvent('bricks/filter/submit/start', {
							detail: { queryId: targetQueryId, filterId: filterId }
						})
					)

					return bricksUtils.fetchFilterResults(targetQueryId).finally(() => {
						document.dispatchEvent(
							new CustomEvent('bricks/filter/submit/end', {
								detail: { queryId: targetQueryId, filterId: filterId }
							})
						)
					})
				}

				// If it's a live search, hide the [data-brx-ls-wrapper] if searchValue is empty.
				// Exclude when filterEnterAction is redirect, otherwise hit Enter with empty searchValue will not trigger redirect
				if (
					queryInstance?.isLiveSearch &&
					searchValue === '' &&
					(filterEnterAction !== 'redirect' || preventRedirect)
				) {
					bricksUtils.hideLiveSearchWrapper(targetQueryId)
					// Update selected filters
					bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

					// Make API call to refresh filter options
					fetchResults()

					return
				}

				// Return: searchValue is below the filterMinChars
				if (!isEnter && searchValue.length && searchValue.length < filterMinChars) {
					return
				}

				if (!isEnter && (!targetQueryId || filterApplyOn === 'click')) {
					return
				}

				/**
				 * STEP: Replace any existing live_search_term that's targeting the same query with the latest searchValue
				 */
				bricksUtils.updateLiveSearchTerm(targetQueryId, searchValue)

				// STEP: Update selected filters
				bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

				// STEP: Redirect on Enter when configured (@since 2.4)
				if (isEnter && !preventRedirect && filterEnterAction === 'redirect' && redirectTo) {
					let params = bricksUtils.buildFilterUrlParams(targetQueryId)
					const urlObj = bricksUtils.mergeUrlWithParams(redirectTo, params)
					let url = urlObj.toString()

					if (newTab) {
						window.open(`${url}`, '_blank')
					} else {
						window.location.href = url
					}

					return
				}

				// STEP: Execute filter
				fetchResults()
			}

			// Possible filterApplyOn values: change, click (on filter apply button)
			if (filterApplyOn === 'change') {
				// Abort previous AJAX request search on keyup without debounce (@since 1.12)
				element.addEventListener('input', () => bricksUtils.maybeAbortXhr(targetQueryId))

				element.addEventListener('input', bricksUtils.debounce(search, filterInputDebounce))

				// Still listen to Enter key for immediate search
				element.addEventListener('keydown', (e) => {
					if (e.key === 'Enter') {
						e.preventDefault()
						search(e)
					}
				})
			} else {
				element.addEventListener('input', search)

				// Listen to keyup "Enter" event as well
				element.addEventListener('keydown', (e) => {
					if (e.key === 'Enter') {
						e.preventDefault()
						search(e)
					}
				})
			}

			// Only show the [data-brx-ls-wrapper] on focus when it's not redirect on Enter, otherwise it will cause confusion that the search result is shown in the current page but actually it's redirecting to another page on Enter (@since 2.4)
			if (!(filterEnterAction === 'redirect' && filterApplyOn === 'click')) {
				// Maybe show the [data-brx-ls-wrapper] if queryInstance.isLiveSearch on focus, if searchValue is not empty
				element.addEventListener('focus', (e) => {
					// Get query instance and check if it's a live search
					const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

					if (!queryInstance) {
						return
					}
					const activeFilters = bricksUtils.getSelectedFiltersForQuery(targetQueryId)
					const hasActiveFilters = Object.keys(activeFilters).length > 0

					// If it's a live search, show the [data-brx-ls-wrapper] if there is any active filters (#86c3zpqyj)
					if (queryInstance?.isLiveSearch && hasActiveFilters) {
						bricksUtils.showLiveSearchWrapper(targetQueryId)
					}
				})
			}

			// For clear icon, clear the search input value
			const clearIcon = element.nextElementSibling || false
			if (!clearIcon) {
				return
			}

			// Return: Icon doesn't have the required .icon class
			if (!clearIcon.classList.contains('icon')) {
				return
			}

			// Clear search input value on Click, Enter, or Space
			const clearSearchInputValue = (e) => {
				e.preventDefault()
				element.value = ''
				element.focus()
				// Trigger clear action without redirect
				search(e, { forceSubmit: true, preventRedirect: true })
			}

			clearIcon.addEventListener('click', (e) => clearSearchInputValue(e))
			clearIcon.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' || e.key === ' ') {
					clearSearchInputValue(e)
				}
			})
		} else {
			// Page refresh
		}
	}
})

function bricksSearchFilter() {
	bricksSearchFilterFn.run()
}

// Select filter
const bricksSelectFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-select[data-brx-filter]',
	frontEndOnly: true,
	eachElement: (element) => {
		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === element
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'
		const filterSource = filterInstance?.filterSource || false
		const filterApplyOn = filterInstance?.filterApplyOn || 'change'
		const isChoiceJS = filterInstance?.isChoiceJS || false
		const isMultiple = filterInstance?.isMultiple || false

		if (!targetQueryId) {
			return
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			if (isChoiceJS) {
				let choicesOptions = element.dataset?.brxChoicesOptions || false
				choicesOptions = JSON.parse(choicesOptions) || {}

				// STEP: Initialize Choices instance (@since 2.3)
				filterInstance.choicesInstance = new BricksChoices(element, choicesOptions)
			}

			// Select input
			element.addEventListener('change', function (e) {
				let selectValue

				if (isMultiple) {
					// Get all selected values
					selectValue = Array.from(element.selectedOptions).map((opt) => opt.value)
				} else {
					selectValue = e.target.value
				}

				// If searchValue is the same as currentValue, skip
				// if (selectValue === filterInstance.currentValue) {
				// 	return
				// }

				// Compare arrays correctly
				const currentValue = filterInstance.currentValue
				if (isMultiple) {
					if (
						JSON.stringify(selectValue.sort()) ===
						JSON.stringify((Array.isArray(currentValue) ? currentValue : [currentValue]).sort())
					) {
						return
					}
				} else {
					if (selectValue === currentValue) {
						return
					}
				}

				// Save selectValue as currentValue
				filterInstance.currentValue = selectValue

				// Only execute filter if filterApplyOn is change (@since 1.9.9)
				if (filterApplyOn !== 'change') {
					return
				}

				// Get query instance
				const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

				if (!queryInstance) {
					return
				}

				// STEP: Update selected filters
				bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

				// STEP: Execute filter
				bricksUtils.fetchFilterResults(targetQueryId)
			})
		}
	}
})

// Select filter
function bricksSelectFilter() {
	bricksSelectFilterFn.run()
}

// Radio filter
const bricksRadioFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-radio[data-brx-filter] input',
	frontEndOnly: true,
	eachElement: (radioInput) => {
		const filterElement = radioInput.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === filterElement
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'
		const filterApplyOn = filterInstance?.filterApplyOn || 'change'

		if (!targetQueryId) {
			return
		}

		/**
		 * Remove default radio input behavior or it will change the value when using Arrow keys
		 * Add custom navigation for radio input
		 *
		 * @since 1.10
		 */
		radioInput.addEventListener('keydown', function (e) {
			if (
				e.key === 'ArrowDown' ||
				e.key === 'ArrowUp' ||
				e.key === 'ArrowLeft' ||
				e.key === 'ArrowRight'
			) {
				e.preventDefault()

				// Custom navigation
				let currentInput = e.target
				let liNode = currentInput.closest('li')

				if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
					let nextLiNode = liNode.nextElementSibling
					// Maybe some inputs are disabled, skip them
					while (
						nextLiNode &&
						nextLiNode.querySelector('input') &&
						nextLiNode.querySelector('input').disabled
					) {
						nextLiNode = nextLiNode.nextElementSibling
					}

					if (nextLiNode && nextLiNode.querySelector('input')) {
						nextLiNode.querySelector('input').focus()
					}
				} else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
					let prevLiNode = liNode.previousElementSibling
					// Maybe some inputs are disabled, skip them
					while (
						prevLiNode &&
						prevLiNode.querySelector('input') &&
						prevLiNode.querySelector('input').disabled
					) {
						prevLiNode = prevLiNode.previousElementSibling
					}

					if (prevLiNode && prevLiNode.querySelector('input')) {
						prevLiNode.querySelector('input').focus()
					}
				}
			}
		})

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			// Function to update class for the checked radio input
			const updateClass = () => {
				// Remove all brx-option-active class
				const allNodesWithActiveClass =
					filterElement.querySelectorAll('.brx-option-active') || false

				if (allNodesWithActiveClass) {
					allNodesWithActiveClass.forEach((node) => {
						node.classList.remove('brx-option-active')
					})
				}

				// Update class for the checked radio input
				const checkedRadio = filterElement.querySelector('input:checked') || false

				if (checkedRadio) {
					const liNode = checkedRadio.closest('li') || false
					if (liNode) {
						liNode.classList.add('brx-option-active')
					}

					const labelNode = checkedRadio.closest('label') || false
					if (labelNode) {
						labelNode.classList.add('brx-option-active')
					}

					const spanNode = checkedRadio.nextElementSibling || false
					if (spanNode) {
						spanNode.focus()
						spanNode.classList.add('brx-option-active')
					}
				}
			}
			// Custom click event for toggleable radio input (@since 1.11)
			radioInput.addEventListener('click', function (e) {
				// Cannot preventDefault here, it will prevent the radio input from being checked
				// e.preventDefault()

				if (filterInstance.currentValue === radioInput.value) {
					// Do nothing if this is the reset radio input
					if (radioInput.value === '') {
						return
					}
					// Toggle the radio input
					// STEP: Remove checked attribute from all radio inputs
					const otherInputs = filterElement.querySelectorAll('input') || false
					if (otherInputs) {
						otherInputs.forEach((input) => {
							input.removeAttribute('checked')
						})
					}

					// STEP: Check if there is an empty radio input (maybe user remove this all option)
					let allOption = filterElement.querySelector('input[value=""]') || false
					if (allOption) {
						allOption.checked = true
						allOption.setAttribute('checked', 'checked')
					}

					// STEP: Uncheck the radio input
					radioInput.checked = false
					radioInput.removeAttribute('checked')
					// STEP: Set new radioValue as empty string
					filterInstance.currentValue = ''

					// STEP: Must trigger change event manually
					radioInput.dispatchEvent(new Event('change'), { bubbles: true })
				} else {
					// STEP: Remove checked attribute from all other radio inputs
					const otherInputs = filterElement.querySelectorAll('input') || false
					otherInputs.forEach((input) => {
						if (input !== radioInput) {
							input.removeAttribute('checked')
						}
					})

					// STEP: Check the radio input
					radioInput.checked = true
					radioInput.setAttribute('checked', 'checked')

					// STEP: Set new radioValue as currentValue
					filterInstance.currentValue = radioInput.value
				}

				// STEP: Set classes in case Apply on "Submit" is used, to show the active filter
				updateClass()
			})

			// Only execute filter if filterApplyOn is change
			if (filterApplyOn === 'change') {
				// Listen to the radio change event
				radioInput.addEventListener('change', function (e) {
					// Set currentValue logic moved to click event
					// Set new radioValue as currentValue
					// filterInstance.currentValue = radioValue

					// Get query instance
					const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

					if (!queryInstance) {
						return
					}

					// STEP: Update selected filters
					bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

					// STEP: Execute filter
					bricksUtils.fetchFilterResults(targetQueryId)
				})
			}
		}
	}
})

function bricksRadioFilter() {
	bricksRadioFilterFn.run()
}

// Range filter
const bricksRangeFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-range[data-brx-filter] input[type="number"]',
	frontEndOnly: true,
	eachElement: (rangeInput) => {
		const filterElement = rangeInput.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === filterElement
			}) || false

		if (!filterInstance) {
			return
		}

		// Function to format the display value based on decimalPlaces setting (@since 2.3)
		const formatDisplayValue = (value) => {
			const decimalPlaces = parseInt(filterInstance?.decimalPlaces) || 0
			const numericValue = parseFloat(value)

			if (isNaN(numericValue)) {
				return value
			}

			if (decimalPlaces > 0) {
				return numericValue.toFixed(decimalPlaces)
			}

			return `${numericValue}`
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'
		const filterApplyOn = filterInstance?.filterApplyOn || 'change'

		if (!targetQueryId) {
			return
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			// Listen to the range change event
			rangeInput.addEventListener('change', function (e) {
				// Check if the currentInputType is a min or max
				const currentInputType = rangeInput.classList.contains('min') ? 'min' : 'max'

				// Get high and low range value
				let rangeValueLow =
					currentInputType === 'min'
						? rangeInput.value
						: filterElement.querySelector('input.min[type="number"]').value || 0
				let rangeValueHigh =
					currentInputType === 'max'
						? rangeInput.value
						: filterElement.querySelector('input.max[type="number"]').value || 0

				rangeValueLow = parseFloat(rangeValueLow)
				rangeValueHigh = parseFloat(rangeValueHigh)

				// Must be a number
				if (isNaN(rangeValueLow) || isNaN(rangeValueHigh)) {
					return
				}

				// Tweak the rangeValueLow and rangeValueHigh
				if (rangeValueLow > rangeValueHigh) {
					if (currentInputType === 'min') {
						rangeValueLow = rangeValueHigh
						rangeInput.value = rangeValueLow
					} else {
						rangeValueHigh = rangeValueLow
						rangeInput.value = rangeValueHigh
					}
				}

				// Check min and max value
				if (rangeValueLow < filterInstance.min) {
					rangeValueLow = filterInstance.min
					rangeInput.value = rangeValueLow
				} else if (rangeValueLow > filterInstance.max) {
					rangeValueLow = filterInstance.max
					rangeInput.value = rangeValueLow
				}

				// Decimal format (Must after min and max check) (@since 2.0)
				// if (filterInstance?.decimalPlaces) {
				// 	rangeInput.value = parseFloat(rangeInput.value).toFixed(filterInstance.decimalPlaces)
				// }
				rangeInput.value = formatDisplayValue(rangeInput.value)

				let rangeValue = [rangeValueLow, rangeValueHigh]

				// If rangeValue is the same as currentValue, skip
				if (rangeValue === filterInstance.currentValue) {
					return
				}

				// Set new rangeValue as currentValue
				filterInstance.currentValue = [...rangeValue]

				// If currentValue is not same as originalValue, set hasChanged to true (#86c3vf095; @since 2.2)
				if (
					filterInstance.currentValue[0] !== filterInstance.originalValue[0] ||
					filterInstance.currentValue[1] !== filterInstance.originalValue[1]
				) {
					filterInstance.hasChanged = true
				} else {
					filterInstance.hasChanged = false
				}

				// Only execute filter if filterApplyOn is change
				if (filterApplyOn !== 'change') {
					return
				}

				// Get query instance
				const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

				if (!queryInstance) {
					return
				}

				// STEP: Update selected filters
				bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

				// STEP: Execute filter
				bricksUtils.fetchFilterResults(targetQueryId)
			})

			// Normalize manual typing when leaving the field (#86c3vge15; @since 2.3)
			rangeInput.addEventListener('blur', function () {
				// Avoid bad input, if it's not a number, reset to currentValue
				if (isNaN(parseFloat(rangeInput.value))) {
					rangeInput.value = rangeInput.classList.contains('min')
						? filterInstance.currentValue[0]
						: filterInstance.currentValue[1]
				}
				rangeInput.value = formatDisplayValue(rangeInput.value)
			})
		}
	}
})

function bricksRangeFilter() {
	bricksRangeFilterFn.run()
}

/**
 * Range filter element DOM is replaced but the currentValue is not updated.
 * Need to update the filter currentValue from the min and max input or the slider UI cannot get calculate the correct width.
 *
 * @since 1.12
 */
function bricksRangeValueUpdater() {
	// Listen to bricks/ajax/query_result/displayed, check if currentValue changed (popstate), update the value
	document.addEventListener('bricks/ajax/query_result/displayed', function (event) {
		const targetQueryId = event.detail.queryId || false

		if (!targetQueryId) {
			return
		}

		// Get all filters for the targetQueryId
		const allFilters = bricksUtils.getFiltersForQuery(targetQueryId)

		// Find all range type filters
		const rangeFilters = allFilters.filter((filter) => {
			return filter.filterType === 'range'
		})

		if (rangeFilters.length > 0) {
			// Loop through all range filters and update currentValue from min and max input
			rangeFilters.forEach((filter) => {
				const filterElement = filter.filterElement
				const minInput = filterElement.querySelector('input.min[type="number"]')
				const maxInput = filterElement.querySelector('input.max[type="number"]')

				if (!minInput || !maxInput) {
					return
				}

				// Get min and max value
				const minVal = parseFloat(minInput.value) || 0
				const maxVal = parseFloat(maxInput.value) || 0

				// Update currentValue
				filter.currentValue = [minVal, maxVal]
			})
		}
	})
}

// Range slider UI, Just to sync the range value with the input value
const bricksRangeSliderUIFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-range[data-brx-filter] input[type="range"]',
	frontEndOnly: true,
	eachElement: (rangeInput) => {
		// Get filterElement
		const filterElement = rangeInput.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === filterElement
			}) || false

		if (!filterInstance) {
			return
		}

		// Get page direction
		const isRTL = document.dir === 'rtl' || document.documentElement.dir === 'rtl'

		const maxInput = filterElement.querySelector('input.max[type="range"]')
		const minInput = filterElement.querySelector('input.min[type="range"]')

		const updateText = (rangeInputType, rangeValue) => {
			// Update the value in .value-wrap .upper or .lower
			const valueWrapper = filterElement.querySelector(`.value-wrap .${rangeInputType} .value`)
			if (valueWrapper) {
				// Ensure rangeValue is an integer
				rangeValue = parseFloat(rangeValue) || 0

				if (filterInstance?.decimalPlaces) {
					rangeValue = rangeValue.toLocaleString('en-US', {
						minimumFractionDigits: filterInstance.decimalPlaces, // Always show two decimal places
						maximumFractionDigits: filterInstance.decimalPlaces // Limit to two decimal places
					})
				}

				// Check if the filterInstance has thousands and separator
				if (filterInstance?.thousands && filterInstance?.separator) {
					rangeValue = rangeValue.toLocaleString('en-US').replaceAll(',', filterInstance?.separator)
				} else if (filterInstance?.thousands) {
					rangeValue = rangeValue.toLocaleString('en-US')
				}
				valueWrapper.innerText = rangeValue
			}
		}

		const updateTrack = (rangeInputType, rangeValue) => {
			const track = filterElement.querySelector('.slider-track')
			if (track) {
				// Ensure currentValue is an array and has at least two elements
				if (!Array.isArray(filterInstance.currentValue) || filterInstance.currentValue.length < 2) {
					return
				}

				let minVal = rangeInputType === 'lower' ? rangeValue : filterInstance.currentValue[0]
				let maxVal = rangeInputType === 'lower' ? filterInstance.currentValue[1] : rangeValue

				// Check boundaries
				if (minVal >= maxVal) {
					minVal = maxVal
				}
				if (maxVal <= minVal) {
					maxVal = minVal
				}

				// Get the min and max value from the input[type="range"]
				let filterMin = parseFloat(minInput.getAttribute('min') || 0)
				let filterMax = parseFloat(maxInput.getAttribute('max') || 0)

				// Avoid division by zero
				if (filterMin === filterMax) {
					filterMax = filterMin + 1
				}

				// Ensure minVal is not less than filterMin
				if (minVal < filterMin) {
					minVal = filterMin
				}

				// Ensure maxVal is not more than filterMax
				if (maxVal > filterMax) {
					maxVal = filterMax
				}

				const minPercent = ((minVal - filterMin) / (filterMax - filterMin)) * 100
				const maxPercent = ((maxVal - filterMin) / (filterMax - filterMin)) * 100

				// Update track style if percentages are valid
				if (!isNaN(minPercent) && !isNaN(maxPercent)) {
					const width = maxPercent - minPercent

					// Hide the track if the width is less than 2%. Otherwise, there might be a small line visible
					if (width <= 2) {
						track.style.visibility = 'hidden'
					} else {
						track.style.visibility = 'visible'
					}
					// RTL: Offset from right
					if (isRTL) {
						track.style.right = `${minPercent}%`
					} else {
						track.style.left = `${minPercent}%`
					}

					track.style.width = `${width}%`
				}
			}
		}

		// Listen to the range input event to update the Text in .value-wrap .upper or .lower
		rangeInput.addEventListener('input', function (e) {
			// Get the range value
			const rangeValue = parseFloat(e.target.value) || 0
			const rangeInputType = rangeInput.classList.contains('min') ? 'lower' : 'upper'

			// Update the text
			updateText(rangeInputType, rangeValue)

			// Update track (@since 1.11)
			updateTrack(rangeInputType, rangeValue)
		})

		// Listen to the range change event to sync the value to the input[type="number"]
		rangeInput.addEventListener('change', function (e) {
			// Check if the currentInputType is a min or max
			const currentInputType = rangeInput.classList.contains('min') ? 'lower' : 'upper'

			// Get high and low range value
			let rangeValueLow =
				currentInputType === 'lower'
					? rangeInput.value
					: filterElement.querySelector('input.min[type="range"]').value || 0
			let rangeValueHigh =
				currentInputType === 'upper'
					? rangeInput.value
					: filterElement.querySelector('input.max[type="range"]').value || 0

			// Convert rangeValueLow and rangeValueHigh to float
			rangeValueLow = parseFloat(rangeValueLow)
			rangeValueHigh = parseFloat(rangeValueHigh)

			// Tweak the rangeValueLow and rangeValueHigh
			if (rangeValueLow > rangeValueHigh) {
				if (currentInputType === 'lower') {
					rangeValueLow = rangeValueHigh
					rangeInput.value = rangeValueLow
				} else {
					rangeValueHigh = rangeValueLow
					rangeInput.value = rangeValueHigh
				}
			}

			// Check min and max value
			if (rangeValueLow < filterInstance.min) {
				rangeInput.value = rangeValueLow
			} else if (rangeValueLow > filterInstance.max) {
				rangeInput.value = rangeValueLow
			}

			// Update the text
			updateText(currentInputType, rangeInput.value)

			// Sync the range value to the input[type="number"]
			const rangeInputNumberLow = filterElement.querySelector(`input.min[type="number"]`)
			if (rangeInputNumberLow) {
				rangeInputNumberLow.value = rangeValueLow
			}

			const rangeInputNumberHigh = filterElement.querySelector(`input.max[type="number"]`)
			if (rangeInputNumberHigh) {
				rangeInputNumberHigh.value = rangeValueHigh
			}

			if (currentInputType === 'lower') {
				rangeInputNumberLow.dispatchEvent(new Event('change'))
			} else {
				rangeInputNumberHigh.dispatchEvent(new Event('change'))
			}
		})
	}
})

function bricksRangeSliderUI() {
	bricksRangeSliderUIFn.run()
}

// Range filter custom stepper UI (@since 2.3)
const bricksRangeInputStepperFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-range[data-brx-filter] .brx-stepper-button',
	frontEndOnly: true,
	eachElement: (button) => {
		const filterElement = button.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === filterElement
			}) || false

		if (!filterInstance) {
			return
		}

		// Get the precision from the step value. E.g. 0.01 will return 2, 0.001 will return 3, 1 will return 0
		const getPrecision = (step) => {
			const stepString = `${step}`

			if (stepString.includes('e-')) {
				const parts = stepString.split('e-')
				return parseInt(parts[1]) || 0
			}

			const parts = stepString.split('.')
			return parts[1] ? parts[1].length : 0
		}

		const getTargetInput = () => {
			const inputId = button.dataset.inputId || ''

			if (inputId) {
				const input = filterElement.querySelector(`#${inputId}`)
				if (input) {
					return input
				}
			}

			return button.closest('.brx-number-wrap')?.querySelector('input[type="number"]') || false
		}

		// Core function to step up or down the value
		const stepValue = (direction) => {
			const input = getTargetInput()

			if (!input || input.disabled) {
				return
			}

			const stepFromInput = parseFloat(input.getAttribute('step'))
			const step = !isNaN(stepFromInput) && stepFromInput > 0 ? stepFromInput : 1

			const decimalPlaces = parseInt(filterInstance?.decimalPlaces) || 0
			const precision = Math.max(decimalPlaces, getPrecision(step))

			let current = parseFloat(input.value)
			if (isNaN(current)) {
				current = parseFloat(input.getAttribute('min'))
			}
			if (isNaN(current)) {
				current = 0
			}

			const min = parseFloat(input.getAttribute('min'))
			const max = parseFloat(input.getAttribute('max'))

			let next = direction === 'up' ? current + step : current - step

			if (!isNaN(min)) {
				next = Math.max(next, min)
			}
			if (!isNaN(max)) {
				next = Math.min(next, max)
			}

			next = precision > 0 ? Number(next.toFixed(precision)) : Math.round(next)
			input.value = decimalPlaces > 0 ? next.toFixed(decimalPlaces) : `${next}`

			// Keep existing filter logic untouched
			input.dispatchEvent(new Event('change', { bubbles: true }))
		}

		button.addEventListener('click', (e) => {
			e.preventDefault()
			const direction = button.dataset.stepperDirection === 'down' ? 'down' : 'up'
			stepValue(direction)
		})

		// A11y: Arrow key support on focused button
		button.addEventListener('keydown', (e) => {
			if (e.key === 'ArrowUp') {
				e.preventDefault()
				stepValue('up')
			}

			if (e.key === 'ArrowDown') {
				e.preventDefault()
				stepValue('down')
			}
		})
	}
})

function bricksRangeInputStepper() {
	bricksRangeInputStepperFn.run()
}

// Checkbox filter
const bricksCheckboxFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-checkbox[data-brx-filter] input',
	frontEndOnly: true,
	eachElement: (checkboxInput) => {
		const filterElement = checkboxInput.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === filterElement
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'
		const filterApplyOn = filterInstance?.filterApplyOn || 'change'
		const autoCheck = filterInstance?.autoCheck || false
		const hierarchy = filterInstance?.hierarchy || false

		if (!targetQueryId) {
			return
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			// Listen to the checkbox change event
			checkboxInput.addEventListener('change', function (e) {
				// STEP: Handle multiple checkbox
				const checkboxValue = e.target.value
				// Get the currentValue - ensure we're working with an array
				const currentValue = Array.isArray(filterInstance.currentValue)
					? [...filterInstance.currentValue]
					: []
				// Get the index of the checkboxValue in currentValue
				const index = currentValue.indexOf(checkboxValue)
				let childrenCheckboxes = []

				const updateClass = (cb) => {
					const liNode = cb.closest('li') || false
					if (liNode) {
						if (cb.checked) {
							liNode.classList.add('brx-option-active')
						}
						if (!cb.checked) {
							liNode.classList.remove('brx-option-active')
						}
					}

					const labelNode = cb.closest('label') || false
					if (labelNode) {
						if (cb.checked) {
							labelNode.classList.add('brx-option-active')
						}
						if (!cb.checked) {
							labelNode.classList.remove('brx-option-active')
						}
					}

					const spanNode = cb.nextElementSibling || false
					if (spanNode) {
						if (cb.checked) {
							spanNode.classList.add('brx-option-active')
						}
						if (!cb.checked) {
							spanNode.classList.remove('brx-option-active')
						}
					}
				}

				if (autoCheck && hierarchy) {
					// Auto check/uncheck children @since 1.11
					let liNode = e.target.closest('li[data-depth]') || false
					if (liNode) {
						let currentDepth = parseInt(liNode.dataset.depth) || 0
						let nextLiNode = liNode.nextElementSibling || false

						// STEP: Loop through the next li nodes to get all children checkboxes
						while (nextLiNode) {
							let nextLiDepth = parseInt(nextLiNode.dataset.depth) || 0
							if (nextLiDepth <= currentDepth) {
								break
							}

							let nextCheckbox = nextLiNode.querySelector('input[type="checkbox"]')
							if (nextCheckbox) {
								childrenCheckboxes.push(nextCheckbox)
							}

							nextLiNode = nextLiNode.nextElementSibling || false
						}
					}
				}

				if (!e.target.checked && index > -1) {
					// Remove the checkboxValue from currentValue as user unchecked the checkbox and it's in currentValue
					if (index > -1) {
						currentValue.splice(index, 1)

						// Auto check/uncheck children checkboxes (@since 1.11)
						if (autoCheck && hierarchy && childrenCheckboxes.length) {
							// Remove the children checkboxes from currentValue
							childrenCheckboxes.forEach((childCheckbox) => {
								// Uncheck the child checkbox
								childCheckbox.checked = false
								// Update class to avoid button mode no visual indication
								updateClass(childCheckbox)
								const childCheckboxValue = childCheckbox.value
								const childIndex = currentValue.indexOf(childCheckboxValue)
								if (childIndex > -1) {
									currentValue.splice(childIndex, 1)
								}
							})
						}
					}
				}

				if (e.target.checked && index === -1) {
					// Add the checkboxValue to currentValue as user checked the checkbox and it's not in currentValue
					currentValue.push(checkboxValue)

					// Auto check/uncheck children checkboxes
					if (autoCheck && hierarchy && childrenCheckboxes.length) {
						// Add the children checkboxes to currentValue
						childrenCheckboxes.forEach((childCheckbox) => {
							// Check the child checkbox
							childCheckbox.checked = true
							// Update class to avoid button mode no visual indication
							updateClass(childCheckbox)
							const childCheckboxValue = childCheckbox.value
							const childIndex = currentValue.indexOf(childCheckboxValue)
							if (childIndex === -1) {
								currentValue.push(childCheckboxValue)
							}
						})
					}
				}

				// Save currentValue as currentValue
				filterInstance.currentValue = [...currentValue]

				// Set the taxonomy name as the filterElement name
				filterInstance.filterElement.name = checkboxInput.name

				// Update class to avoid button mode no visual indication
				updateClass(checkboxInput)

				// Only execute filter if filterApplyOn is change
				if (filterApplyOn !== 'change') {
					return
				}

				// Get query instance
				const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

				if (!queryInstance) {
					return
				}

				// STEP: Update selected filters
				bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

				// STEP: Execute filter
				bricksUtils.fetchFilterResults(targetQueryId)
			})
		}
	}
})

function bricksCheckboxFilter() {
	bricksCheckboxFilterFn.run()
}

// Datepicker filter
const bricksDatePickerFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-datepicker input[data-brx-filter]',
	frontEndOnly: true,
	eachElement: (element) => {
		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === element
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'
		const filterSource = filterInstance?.filterSource || false
		const filterApplyOn = filterInstance?.filterApplyOn || 'change'

		if (!targetQueryId) {
			return
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			let flatpickrOptions = element.dataset?.bricksDatepickerOptions || false

			if (flatpickrOptions) {
				// STEP: Destroy flatpickr if it's already initialized
				if (filterInstance.datepicker) {
					filterInstance.datepicker.destroy()
				}

				// STEP: Init flatpickr
				flatpickrOptions = JSON.parse(flatpickrOptions)
				flatpickrOptions.disableMobile = true

				// Setup clear button function for datepicker (@since 2.2)
				const setupClearButton = () => {
					// Setup clear button
					const clearButton = filterInstance.filterElement.parentElement?.querySelector('.icon')

					if (clearButton) {
						const clearValue = () => {
							bricksUtils.resetFilterValue(filterInstance)

							// Only execute filter if filterApplyOn is change
							if (filterApplyOn !== 'change') {
								return
							}

							// Get query instance
							const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

							if (!queryInstance) {
								return
							}

							// STEP: Update selected filters
							bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

							// STEP: Execute filter
							bricksUtils.fetchFilterResults(targetQueryId)
						}

						// Click event
						clearButton.addEventListener('click', function (e) {
							e.preventDefault()
							clearValue()
						})

						// Keydown event for accessibility
						clearButton.addEventListener('keydown', function (e) {
							if (e.key === 'Enter' || e.key === ' ') {
								e.preventDefault()
								clearValue()
							}
						})
					}
				}

				// Listen to the flatpickr ready event to set the aria-label and id
				flatpickrOptions.onReady = (a, b, fp) => {
					// Add the aria-label to flatpickr altInput
					const ariaLabel = element.getAttribute('aria-label') || 'Date'
					fp.altInput.setAttribute('aria-label', ariaLabel)

					// Add the id tag to flatpicker altInput or ID level syles will not work
					if (element.id) {
						fp.altInput.setAttribute('id', element.id)
						// Remove the id tag on the original input to avoid duplicate id
						element.removeAttribute('id')
					}
					// Setup clear button
					setupClearButton()
				}

				// Listen to the datepicker change event
				flatpickrOptions.onChange = (selectedDates, dateStr, instance) => {
					// Get the flatpickrType
					const flatpickrType = instance.config.mode

					// Check if time is enabled
					const timeEnabled = instance.config.enableTime

					// If this is a single type, ensure selectedDates is an array and length is 1
					if (flatpickrType === 'single') {
						if (!Array.isArray(selectedDates) || selectedDates.length !== 1) {
							return
						}
					} else if (flatpickrType === 'range') {
						// If this is a range type, ensure selectedDates is an array and length is 2
						if (!Array.isArray(selectedDates) || selectedDates.length !== 2) {
							return
						}
					}

					// Use flatpickr's internal format (Y-m-d H:i) for consistency (#86c2crr7d; @since 2.3)
					// This ensures the format is always the same regardless of display format
					let ymdDates = []

					selectedDates.forEach((dateObj) => {
						let month = dateObj.getMonth() + 1
						let day = dateObj.getDate()
						let year = dateObj.getFullYear()

						// Add leading zero if necessary
						if (month < 10) {
							month = '0' + month
						}

						if (day < 10) {
							day = '0' + day
						}

						let formattedDate = `${year}-${month}-${day}`

						// Parse time
						if (timeEnabled) {
							let hour = dateObj.getHours()
							let minute = dateObj.getMinutes()

							if (hour < 10) {
								hour = '0' + hour
							}

							if (minute < 10) {
								minute = '0' + minute
							}

							formattedDate += ` ${hour}:${minute}`
						}

						ymdDates.push(formattedDate)
					})

					// Use comma to separate the dates
					let bricksDateStr = ymdDates.join(',')

					// Compare current bricksDateStr with currentValue, if it's the same, skip
					if (bricksDateStr === filterInstance.currentValue) {
						return
					}

					// Save bricksDateStr as currentValue
					filterInstance.currentValue = bricksDateStr

					bricksUtils.updateDatepickerFilterIconVisibility(filterInstance)

					// Only execute filter if filterApplyOn is change
					if (filterApplyOn !== 'change') {
						return
					}

					// Get query instance
					const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

					if (!queryInstance) {
						return
					}

					// STEP: Update selected filters
					bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

					// STEP: Execute filter
					bricksUtils.fetchFilterResults(targetQueryId)
				}

				// Init flatpickr & save it to filterInstance
				filterInstance.datepicker = flatpickr(element, flatpickrOptions)
			}
		}
	}
})

function bricksDatePickerFilter() {
	bricksDatePickerFilterFn.run()
}

// Active filters (@since 1.11)
const bricksActiveFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-filter-active-filters[data-brx-filter] [data-filter-id]',
	frontEndOnly: true,
	eachElement: (clearButton) => {
		const filterElement = clearButton.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === filterElement
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'
		const filterSource = filterInstance?.filterSource || false
		const filterApplyOn = filterInstance?.filterApplyOn || 'change'

		if (!targetQueryId) {
			return
		}

		const targetFilterId = clearButton.dataset.filterId || false
		const clearValue = clearButton.dataset.filterValue || false
		const urlParam = clearButton.dataset.filterUrlParam || ''

		if (!targetFilterId) {
			return
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			clearButton.addEventListener('click', function (e) {
				// Get the targetFilterInstance
				const targetFilterInstance =
					Object.values(window.bricksData.filterInstances).find((filter) => {
						return (
							filter.filterId === targetFilterId ||
							(urlParam !== '' &&
								urlParam === filter.filterNiceName &&
								clearValue == filter.currentValue &&
								targetQueryId === filter.targetQueryId) // Maybe same is another filter element (@since 1.12)
						)
					}) || false

				if (!targetFilterInstance) {
					return
				}

				// STEP: Reset the targetFilterInstance value
				bricksUtils.resetFilterValue(targetFilterInstance, clearValue)

				// STEP: Update selected filters for the targetFilterInstance
				bricksUtils.updateSelectedFilters(targetQueryId, targetFilterInstance)

				// STEP: Execute filter
				bricksUtils.fetchFilterResults(targetQueryId)
			})
		}
	}
})

function bricksActiveFilter() {
	bricksActiveFilterFn.run()
}

// Reset filter
const bricksResetFilterFn = new BricksFunction({
	parentNode: document,
	selector: `.brxe-filter-submit[type='reset'][data-brx-filter]`,
	frontEndOnly: true,
	eachElement: (element) => {
		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === element
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'

		if (!targetQueryId) {
			return
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			// Reset button
			element.addEventListener('click', function (e) {
				// Get all filter instances with the same targetQueryId
				const filterIntances = Object.values(window.bricksData.filterInstances).filter((filter) => {
					return filter.targetQueryId === targetQueryId
				})
				const excludeFilterIds = bricksUtils.getFilterExcludeIds(filterInstance)

				const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

				if (!filterIntances.length) {
					return
				}

				// Reset all filter elements value
				filterIntances.forEach((filter) => {
					// Keep excluded filters untouched; they define the remaining page/search context after reset. (#86ca6wqtg; @since 2.4)
					if (excludeFilterIds.includes(filter.filterId)) {
						return
					}

					bricksUtils.resetFilterValue(filter)
					// Dont trigger the change event here or it will retrigger every filter
				})

				// Do not execute filter if it's a live search
				if (queryInstance?.isLiveSearch) {
					// Hide the [data-brx-ls-wrapper]
					bricksUtils.hideLiveSearchWrapper(targetQueryId)
				}

				// STEP: Update selected filters
				bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

				// STEP: Execute filter
				bricksUtils.fetchFilterResults(targetQueryId)
			})
		} else {
			// Page refresh - Not in Beta
		}
	}
})

function bricksResetFilter() {
	bricksResetFilterFn.run()
}

// Apply filter
const bricksApplyFilterFn = new BricksFunction({
	parentNode: document,
	selector: `.brxe-filter-submit[type='submit'][data-brx-filter]`,
	frontEndOnly: true,
	eachElement: (element) => {
		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === element
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'
		const redirectTo = filterInstance?.redirectTo || false
		const newTab = filterInstance?.newTab || false

		if (!targetQueryId) {
			return
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			// Apply button
			element.addEventListener('click', function (e) {
				// Get query instance and check if it's a live search
				const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

				if (!queryInstance) {
					return
				}

				// STEP: Update selected filters
				bricksUtils.updateSelectedFilters(targetQueryId, filterInstance)

				if (!redirectTo) {
					// Dispatch filter submit start event (@since 2.4)
					document.dispatchEvent(
						new CustomEvent('bricks/filter/submit/start', {
							detail: { queryId: targetQueryId, filterId: filterId }
						})
					)

					// Execute filter
					bricksUtils
						.fetchFilterResults(targetQueryId)
						// Dispatch filter submit end event (@since 2.4)
						.finally(() => {
							document.dispatchEvent(
								new CustomEvent('bricks/filter/submit/end', {
									detail: { queryId: targetQueryId, filterId: filterId }
								})
							)
						})
				} else {
					// Generate the URL params and redirect
					let params = bricksUtils.buildFilterUrlParams(targetQueryId)

					const urlObj = bricksUtils.mergeUrlWithParams(redirectTo, params)
					let url = urlObj.toString()

					if (newTab) {
						window.open(`${url}`, '_blank')
					} else {
						window.location.href = url
					}
				}
			})
		} else {
			// Page refresh - Not in Beta
		}
	}
})

function bricksApplyFilter() {
	bricksApplyFilterFn.run()
}

// Pagination filter
const bricksPaginationFilterFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-pagination[data-brx-filter] a',
	frontEndOnly: true,
	eachElement: (button) => {
		// Get filterElement
		const filterElement = button.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		// Get filterInstance
		const filterInstance =
			Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterElement === filterElement
			}) || false

		if (!filterInstance) {
			return
		}

		const filterId = filterInstance?.filterId || false
		const targetQueryId = filterInstance?.targetQueryId || false
		const filterMethod = filterInstance?.filterMethod || 'ajax'

		let allFilters = bricksUtils.getFiltersForQuery(filterInstance.targetQueryId)

		// Exclude all pagination filters
		allFilters = allFilters.filter((filter) => {
			return filter.filterType !== 'pagination'
		})

		// Exit if there are no filters
		if (!allFilters.length) {
			filterElement.removeAttribute('data-brx-filter')
			return
		}

		const updateAllPaginationInstancesCurrentPage = (targetQueryId, currentPage) => {
			// Get all pagination instances with the same targetQueryId
			const paginationIntances = Object.values(window.bricksData.filterInstances).filter(
				(filter) => {
					return filter.targetQueryId === targetQueryId && filter.filterType === 'pagination'
				}
			)

			if (!paginationIntances.length) {
				return
			}

			// Update all pagination instances currentValue
			paginationIntances.forEach((pagination) => {
				pagination.currentValue = currentPage
			})
		}

		// STEP: Base on the filterMethod, decide to use AJAX or change the URL
		if (filterMethod === 'ajax') {
			button.addEventListener('click', function (e) {
				e.preventDefault()

				// Fix clicking on custom icon not working issue
				const link = e.currentTarget

				const queryInstance = window.bricksData.queryLoopInstances[targetQueryId] || false

				if (!queryInstance) {
					return
				}

				const href = link.href || false

				// If href is empty, skip
				if (!href) {
					return
				}

				let clickedPageNumber = bricksUtils.getPageNumberFromUrl(href)

				// Skip, if clickedPageNumber is less than 1
				if (parseInt(clickedPageNumber) < 1) {
					return
				}

				/**
				 * STEP: Update all pagination instances currentValue
				 * If multiple pagination elements targeting the same query, we need to update all of them.
				 * Otherwise the 'paged' param will always follow the last pagination instance's currentValue (#86bxet3c3).
				 */
				updateAllPaginationInstancesCurrentPage(targetQueryId, clickedPageNumber)

				let overwriteURL = new URL(link.href)
				// Remove all search params
				overwriteURL.search = ''

				// STEP: Update selected filters
				bricksUtils.updateSelectedFilters(targetQueryId, filterInstance, overwriteURL)

				// STEP: Execute filter
				bricksUtils.fetchFilterResults(targetQueryId)
			})
		} else {
			// Page refresh - Not in Beta
		}
	}
})

function bricksPaginationFilter() {
	bricksPaginationFilterFn.run()
}

/**
 * A11y handler for option text (radio button mode)
 *
 * @since 1.10
 */
const bricksFiltersA11yHandlerFn = new BricksFunction({
	parentNode: document,
	selector: 'span.brx-option-text[tabindex]',
	frontEndOnly: true,
	eachElement: (span) => {
		const linkedInput = span.previousElementSibling || false

		if (!linkedInput) {
			return
		}

		if (linkedInput.tagName !== 'INPUT') {
			return
		}

		// Listen to keydown event
		span.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault()
				linkedInput.click()
			}
		})
	}
})

function bricksFiltersA11yHandler() {
	bricksFiltersA11yHandlerFn.run()
}

/**
 *
 * @since 2.0
 */
const bricksActiveFiltersCountDDFn = new BricksFunction({
	parentNode: document,
	selector: 'span[data-brx-af-count][data-brx-af-dd]',
	frontEndOnly: true,
	eachElement: (element) => {
		const dynamicTag = element.dataset.brxAfDd || false
		const targetQueryId = element.dataset.brxAfCount || false

		if (!targetQueryId || !dynamicTag) {
			return
		}

		// STEP: Save each registered elements in window.bricksData.activeFiltersCountInstances
		if (!window.bricksData.activeFiltersCountInstances) {
			window.bricksData.activeFiltersCountInstances = []
		}

		// Check if this element already exists in window.bricksData.activeFiltersCountInstances
		let foundInstance = window.bricksData.activeFiltersCountInstances.find((instance) => {
			return instance.element === element
		})

		if (foundInstance) {
			// Remove data-brx-af-dd
			element.removeAttribute('data-brx-af-dd')
			return
		}

		// Register instance
		window.bricksData.activeFiltersCountInstances.push({
			element: element,
			targetQueryId: targetQueryId,
			dynamicTag: dynamicTag
		})

		// Set data-brx-af-dd to true, beautify
		element.dataset.brxAfDd = true
	}
})

function bricksActiveFiltersCountDD() {
	bricksActiveFiltersCountDDFn.run()
}

/**
 * Show More/Less functionality for Filter Radio and Checkbox
 *
 * @since 2.3
 */
const bricksFilterShowMoreLessFn = new BricksFunction({
	parentNode: document,
	selector: '.brx-show-more-less-button[data-action="show-more-less"]',
	frontEndOnly: true,
	eachElement: (button) => {
		const filterElement = button.closest('[data-brx-filter]') || false

		if (!filterElement) {
			return
		}

		// Get show more/less settings from data attribute
		const showMoreLessData = filterElement.dataset.brxShowMoreLess || false

		if (!showMoreLessData) {
			return
		}

		const settings = JSON.parse(showMoreLessData) || {}
		const initialItems = parseInt(settings['initial-items']) || 0
		const totalItems = parseInt(settings['total-items']) || 0
		const showMoreText = settings['show-more-text'] || 'Show more'
		const showLessText = settings['show-less-text'] || 'Show less'
		const hasCheckedBeyondInitial = settings['has-checked-beyond-initial'] === 'true'

		// Get all option list items (excluding the show more/less wrapper)
		const allOptions = filterElement.querySelectorAll('li:not(.brx-show-more-less-wrapper)')

		// Track current state
		let isExpanded = hasCheckedBeyondInitial

		// Function to update button text and aria-expanded
		const updateButton = () => {
			if (isExpanded) {
				button.textContent = showLessText
				button.setAttribute('aria-expanded', 'true')
			} else {
				// Calculate remaining items to show
				const remainingItems = totalItems - initialItems
				const textWithNumber = showMoreText.replace('%number%', remainingItems)
				button.textContent = textWithNumber
				button.setAttribute('aria-expanded', 'false')
			}
		}

		// Function to show/hide options
		const toggleOptions = () => {
			if (isExpanded) {
				// Collapse: Hide all items beyond initial limit
				allOptions.forEach((option, index) => {
					if (index >= initialItems) {
						option.classList.add('brx-option-hidden')
					}
				})
				filterElement.classList.remove('brx-options-expanded')
				isExpanded = false
			} else {
				// Expand: Show all items
				allOptions.forEach((option) => {
					option.classList.remove('brx-option-hidden')
				})
				filterElement.classList.add('brx-options-expanded')
				isExpanded = true
			}

			updateButton()
		}

		// Initialize: Hide items beyond initial limit if not already expanded
		if (!isExpanded) {
			allOptions.forEach((option, index) => {
				if (index >= initialItems) {
					option.classList.add('brx-option-hidden')
				}
			})
		}

		// Set initial button state
		updateButton()

		// Click event
		button.addEventListener('click', (e) => {
			e.preventDefault()
			toggleOptions()
		})

		// Keyboard accessibility
		button.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault()
				toggleOptions()
			}
		})

		// Cleanup: data-brx-show-more-less to true
		filterElement.dataset.brxShowMoreLess = 'true'
	}
})

function bricksFilterShowMoreLess() {
	bricksFilterShowMoreLessFn.run()
}

/**
 * Live search wrapper listeners
 * Logic to show/hide the [data-brx-ls-wrapper] based certain conditions
 */
function bricksLiveSearchWrappersInit() {
	// bricks/ajax/start - Without this, AJAX loader inside the live search wrapper will be hidden (#86c88rgdy)
	// bricks/ajax/query_result/displayed - Determine to show/hide the wrapper after AJAX end (#86c82jqmt)
	const hideOrShowEvents = ['bricks/ajax/query_result/displayed', 'bricks/ajax/start']

	hideOrShowEvents.forEach((eventName) => {
		// Listen to hideOrShowEvents and run hideOrShowLiveSearchWrapper if queryInstance.isLiveSearch && !isPopStateCall
		document.addEventListener(eventName, function (event) {
			// Get the queryId from the event
			const queryId = event.detail.queryId || false
			const isPopStateCall = event.detail?.isPopState || false

			if (!queryId || isPopStateCall) {
				return
			}

			const queryInstance = window.bricksData.queryLoopInstances[queryId] || false

			if (!queryInstance?.isLiveSearch) {
				return
			}

			// Use the new function to check current state and show/hide wrapper
			bricksUtils.hideOrShowLiveSearchWrapper(queryId)
		})
	})

	// Listen to document click event and maybe hide the [data-brx-ls-wrapper]
	document.addEventListener('click', (e) => {
		const activeElement = e.target
		// Button feedback can detach the clicked child before this listener runs. Keep its original wrapper ancestry.
		const closestLiveSearchWrapper = e
			.composedPath()
			.find((element) => element.matches?.('[data-brx-ls-wrapper]'))

		const allTargetQueryIds = bricksUtils.currentPageTargetQueryIds()

		allTargetQueryIds.forEach((targetQueryId) => {
			// Get all filter instances with the same targetQueryId and filterElement that is the current activeElement
			const filterIntances = Object.values(window.bricksData.filterInstances).filter((filter) => {
				return filter.targetQueryId === targetQueryId && filter.filterElement === activeElement
			})

			// Do nothing if no filterIntances found
			if (filterIntances.length) {
				return
			}

			// Do nothing if the closestLiveSearchWrapper is the same targetQueryId
			if (closestLiveSearchWrapper?.dataset?.brxLsWrapper === targetQueryId) {
				return
			}

			// Do nothing if this element has "icon" class, for filter-search element
			if (activeElement.classList.contains('icon')) {
				return
			}

			// This is not related to the current filter, hide the [data-brx-ls-wrapper]
			bricksUtils.hideLiveSearchWrapper(targetQueryId)
		})
	})
}

/**
 * Disable all filter elements when the AJAX request is started and enable them when the AJAX request is ended
 * Avoid multiple AJAX requests at the same time
 */
function bricksDisableFiltersOnLoad() {
	document.addEventListener('bricks/ajax/start', function (event) {
		// Get the queryId from the event
		const queryId = event.detail.queryId || false

		if (!queryId) {
			return
		}

		const queryInstance = window.bricksData.queryLoopInstances[queryId] || false

		if (!queryInstance) {
			return
		}

		// Get all filter instances with the same targetQueryId
		const filterIntances = Object.values(window.bricksData.filterInstances).filter((filter) => {
			return filter.targetQueryId === queryId
		})

		if (!filterIntances.length) {
			return
		}

		// Disable all filter elements
		filterIntances.forEach((filter) => {
			if (filter.filterType === 'search') {
				return
			}

			const filterElement = filter.filterElement ?? false
			if (!filterElement) {
				return
			}

			filterElement.disabled = true
			filterElement.classList.add('brx-filter-disabled')

			// Disable all input inside the filterElement
			filterElement.querySelectorAll('input, button').forEach((control) => {
				control.dataset.brxDisabled = control.disabled ? 'true' : 'false'
				control.disabled = true
			})
		})
	})

	document.addEventListener('bricks/ajax/end', function (event) {
		// Get the queryId from the event
		const queryId = event.detail.queryId || false

		if (!queryId) {
			return
		}

		const queryInstance = window.bricksData.queryLoopInstances[queryId] || false

		if (!queryInstance) {
			return
		}

		// Get all filter instances with the same targetQueryId
		const filterIntances = Object.values(window.bricksData.filterInstances).filter((filter) => {
			return filter.targetQueryId === queryId
		})

		if (!filterIntances.length) {
			return
		}

		// Enable all filter elements
		filterIntances.forEach((filter) => {
			const filterElement = filter.filterElement ?? false

			if (!filterElement) {
				return
			}

			filterElement.disabled = false
			filterElement.classList.remove('brx-filter-disabled')

			// Enable all input inside the filterElement
			filterElement.querySelectorAll('input, button').forEach((control) => {
				control.disabled = control.dataset.brxDisabled === 'true'
				delete control.dataset.brxDisabled
			})
		})
	})
}

/**
 * Initialize the browser state when the page is loaded
 *
 * @since 1.11
 */
function bricksInitBrowserState() {
	// Only in actual frontend
	if (!bricksIsFrontend) {
		return
	}

	let instancesValue = {}
	let allTargetQueryIds = bricksUtils.currentPageTargetQueryIds()

	// Save each filter current value in instancesValue for each targetQueryId
	allTargetQueryIds.forEach((targetQueryId) => {
		instancesValue[targetQueryId] = {}

		let allFilters = bricksUtils.getFiltersForQuery(targetQueryId)
		allFilters.forEach((filterInstance) => {
			instancesValue[targetQueryId][filterInstance.filterId] = filterInstance.currentValue
		})
	})

	// clone the window.bricksData.selectedFilters
	let selectedFilters = window.bricksData.selectedFilters

	window.history.replaceState(
		{
			isBricksFilter: true,
			targetQueryId: '',
			selectedFilters: selectedFilters,
			instancesValue: instancesValue
		},
		'',
		window.location.href
	)
}

/**
 * Listen to popstate event, update the selectedFilters and filterInstances value then fetch the filter results
 * @since 1.11
 */
function bricksBrowserHistorySupport() {
	// Only in actual frontend
	if (!bricksIsFrontend) {
		return
	}

	/**
	 * This is causing issues when using back/forward browser navigation and bad UX
	 * No obvious issue with Scroll To interaction, so commenting it out.
	 * (#86c0rbuhb; @since 2.2)
	 */
	// Disable scroll restoration in modern browsers
	// if ('scrollRestoration' in history) {
	// 	history.scrollRestoration = 'manual'
	// }

	window.addEventListener('popstate', function (event) {
		if (event.state && event.state.isBricksFilter) {
			const targetQueryId = event.state.targetQueryId || false
			const selectedFilters = event.state.selectedFilters || []
			const instancesValue = event.state.instancesValue || []

			// Update window.bricksData.selectedFilters
			window.bricksData.selectedFilters = selectedFilters

			// Update each filterInstance value based on instancesValue
			if (Object.keys(instancesValue).length) {
				Object.keys(instancesValue).forEach((queryId) => {
					Object.keys(instancesValue[queryId]).forEach((filterId) => {
						const filterInstance = window.bricksData.filterInstances[filterId] || false
						if (!filterInstance) {
							return
						}

						// Update the filterInstance currentValue
						window.bricksData.filterInstances[filterId].currentValue =
							instancesValue[queryId][filterId]
					})
				})
			}

			if (!targetQueryId) {
				// This is initial state, each of the queryIds should be fetched
				let queryIds = bricksUtils.currentPageTargetQueryIds()

				queryIds.forEach((queryId) => {
					bricksUtils.fetchFilterResults(queryId, true)
				})
			} else {
				// This is not initial state, only the targetQueryId should be fetched
				bricksUtils.fetchFilterResults(targetQueryId, true)
			}
		}
	})
}

/**
 * Search filter element DOM will not be replaced after each request, or the cursor position will be lost.
 * We need to have a separate listener to update the search filter DOM value when necessary
 *
 * @since 1.11
 */
function bricksSearchValueUpdater() {
	// Listen to bricks/ajax/query_result/displayed, check if currentValue changed (popstate), update the value
	document.addEventListener('bricks/ajax/query_result/displayed', function (event) {
		const targetQueryId = event.detail.queryId || false

		if (!targetQueryId) {
			return
		}

		const allFilters = bricksUtils.getFiltersForQuery(targetQueryId)

		// Find all search type filters
		const searchFilters = allFilters.filter((filter) => {
			return filter.filterType === 'search'
		})

		if (searchFilters.length > 0) {
			// Loop through each search filter and check if the currentValue is different from the input value
			searchFilters.forEach((filter) => {
				const filterElement = filter.filterElement
				const currentValue = filter.currentValue

				// If the currentValue is different from the input value, update the input value
				if (filterElement.value !== currentValue) {
					filterElement.value = currentValue

					bricksUtils.updateSearchFilterIconVisibility(filter)
					bricksUtils.updateLiveSearchTerm(targetQueryId, currentValue)
				}

				// Always update live search term as the search filter might be triggered via submit button (#86c55zec4; @since 2.2)
				bricksUtils.updateLiveSearchTerm(targetQueryId, currentValue)
			})

			// History navigation can restore an empty search after the wrapper listener has run.
			bricksUtils.hideOrShowLiveSearchWrapper(targetQueryId)
		}
	})
}

/**
 * Restore focus on filter element after AJAX query
 *
 * @since 1.10
 */
function bricksRestoreFocusOnFilter() {
	let lastFocused = {
		elementId: false,
		input: false,
		rangeFocus: false
	}

	const getFilterInstanceFromActiveElement = (activeElement) => {
		// Standard case: element is inside a filter node
		const filterElement = activeElement.closest('[data-brx-filter]')

		if (filterElement) {
			return (
				Object.values(window.bricksData.filterInstances).find((filter) => {
					return filter.filterElement === filterElement
				}) || false
			)
		}

		// Choices.js case: focused element may live outside the original select[data-brx-filter]
		return (
			Object.values(window.bricksData.filterInstances).find((filter) => {
				if (!filter?.isChoiceJS || !filter?.choicesInstance?.choices?.containerOuter?.element) {
					return false
				}

				const choicesContainer = filter.choicesInstance.choices.containerOuter.element

				return choicesContainer.contains(activeElement)
			}) || false
		)
	}

	document.addEventListener('focusin', (event) => {
		const activeElement = event.target

		const filterInstance = getFilterInstanceFromActiveElement(activeElement)

		if (!filterInstance) {
			return
		}

		lastFocused.elementId = filterInstance.filterId
		lastFocused.rangeFocus = false
		lastFocused.choicesFocus = false

		if (filterInstance.filterType === 'range') {
			// Persist focused custom stepper button across AJAX re-render
			if (activeElement.classList && activeElement.classList.contains('brx-stepper-button')) {
				lastFocused.rangeFocus = {
					type: 'stepper-button',
					direction: activeElement.dataset.stepperDirection || '',
					inputId: activeElement.dataset.inputId || ''
				}
				lastFocused.input = false
				return
			}

			// Range filter has multiple inputs, save the activeElement
			lastFocused.input = activeElement
			return
		}

		// Choices.js specific focus snapshot
		if (filterInstance.filterType === 'select' && filterInstance.isChoiceJS) {
			const choices = filterInstance?.choicesInstance?.choices || false
			const choicesInput = choices?.input?.element || false
			const choicesContainer = choices?.containerOuter?.element || false

			lastFocused.choicesFocus = {
				type: 'choices',
				isSearchInput:
					!!choicesInput &&
					(activeElement === choicesInput || choicesInput.contains(activeElement)),
				wasOpen: !!(
					choicesContainer &&
					choicesContainer.classList &&
					choicesContainer.classList.contains('is-open')
				)
			}

			lastFocused.input = false
			return
		}

		// Keep original behavior for non-range / non-choices filters
		lastFocused.input = activeElement.parentElement?.querySelector('input') || false
	})

	document.addEventListener('bricks/ajax/query_result/displayed', function (event) {
		const displayedQueryId = event.detail?.queryId || false

		if (!lastFocused.elementId) {
			return
		}

		// Wait one frame to ensure Choices instances are re-created after DOM swap
		window.requestAnimationFrame(() => {
			// Search the newly fetched filterElement and focus on the input/button
			const filterInstance = Object.values(window.bricksData.filterInstances).find((filter) => {
				return filter.filterId === lastFocused.elementId
			})

			if (!filterInstance || !filterInstance.filterElement) {
				return
			}

			// Avoid refocusing if the filter is not related to the currently displayed query
			if (displayedQueryId && filterInstance.targetQueryId !== displayedQueryId) {
				return
			}

			const filterElement = filterInstance.filterElement

			// Restore focus to custom stepper button (keyboard flow fix)
			if (
				filterInstance.filterType === 'range' &&
				lastFocused.rangeFocus &&
				lastFocused.rangeFocus.type === 'stepper-button'
			) {
				let stepperSelector = '.brx-stepper-button'

				if (lastFocused.rangeFocus.direction) {
					stepperSelector += `[data-stepper-direction="${lastFocused.rangeFocus.direction}"]`
				}

				if (lastFocused.rangeFocus.inputId) {
					stepperSelector += `[data-input-id="${lastFocused.rangeFocus.inputId}"]`
				}

				const stepperButton = filterElement.querySelector(stepperSelector)

				if (stepperButton) {
					stepperButton.focus()
					return
				}
			}

			// Choices.js restore path
			if (
				filterInstance.filterType === 'select' &&
				filterInstance.isChoiceJS &&
				lastFocused.choicesFocus &&
				lastFocused.choicesFocus.type === 'choices'
			) {
				const choices = filterInstance?.choicesInstance?.choices || false
				const choicesInput = choices?.input?.element || false
				const choicesContainer = choices?.containerOuter?.element || false

				let focusTarget = false

				if (lastFocused.choicesFocus.isSearchInput && choicesInput) {
					focusTarget = choicesInput
				} else if (choicesContainer) {
					focusTarget = choicesContainer
				} else if (choicesInput) {
					focusTarget = choicesInput
				}

				if (focusTarget) {
					focusTarget.focus()

					// Preserve open state for searchable choices when possible
					if (
						lastFocused.choicesFocus.wasOpen &&
						choicesContainer &&
						!choicesContainer.classList.contains('is-open') &&
						typeof choices.showDropdown === 'function'
					) {
						choices.showDropdown(true)
					}

					return
				}
			}

			if (!lastFocused.input) {
				return
			}

			const inputs = filterElement.querySelectorAll('input')

			// Find the matching input based on attributes
			let matchingInput = Array.from(inputs).find((input) => {
				return input.name === lastFocused.input.name && input.value === lastFocused.input.value
			})

			// Fallback: match by name only
			if (!matchingInput) {
				matchingInput = Array.from(inputs).find((input) => {
					return input.name === lastFocused.input.name
				})
			}

			if (matchingInput) {
				matchingInput.focus()

				// Range filter needs to tweak zIndex or it might be overlapped (@since 1.11)
				if (
					filterInstance.filterType === 'range' &&
					matchingInput.tagName === 'INPUT' &&
					matchingInput.type === 'range'
				) {
					if (
						!Array.isArray(filterInstance.currentValue) ||
						filterInstance.currentValue.length < 2
					) {
						return
					}

					if (filterInstance.currentValue[0] !== filterInstance.currentValue[1]) {
						return
					}

					matchingInput.style.zIndex = 3

					let siblingClass = matchingInput.classList.contains('min') ? 'max' : 'min'
					let siblingInput = filterElement.querySelector(`input.${siblingClass}[type='range']`)

					if (siblingInput) {
						siblingInput.style.zIndex = 2
					}
				}
			}
		})
	})
}

/**
 * Filter options interactions
 *
 * Emit events when the filter options are empty or not empty.
 *
 * @since 1.11
 */
function bricksFilterOptionsInteractions() {
	const checkFilterOptionsCount = (targetQueryId) => {
		// Get all filter instances where the filterType is select, radio, pagination, or checkbox where targetQueryId is the same, if targetQueryId is 'initial', get all filter instances
		const filterInstances = Object.values(window.bricksData.filterInstances).filter((filter) => {
			return (
				(targetQueryId === 'initial' || filter.targetQueryId === targetQueryId) &&
				(filter.filterType === 'active-filters' ||
					filter.filterType === 'checkbox' ||
					filter.filterType === 'datepicker' ||
					filter.filterType === 'search' ||
					filter.filterType === 'select' ||
					filter.filterType === 'radio' ||
					filter.filterType === 'range')
			)
		})

		// If no filterInstances found, skip
		if (!filterInstances.length) {
			return
		}

		const eventNames = {
			'bricks/filter/option/notempty': [],
			'bricks/filter/option/empty': []
		}

		// Loop through each filter instance and categorize them based on the total options
		filterInstances.forEach((filter) => {
			// Skip if no filterElement, filterId, or filterType
			if (!filter.filterElement || !filter.filterId || !filter.filterType) {
				return
			}

			let totalOptions

			switch (filter.filterType) {
				case 'active-filters':
					totalOptions = filter.filterElement.innerHTML
					break

				case 'datepicker':
					totalOptions = filter.currentValue
					break

				case 'range':
					if (filter.min != filter.currentValue[0] || filter.max != filter.currentValue[1]) {
						totalOptions = 1
					}
					break

				case 'search':
					totalOptions = filter.filterElement.value
					break

				case 'select':
					totalOptions = filter.filterElement.querySelectorAll(
						':scope > option:not(.placeholder)'
					)?.length // Exclude the "placeholder" option (@since 2.0)
					break

				// Radio and checkbox filter types
				default:
					totalOptions = filter.filterElement.querySelectorAll(
						':scope > .brx-filter-options > li:not(.brx-option-all)'
					)?.length // Exclude the "All" option (@since 2.0)
					break
			}

			if (totalOptions) {
				eventNames['bricks/filter/option/notempty'].push(filter.filterId)
			} else {
				eventNames['bricks/filter/option/empty'].push(filter.filterId)
			}
		})

		// Emit event
		Object.keys(eventNames).forEach((eventName) => {
			const event = new CustomEvent(eventName, {
				detail: {
					filterElementIds: eventNames[eventName]
				}
			})
			document.dispatchEvent(event)
		})
	}

	// Listen to bricks/ajax/query_result/displayed event
	document.addEventListener('bricks/ajax/query_result/displayed', function (event) {
		const targetQueryId = event.detail.queryId || false
		if (!targetQueryId) {
			return
		}

		checkFilterOptionsCount(targetQueryId)
	})

	// Run the function on page load
	checkFilterOptionsCount('initial')
}

document.addEventListener('DOMContentLoaded', function (event) {
	if (!window.bricksData?.filterInstances || !window.bricksData?.queryLoopInstances) {
		return
	}

	bricksFilters()
	bricksLiveSearchWrappersInit()

	bricksSearchFilter()
	bricksSearchValueUpdater() // @since 1.11
	bricksSelectFilter()
	bricksResetFilter()
	bricksApplyFilter()
	bricksPaginationFilter()
	bricksRadioFilter()
	bricksRangeFilter()
	bricksRangeInputStepper() // @since 2.3
	bricksRangeValueUpdater() // @since 1.12
	bricksRangeSliderUI()
	bricksCheckboxFilter()
	bricksDatePickerFilter()
	bricksActiveFilter() // @since 1.11
	bricksActiveFiltersCountDD() // @since 2.0
	bricksFilterShowMoreLess() // @since 2.3

	bricksDisableFiltersOnLoad()
	bricksInitBrowserState() // @since 1.11
	bricksBrowserHistorySupport() //@since 1.11
	bricksFiltersA11yHandler()
	bricksRestoreFocusOnFilter()
	bricksFilterOptionsInteractions() //@since 1.11
})
