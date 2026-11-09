/**
 * Validate and convert property value based on expected type
 */
function validatePropertyValue(value, propertyType) {
	// Handle arrays - only certain types should accept arrays
	if (Array.isArray(value)) {
		const arrayTypes = ['class', 'image-gallery', 'select']

		if (arrayTypes.includes(propertyType)) {
			return value // Keep as array
		} else {
			// Convert array to string for text-like properties
			if (['text', 'editor', 'textarea'].includes(propertyType)) {
				return value.length > 0 ? value.join(', ') : ''
			}
			// For other types, take first value or empty string
			return value.length > 0 ? value[0] : ''
		}
	}

	// Handle objects - only certain types should accept objects
	if (typeof value === 'object' && value !== null) {
		const objectTypes = ['image', 'icon', 'link', 'query', 'image-gallery']

		if (objectTypes.includes(propertyType)) {
			return value // Keep as object
		} else {
			// Convert object to string for text-like properties
			if (['text', 'editor', 'textarea'].includes(propertyType)) {
				// Try to extract a meaningful string from the object
				if (value.url) return value.url
				if (value.name) return value.name
				if (value.label) return value.label
				return JSON.stringify(value)
			}
			// For other types, return empty string
			return ''
		}
	}

	// For primitive values, return as-is
	return value
}

/**
 * Create unified property update function
 */
function createPropertyUpdater(props, component) {
	return function updateProperty(propertyId, value) {
		const currentProperties = props.attributes.properties || {}
		const newProperties = { ...currentProperties }

		// Find property definition to get its type
		let propertyType = 'text' // Default fallback
		if (component && component.properties) {
			const propertyDef = component.properties.find((prop) => prop.id === propertyId)
			if (propertyDef) {
				propertyType = propertyDef.type
			}
		}

		// Remove property if value is empty/null/undefined
		if (value === null || value === undefined || value === '') {
			delete newProperties[propertyId]
		}
		// Remove empty arrays
		else if (Array.isArray(value) && value.length === 0) {
			delete newProperties[propertyId]
		}
		// Remove empty objects
		else if (
			typeof value === 'object' &&
			!Array.isArray(value) &&
			Object.keys(value).length === 0
		) {
			delete newProperties[propertyId]
		}
		// Validate and set the value
		else {
			const validatedValue = validatePropertyValue(value, propertyType)
			newProperties[propertyId] = validatedValue
		}

		props.setAttributes({ properties: newProperties })
	}
}

/**
 * Create appropriate Gutenberg control based on property type
 */
function bricksCreatePropertyControl(property, props, component) {
	try {
		// Create unified property updater with component context for type validation
		const updateProperty = createPropertyUpdater(props, component)

		// Create wrapper props that work with the new system
		const controlProps = {
			// Stable TinyMCE editor IDs prevent Gutenberg inspector re-renders from remounting the rich text editor. (#86c9qmpym; @since 2.3.9)
			clientId: props.clientId || props.attributes.blockId || '',
			attributes: {
				[property.id]: (props.attributes.properties || {})[property.id]
			},
			setAttributes: function (newAttrs) {
				const value = newAttrs[property.id]
				updateProperty(property.id, value)
			}
		}

		switch (property.type) {
			case 'text':
				return window.createBricksTextControl(property, controlProps)

			case 'editor':
				return window.createBricksRichTextControl(property, controlProps)

			case 'select':
				return window.createBricksSelectControl(property, controlProps)

			case 'toggle':
				return window.createBricksToggleControl(property, controlProps)

			case 'icon':
				return window.createBricksIconControl(property, controlProps)

			case 'image':
				return window.createBricksImageControl(property, controlProps)

			case 'image-gallery':
				return window.createBricksGalleryControl(property, controlProps)

			case 'link':
				return window.createBricksLinkControl(property, controlProps)

			case 'query':
				return window.createBricksQueryControl(property, controlProps)

			case 'class':
				return window.createBricksClassControl(property, controlProps)

			default:
				// Fallback to text control
				return window.createBricksTextControl(property, controlProps)
		}
	} catch (error) {
		// Return a simple text control as fallback
		const fallbackProps = {
			attributes: {
				[property.id]: (props.attributes.properties || {})[property.id]
			},
			setAttributes: function (newAttrs) {
				const updateProperty = createPropertyUpdater(props, component)
				updateProperty(property.id, newAttrs[property.id])
			}
		}
		return window.createBricksTextControl(property, fallbackProps)
	}
}

/**
 * Check if a property is connected to a nested component instance
 */
function isConnectedToNestedInstance(component, propertyId) {
	if (!component || !component.elements) {
		return false
	}

	return component.elements.some((element) => {
		if (element.cid && element.properties) {
			// Check if any of the instance's properties connect to this property
			return Object.values(element.properties).some((connectionValue) => {
				if (typeof connectionValue === 'string' && connectionValue.startsWith('parent:')) {
					// Parse: parent:cid_{componentId}:prop_{propertyId}
					const match = connectionValue.match(/^parent:cid_([^:]+):prop_([^:]+)$/)
					if (match) {
						const connectedPropertyId = match[2]
						return connectedPropertyId === propertyId
					}
				}
				return false
			})
		}
		return false
	})
}

/**
 * Release detached preview instances without touching another block's replacement instance.
 */
function bricksCleanUpComponentPreviewInstances(node) {
	const previewWindow = node.ownerDocument?.defaultView
	const data = previewWindow?.bricksData
	const owned = node.bricksComponentPreviewInstances

	for (const [registry, getRoot, getInstance] of [
		[data?.splideInstances, (entry) => entry.root, (entry) => entry],
		[data?.swiperInstances, (entry) => entry.el, (entry) => entry],
		[data?.isotopeInstances, (entry) => entry.element, (entry) => entry.instance]
	]) {
		for (const [id, entry] of Object.entries(registry || {})) {
			const root = getRoot(entry)

			if (root && !root.isConnected && (node.contains(root) || owned?.has(entry))) {
				getInstance(entry).destroy()

				if (registry[id] === entry) {
					delete registry[id]
				}
			}
		}
	}

	// Release detached DOM roots retained by the shared initialization tracking sets.
	for (const initializer of previewWindow?.bricksFunctionInstances || []) {
		initializer.cleanUpInitElements?.()
	}

	if (owned) {
		for (const entry of owned) {
			if (!(entry.root || entry.el || entry.element)?.isConnected) {
				owned.delete(entry)
			}
		}
	}
}

/**
 * Run Bricks frontend initializers after Gutenberg mounts a server-rendered component preview.
 */
function bricksInitComponentPreviewScripts(node) {
	if (!node) {
		return
	}

	const previewWindow = node.ownerDocument?.defaultView

	if (!previewWindow) {
		return
	}

	const run = () => {
		if (!node.isConnected || !previewWindow.bricksData) {
			return
		}

		bricksCleanUpComponentPreviewInstances(node)

		// The libraries are editor dependencies; only initialize roots in this preview.
		// Preserve mounted instances when library-generated DOM mutations fire again.
		const settings = { parentNode: node, forceReinit: false }
		for (const [initializer, registry] of [
			['bricksSwiper', 'swiperInstances'],
			['bricksSplide', 'splideInstances'],
			['bricksIsotope', 'isotopeInstances']
		]) {
			previewWindow.bricksData[registry] = previewWindow.bricksData[registry] || {}
			if (typeof previewWindow[initializer] === 'function') {
				previewWindow[initializer](settings)
			}
		}

		const owned = node.bricksComponentPreviewInstances || new Set()

		for (const entry of [
			...Object.values(previewWindow.bricksData.swiperInstances),
			...Object.values(previewWindow.bricksData.splideInstances),
			...Object.values(previewWindow.bricksData.isotopeInstances)
		]) {
			if (node.contains(entry.root || entry.el || entry.element)) {
				// Update mounted Splide instances too: iframe classes can arrive after initialization.
				if (entry.root && entry.options?.keyboard === 'global') {
					entry.options = { keyboard: 'focused' }
				}

				owned.add(entry)
			}
		}

		node.bricksComponentPreviewInstances = owned
	}

	clearTimeout(node.bricksComponentPreviewInitTimeout)
	node.bricksComponentPreviewInitTimeout = setTimeout(run, 50)

	if (node.bricksComponentPreviewObserver) {
		return
	}

	const observer = new MutationObserver(() => {
		clearTimeout(node.bricksComponentPreviewInitTimeout)
		node.bricksComponentPreviewInitTimeout = setTimeout(run, 50)
	})

	node.bricksComponentPreviewObserver = observer
	observer.observe(node, {
		childList: true,
		subtree: true
	})
}

/**
 * Stop preview observers when Gutenberg replaces or unmounts a block wrapper.
 */
function bricksDestroyComponentPreviewScripts(node) {
	if (!node) {
		return
	}

	clearTimeout(node.bricksComponentPreviewInitTimeout)
	node.bricksComponentPreviewObserver?.disconnect()

	delete node.bricksComponentPreviewInitTimeout
	delete node.bricksComponentPreviewObserver

	// React can detach and reattach the callback ref while keeping the same DOM node.
	setTimeout(() => {
		if (!node.isConnected) {
			bricksCleanUpComponentPreviewInstances(node)
			delete node.bricksComponentPreviewInstances
		}
	}, 0)
}

/**
 * Register Bricks components as Gutenberg blocks using ServerSideRender
 */
function getBricksComponentBlockName(componentId) {
	const id = String(componentId)
	const encodedId = Array.from(new TextEncoder().encode(id), (byte) =>
		byte.toString(16).padStart(2, '0')
	).join('')

	return /^[a-z][a-f0-9]{5}$/.test(id)
		? `bricks-components/${id}`
		: `bricks-component-ids/id-${encodedId}`
}

function getBricksLegacyComponentBlockName(componentId) {
	return `bricks-components/${String(componentId)}`
}

function isValidGutenbergBlockName(blockName) {
	return /^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/.test(blockName)
}

/**
 * Keep saved instance IDs stable, replacing only missing IDs and collisions within a component.
 */
function bricksGetComponentBlockId(editor, clientId, blockId, componentId) {
	if (!clientId) {
		return blockId
	}

	const blockNames = [
		getBricksComponentBlockName(componentId),
		getBricksLegacyComponentBlockName(componentId)
	]
	const instanceIds = editor.getClientIdsWithDescendants().filter((id) => {
		const name = editor.getBlockName(id)
		const attributes = editor.getBlockAttributes(id)

		return (
			(name?.startsWith('bricks-components/') || name?.startsWith('bricks-component-ids/')) &&
			(attributes?.componentId ? attributes.componentId === componentId : blockNames.includes(name))
		)
	})
	const ownerId = blockId
		? instanceIds.find((id) => editor.getBlockAttributes(id)?.blockId === blockId)
		: undefined

	// Document order gives saved duplicates a deterministic owner, independent of React effect order.
	if (blockId && (!ownerId || ownerId === clientId)) {
		return blockId
	}

	const usedIds = new Set(
		instanceIds.filter((id) => id !== clientId).map((id) => editor.getBlockAttributes(id)?.blockId)
	)
	let nextId = clientId
	let suffix = 1

	while (usedIds.has(nextId)) {
		nextId = `${clientId}-${suffix++}`
	}

	return nextId
}

function bricksRegisterComponentBlocks() {
	if (!window?.wp?.blocks || !window.bricksGutenbergData?.components) {
		return
	}

	const { registerBlockType } = window.wp.blocks
	const { createElement } = window.wp.element
	const { Placeholder } = window.wp.components
	const ServerSideRender = window.wp.serverSideRender || window.wp.components.ServerSideRender
	const { useBlockProps } = window.wp.blockEditor || window.wp.editor || {}

	// Register block category
	window.wp.blocks.setCategories([
		...window.wp.blocks.getCategories(),
		{
			slug: 'bricks',
			title: `Bricks: ${window.bricksData.i18n.components}`
		}
	])

	// Get enabled component IDs from PHP
	const enabledComponentIds = window.bricksGutenbergData.enabledComponentIds || []
	// Keep canonical editor types authoritative when an imported legacy ID occupies the same slug.
	const canonicalBlockNames = new Set(
		window.bricksGutenbergData.components.map((component) =>
			getBricksComponentBlockName(component.id)
		)
	)

	// Register each component as a block, if enabled
	window.bricksGutenbergData.components.forEach(function (component) {
		if (!component.id || !component.elements || !component.elements.length) {
			return
		}

		// Only register enabled components
		if (!enabledComponentIds.includes(component.id)) {
			return
		}

		// Get component name from first element or use ID
		const componentName =
			component.elements[0].label || window.bricksData.i18n.component + ' ' + component.id
		const blockName = getBricksComponentBlockName(component.id)

		const attributes = {
			componentId: {
				type: 'string'
			},
			properties: {
				type: 'object',
				default: {}
			},
			blockId: {
				type: 'string',
				default: ''
			},
			variant: {
				type: 'string',
				default: ''
			},
			_preview: {
				type: 'boolean',
				default: false
			}
		}

		// Prepare icon
		let icon = null

		if (component.blockIcon) {
			try {
				// SVG / Custom Icon
				if (
					(component.blockIcon.library === 'svg' ||
						component.blockIcon.library?.startsWith('custom_')) &&
					component.blockIcon.svg?.url
				) {
					icon = createElement('img', {
						src: component.blockIcon.svg.url,
						style: { width: '24px', height: '24px' }
					})
				}
				// Font Icon
				else if (component.blockIcon.icon) {
					// We need to ensure font families are loaded, but for now just use the class
					icon = createElement('i', {
						className: component.blockIcon.icon,
						style: { fontSize: '20px', lineHeight: '1' }
					})
				}
			} catch (e) {
				console.warn('Error parsing block icon:', e)
			}
		}

		// Default icon
		if (!icon) {
			icon = createElement(
				'svg',
				{
					viewBox: '0 0 24 24',
					xmlns: 'http://www.w3.org/2000/svg',
					style: { fill: 'currentColor' }
				},
				createElement('path', {
					d: 'M7.94514768,0 L8.35021097,0.253164557 L8.35021097,7.29113924 C9.77919139,6.34598684 11.3600476,5.87341772 13.092827,5.87341772 C15.5907298,5.87341772 17.6610326,6.74542025 19.3037975,8.48945148 C20.9240587,10.2334827 21.7341772,12.382547 21.7341772,14.9367089 C21.7341772,17.5021225 20.9184329,19.6511868 19.2869198,21.3839662 C17.6441549,23.1279975 15.579478,24 13.092827,24 C10.9212268,24 9.06470532,23.2236365 7.52320675,21.6708861 L7.52320675,23.5780591 L3,23.5780591 L3,0.556962025 L7.94514768,0 Z M12.2320675,10.4472574 C11.0393752,10.4472574 10.0436046,10.8523166 9.24472574,11.6624473 C8.44584692,12.4950815 8.0464135,13.5864911 8.0464135,14.9367089 C8.0464135,16.2869266 8.44584692,17.3727104 9.24472574,18.1940928 C10.0323527,19.0154753 11.0281234,19.4261603 12.2320675,19.4261603 C13.5035225,19.4261603 14.5330481,18.9985978 15.3206751,18.1434599 C16.0970503,17.2995738 16.4852321,16.2306675 16.4852321,14.9367089 C16.4852321,13.6427502 16.0914245,12.5682181 15.3037975,11.7130802 C14.5161705,10.8691941 13.4922707,10.4472574 12.2320675,10.4472574 Z'
				})
			)
		}

		try {
			const blockSettings = {
				apiVersion: 3,
				title: componentName,
				category: component.blockCategory || 'bricks',
				description: component.desc,
				icon: icon,
				keywords: ['bricks', window.bricksData.i18n.component],
				attributes: attributes,
				supports: {
					align: ['wide', 'full'],
					customClassName: false,
					html: false,
					anchor: false,
					className: false
				},
				example: component.blockPreviewImage
					? {
							attributes: {
								_preview: true
							}
						}
					: undefined,
				edit: function (props) {
					try {
						const { InspectorControls, useBlockProps } = window.wp.blockEditor || window.wp.editor
						const { PanelBody } = window.wp.components
						const { useEffect, useState, useRef, useMemo } = window.wp.element
						const { useSelect, useDispatch } = window.wp.data

						// Get block props
						const blockProps = useBlockProps ? useBlockProps() : {}

						// Check for preview mode
						if (props.attributes._preview && component.blockPreviewImage?.url) {
							return createElement('img', {
								...blockProps,
								src: component.blockPreviewImage.url,
								style: { ...blockProps.style, width: '100%', height: 'auto', display: 'block' },
								alt: componentName
							})
						}

						const componentId = props.attributes.componentId || component.id
						const blockId = useSelect(
							(select) =>
								bricksGetComponentBlockId(
									select('core/block-editor'),
									props.clientId,
									props.attributes.blockId,
									componentId
								),
							[props.clientId, props.attributes.blockId, componentId]
						)
						const { __unstableMarkNextChangeAsNotPersistent } = useDispatch('core/block-editor')

						// Persist identity and block ID so encoded names never need ambiguous fallback decoding.
						useEffect(() => {
							const initialAttributes = {}

							if (!props.attributes.componentId) {
								initialAttributes.componentId = component.id
							}

							if (blockId && props.attributes.blockId !== blockId) {
								initialAttributes.blockId = blockId
							}

							if (Object.keys(initialAttributes).length) {
								// Identity maintenance belongs to the insertion/duplication, not a separate undo step.
								__unstableMarkNextChangeAsNotPersistent()
								props.setAttributes(initialAttributes)
							}
						}, [blockId, props.attributes.blockId, props.attributes.componentId])

						// Track last successful render for smooth loading transitions
						const [lastRender, setLastRender] = useState('')
						const renderRef = useRef(null)
						const [debouncedAttributes, setDebouncedAttributes] = useState(props.attributes)
						// Prevent navigation when clicking links in the editor preview (#86caene71; @since 2.3.9)
						const preventEditorLinkNavigation = (event) => {
							const target =
								event.target?.nodeType === 1 ? event.target : event.target?.parentElement
							const link = target?.closest?.('a[href]')

							if (!link || !renderRef.current?.contains(link)) {
								return
							}

							event.preventDefault()
						}

						// Debounce attribute changes to prevent multiple ServerSideRender calls per keystroke
						useEffect(() => {
							const timeoutId = setTimeout(() => {
								setDebouncedAttributes(props.attributes)
							}, 300) // 300ms delay

							return () => clearTimeout(timeoutId)
						}, [JSON.stringify(props.attributes)])

						// Pass componentId, blockId, variant and properties
						const memoizedSafeAttributes = useMemo(() => {
							const safeAttributes = {
								componentId,
								blockId,
								variant: debouncedAttributes.variant || ''
							}

							// Only include properties if they exist and have content
							const properties = debouncedAttributes.properties || {}
							if (Object.keys(properties).length > 0) {
								// Clean up empty values
								const cleanProperties = {}
								Object.keys(properties).forEach((key) => {
									const value = properties[key]
									if (value !== null && value !== undefined && value !== '') {
										// For arrays, only include if not empty
										if (Array.isArray(value)) {
											if (value.length > 0) {
												cleanProperties[key] = value
											}
										}
										// For objects, only include if not empty
										else if (typeof value === 'object') {
											if (Object.keys(value).length > 0) {
												cleanProperties[key] = value
											}
										}
										// For primitives, include as-is
										else {
											cleanProperties[key] = value
										}
									}
								})

								if (Object.keys(cleanProperties).length > 0) {
									safeAttributes.properties = cleanProperties
								}
							}

							return safeAttributes
						}, [JSON.stringify(debouncedAttributes), componentId, blockId])

						// Create stable cached content to prevent image flashing
						const cachedContent = useMemo(() => {
							if (!lastRender) return null

							return createElement('div', {
								key: `cached-content-${lastRender.slice(0, 20).replace(/[^a-zA-Z0-9]/g, '')}`, // Stable key based on content
								'data-cached': 'true',
								style: { opacity: 0.98 },
								dangerouslySetInnerHTML: { __html: lastRender }
							})
						}, [lastRender])

						// Capture content only when attributes actually change - single delayed capture
						useEffect(() => {
							const timeoutId = setTimeout(() => {
								if (renderRef.current) {
									const serverRenderDiv =
										renderRef.current.querySelector('[class*="wp-block"]:not([data-cached])') ||
										renderRef.current.querySelector('div > div:not([data-cached])')

									if (serverRenderDiv) {
										const content = serverRenderDiv.outerHTML

										// More strict content validation to avoid unnecessary updates
										if (
											content &&
											content.trim() &&
											content.length > 50 &&
											!content.includes(window.bricksData.i18n.loadingComponentPreview) &&
											!content.includes(window.bricksData.i18n.componentPreviewError) &&
											!content.includes('data-cached') &&
											content !== lastRender
										) {
											// Only update if actually different
											setLastRender(content)
										}
									}
								}
							}, 1500) // Wait 1.5 seconds for ServerSideRender to complete

							return () => clearTimeout(timeoutId)
						}, [memoizedSafeAttributes]) // ONLY run when attributes change - removed lastRender from deps!

						// Separate initial content capture on mount
						useEffect(() => {
							const timeoutId = setTimeout(() => {
								if (renderRef.current && !lastRender) {
									const serverRenderDiv =
										renderRef.current.querySelector('[class*="wp-block"]:not([data-cached])') ||
										renderRef.current.querySelector('div > div:not([data-cached])')

									if (serverRenderDiv) {
										const content = serverRenderDiv.outerHTML
										if (
											content &&
											content.trim() &&
											content.length > 50 &&
											!content.includes(window.bricksData.i18n.loadingComponentPreview) &&
											!content.includes(window.bricksData.i18n.componentPreviewError) &&
											!content.includes('data-cached')
										) {
											setLastRender(content)
										}
									}
								}
							}, 2000) // Initial load takes longer

							return () => clearTimeout(timeoutId)
						}, []) // Only run once on mount

						if (!ServerSideRender) {
							return createElement(
								'div',
								{
									...blockProps,
									style: {
										...blockProps.style,
										padding: '20px',
										border: '2px dashed #ccc',
										textAlign: 'center'
									}
								},
								window.bricksData.i18n.serverSideRenderNotAvailable
							)
						}

						// Build variant selector
						let variantControl = null
						if (
							component.variants &&
							Array.isArray(component.variants) &&
							component.variants.length > 0
						) {
							// Prepare variant options for select control
							const variantOptions = component.variants.map(function (variant) {
								return {
									label: variant.name || variant.id,
									value: variant.id
								}
							})

							// Add default/none option at the beginning (@since 2.2)
							variantOptions.unshift({
								label: component.labelVariantBase || window.bricksData.i18n.base || 'Base',
								value: ''
							})

							// Create variant select control
							const variantProperty = {
								id: 'variant',
								label: window.bricksData.i18n.variant || 'Variant',
								type: 'select',
								options: variantOptions
							}

							variantControl = window.createBricksSelectControl(variantProperty, {
								attributes: {
									variant: props.attributes.variant || ''
								},
								setAttributes: function (newAttrs) {
									props.setAttributes({ variant: newAttrs.variant || '' })
								}
							})
						}

						// Build property controls (only for properties with connections)
						let propertyControls = []
						if (component.properties && Array.isArray(component.properties)) {
							component.properties.forEach(function (property) {
								if (property.id && property.label) {
									// Check connections to element controls
									const hasDirectConnections =
										property.connections &&
										typeof property.connections === 'object' &&
										Object.keys(property.connections).length > 0

									// Check connections to nested component instances
									const hasNestedConnections = isConnectedToNestedInstance(component, property.id)

									// Skip properties that don't have connections to any elements
									if (!hasDirectConnections && !hasNestedConnections) {
										return
									}

									const control = bricksCreatePropertyControl(property, props, component)
									if (control) {
										propertyControls.push(control)
									}
								}
							})
						}

						// Create the editor UI
						const editorElements = []

						// Add variant control in inspector (sidebar) if available
						if (variantControl && InspectorControls) {
							editorElements.push(
								createElement(
									InspectorControls,
									{ key: 'inspector-variant' },
									createElement(
										PanelBody,
										{
											title: window.bricksData.i18n.variant,
											initialOpen: true
										},
										variantControl
									)
								)
							)
						}

						// Add property controls in inspector (sidebar)
						if (propertyControls.length > 0 && InspectorControls) {
							editorElements.push(
								createElement(
									InspectorControls,
									{ key: 'inspector-properties' },
									createElement(
										PanelBody,
										{
											title: window.bricksData.i18n.properties,
											initialOpen: true
										},
										propertyControls
									)
								)
							)
						}

						// Memoize the ServerSideRender component to prevent unnecessary re-creation
						const serverSideRenderComponent = useMemo(() => {
							return createElement(ServerSideRender, {
								key: 'render',
								block: blockName,
								attributes: memoizedSafeAttributes,
								httpMethod: 'POST', // Use POST for better security - no attributes in URL
								// Add error handling
								LoadingResponsePlaceholder: function () {
									// Use memoized cached content to prevent image flashing
									if (cachedContent) {
										return cachedContent
									}

									// First load - show minimal loading
									return createElement(
										'div',
										{
											style: { minHeight: '20px', opacity: 0.5 }
										},
										window.bricksData.i18n.loadingComponentPreview
									)
								},
								ErrorResponsePlaceholder: function () {
									return createElement(
										'div',
										{
											style: {
												padding: '20px',
												border: '2px dashed #e65100',
												textAlign: 'center',
												color: '#e65100',
												backgroundColor: '#fff3e0'
											}
										},
										[
											createElement(
												'p',
												{ key: 'error-title', style: { fontWeight: 'bold', margin: '0 0 8px 0' } },
												window.bricksData.i18n.componentPreviewError
											),
											createElement(
												'p',
												{ key: 'message', style: { margin: '0', fontSize: '12px' } },
												window.bricksData.i18n.componentConfiguredButPreview
											)
										]
									)
								}
							})
						}, [memoizedSafeAttributes, !!cachedContent]) // Only recreate when attributes or cached content availability changes

						// Add the memoized ServerSideRender component with error boundary
						try {
							editorElements.push(
								createElement(
									'div',
									{
										...blockProps,
										key: 'render-wrapper',
										onClickCapture: preventEditorLinkNavigation,
										onAuxClickCapture: preventEditorLinkNavigation,
										ref: (node) => {
											if (renderRef.current && renderRef.current !== node) {
												bricksDestroyComponentPreviewScripts(renderRef.current)
											}

											// Handle local ref for content caching
											renderRef.current = node
											// Handle Gutenberg's blockProps ref
											const { ref: blockPropsRef } = blockProps
											if (blockPropsRef) {
												if (typeof blockPropsRef === 'function') {
													blockPropsRef(node)
												} else {
													blockPropsRef.current = node
												}
											}

											bricksInitComponentPreviewScripts(node)
										}
									},
									serverSideRenderComponent
								)
							)
						} catch (renderError) {
							console.error('ServerSideRender Error:', renderError)
							console.error('Block Name:', blockName)
							console.error('Safe Attributes:', memoizedSafeAttributes)
							editorElements.push(
								createElement(
									'div',
									{
										...blockProps,
										key: 'render-error',
										style: {
											...blockProps.style,
											padding: '20px',
											border: '2px solid #d32f2f',
											textAlign: 'center',
											color: '#d32f2f',
											backgroundColor: '#ffebee'
										}
									},
									[
										createElement(
											'div',
											{ key: 'render-error-title' },
											window.bricksData.i18n.componentPreviewUnavailable
										),
										createElement(
											'div',
											{ key: 'details', style: { fontSize: '12px', marginTop: '8px' } },
											renderError.message
										)
									]
								)
							)
						}

						return editorElements
					} catch (error) {
						console.error('Bricks Gutenberg Block Error:', error)
						console.error('Component ID:', component.id)
						console.error('Props:', props)
						return createElement(
							'div',
							{
								...blockProps,
								style: {
									...blockProps.style,
									padding: '20px',
									border: '2px dashed #ccc',
									textAlign: 'center',
									color: '#666'
								}
							},
							[
								createElement(
									'div',
									{ key: 'block-error-title' },
									window.bricksData.i18n.errorRenderingComponent
								),
								createElement(
									'div',
									{ key: 'details', style: { fontSize: '12px', marginTop: '8px' } },
									error.message
								)
							]
						)
					}
				},
				save: function () {
					// Return null for server-side rendering
					return null
				}
			}

			registerBlockType(blockName, blockSettings)

			const legacyBlockName = getBricksLegacyComponentBlockName(component.id)

			if (
				legacyBlockName !== blockName &&
				!canonicalBlockNames.has(legacyBlockName) &&
				isValidGutenbergBlockName(legacyBlockName)
			) {
				registerBlockType(legacyBlockName, {
					...blockSettings,
					supports: {
						...blockSettings.supports,
						inserter: false
					}
				})
			}
		} catch (error) {
			console.error('Error registering block:', blockName, error)
		}
	})

	// Register placeholder blocks for disabled components immediately
	// This is more reliable than overriding getBlockType
	window.bricksGutenbergData.components.forEach(function (component) {
		if (!component.id || !component.elements || !component.elements.length) {
			return
		}

		// If component is NOT enabled, register it as a placeholder
		if (!enabledComponentIds.includes(component.id)) {
			const blockName = getBricksComponentBlockName(component.id)
			const componentName =
				component.elements[0].label || `${window.bricksData.i18n.component} ${component.id}`

			const blockSettings = {
				apiVersion: 3,
				title: componentName,
				category: 'bricks',
				icon: createElement(
					'svg',
					{
						viewBox: '0 0 24 24',
						xmlns: 'http://www.w3.org/2000/svg',
						style: { fill: 'currentColor', opacity: 0.5 }
					},
					createElement('path', {
						d: 'M7.94514768,0 L8.35021097,0.253164557 L8.35021097,7.29113924 C9.77919139,6.34598684 11.3600476,5.87341772 13.092827,5.87341772 C15.5907298,5.87341772 17.6610326,6.74542025 19.3037975,8.48945148 C20.9240587,10.2334827 21.7341772,12.382547 21.7341772,14.9367089 C21.7341772,17.5021225 20.9184329,19.6511868 19.2869198,21.3839662 C17.6441549,23.1279975 15.579478,24 13.092827,24 C10.9212268,24 9.06470532,23.2236365 7.52320675,21.6708861 L7.52320675,23.5780591 L3,23.5780591 L3,0.556962025 L7.94514768,0 Z M12.2320675,10.4472574 C11.0393752,10.4472574 10.0436046,10.8523166 9.24472574,11.6624473 C8.44584692,12.4950815 8.0464135,13.5864911 8.0464135,14.9367089 C8.0464135,16.2869266 8.44584692,17.3727104 9.24472574,18.1940928 C10.0323527,19.0154753 11.0281234,19.4261603 12.2320675,19.4261603 C13.5035225,19.4261603 14.5330481,18.9985978 15.3206751,18.1434599 C16.0970503,17.2995738 16.4852321,16.2306675 16.4852321,14.9367089 C16.4852321,13.6427502 16.0914245,12.5682181 15.3037975,11.7130802 C14.5161705,10.8691941 13.4922707,10.4472574 12.2320675,10.4472574 Z'
					})
				),
				keywords: ['bricks', window.bricksData.i18n.component],
				attributes: {
					componentId: {
						type: 'string',
						default: component.id
					},
					properties: {
						type: 'object',
						default: {}
					},
					variant: {
						type: 'string',
						default: ''
					}
				},
				supports: {
					customClassName: false,
					html: false,
					anchor: false,
					className: false,
					inserter: false // Don't show in inserter
				},
				edit: function () {
					const blockProps = useBlockProps ? useBlockProps() : {}
					return createElement('div', blockProps, [
						createElement(Placeholder, {
							key: 'placeholder',
							icon: 'warning',
							label: `${window.bricksData.i18n.blockNotAvailable}: ${componentName} [BRICKS]`,
							instructions: window.bricksData.i18n.componentNotEnabledInstructions,
							className: 'bricks-disabled-component-placeholder'
						})
					])
				},
				save: function () {
					return null
				}
			}

			registerBlockType(blockName, blockSettings)

			const legacyBlockName = getBricksLegacyComponentBlockName(component.id)

			if (
				legacyBlockName !== blockName &&
				!canonicalBlockNames.has(legacyBlockName) &&
				isValidGutenbergBlockName(legacyBlockName)
			) {
				registerBlockType(legacyBlockName, blockSettings)
			}
		}
	})
}

// Export the registration function to global scope
window.bricksRegisterComponentBlocks = bricksRegisterComponentBlocks

document.addEventListener('DOMContentLoaded', function () {
	bricksRegisterComponentBlocks()
})
