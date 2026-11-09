/**
 * Create proper image control matching ImageControl.vue
 */
function createBricksImageControl(property, props) {
	try {
		const { Button, SelectControl, TextControl, BaseControl } = window.wp.components
		const { MediaUpload, MediaUploadCheck } = window.wp.blockEditor
		const { createElement, useEffect, useState } = window.wp.element

		// Get current image value (should be Bricks image object or empty)
		const currentValue = props.attributes[property.id] || {}

		const hasImage = currentValue.url || currentValue.id
		const usesDynamicData = Boolean(
			currentValue.useDynamicData?.name || currentValue.useDynamicData
		)

		// Get default image sizes from Gutenberg data
		const defaultImageSizes = window.bricksGutenbergData.imageSizes || {}
		const hasUnregisteredImageSize = Boolean(
			(usesDynamicData || currentValue.id) &&
				!currentValue.external &&
				currentValue.size &&
				!Object.prototype.hasOwnProperty.call(defaultImageSizes, currentValue.size)
		)

		/**
		 * Get default image sizes options (all registered sizes)
		 */
		const getDefaultImageSizesOptions = () => {
			const options = Object.entries(defaultImageSizes).map(([value, label]) => ({
				label,
				value
			}))

			if (hasUnregisteredImageSize) {
				options.push({
					label: `${currentValue.size} (${window.bricksData.i18n.unregistered})`,
					value: currentValue.size
				})
			}

			return options
		}

		const [imageSizes, setImageSizes] = useState(getDefaultImageSizesOptions())

		/**
		 * Format attachment image sizes as select options.
		 *
		 * @param {object} sizes Attachment image sizes keyed by size name.
		 * @return {Array} Image size options.
		 */
		const getGeneratedImageSizesOptions = (sizes) => {
			return Object.entries(sizes).map(([value, sizeData]) => {
				let label = `${value
					.split(/[-_]+/)
					.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
					.join(' ')} (${sizeData.width}x${sizeData.height})`

				if (!Object.prototype.hasOwnProperty.call(defaultImageSizes, value)) {
					label += ` (${window.bricksData.i18n.unregistered})`
				}

				return { label, value }
			})
		}

		/**
		 * Merge image size options by value while preserving registered-size order.
		 *
		 * @param {...Array} optionGroups Image size option arrays.
		 * @return {Array} Merged image size options.
		 */
		const mergeImageSizeOptions = (...optionGroups) => {
			return Array.from(
				new Map(optionGroups.flat().map((option) => [option.value, option])).values()
			)
		}

		useEffect(() => {
			if (!currentValue.id || currentValue.external || usesDynamicData) {
				setImageSizes(getDefaultImageSizesOptions())
				return
			}

			let isCurrentImage = true
			setImageSizes(getDefaultImageSizesOptions())

			const fetchImageSizes = async () => {
				try {
					const formData = new FormData()
					formData.append('action', 'bricks_get_image_metadata')
					formData.append('imageId', currentValue.id)
					formData.append('imageSize', currentValue.size || 'full')

					if ((window.bricksAdminData || window.bricksData)?.nonce) {
						formData.append('nonce', (window.bricksAdminData || window.bricksData).nonce)
					}

					const response = await fetch(window.bricksGutenbergData.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})

					if (!response.ok) {
						return
					}

					const result = await response.json()

					if (!result.success || !result.data?.full) {
						return
					}

					const generatedSizes = {
						full: result.data.full,
						...result.data.sizes
					}

					if (!isCurrentImage) {
						return
					}

					setImageSizes(
						mergeImageSizeOptions(
							getDefaultImageSizesOptions(),
							getGeneratedImageSizesOptions(generatedSizes)
						)
					)
				} catch (error) {
					console.error('Error fetching image sizes:', error)
				}
			}

			fetchImageSizes()

			return () => {
				isCurrentImage = false
			}
		}, [currentValue.id, currentValue.external, usesDynamicData])

		const onSelectImage = function (media) {
			try {
				// Create Bricks-compatible image object
				const imageObject = {
					id: media.id,
					url: media.url,
					filename: media.filename || media.title || '',
					size: currentValue.size || 'full'
				}

				// Update the URL to match the selected size if available
				if (media.sizes && media.sizes[imageObject.size]) {
					imageObject.url = media.sizes[imageObject.size].url
				}

				const newAttributes = {}
				newAttributes[property.id] = imageObject
				props.setAttributes(newAttributes)
			} catch (error) {
				console.error('Image selection error:', error)
			}
		}

		const onRemoveImage = function () {
			try {
				const newAttributes = {}
				newAttributes[property.id] = {}
				props.setAttributes(newAttributes)
			} catch (error) {
				console.error('Image removal error:', error)
			}
		}

		const onChangeSize = async function (size) {
			try {
				if (usesDynamicData && !currentValue.id) {
					const newAttributes = {}
					newAttributes[property.id] = { ...currentValue, size }
					props.setAttributes(newAttributes)
					return
				}

				if (!hasImage || !currentValue.id) return

				// Fetch new URL for the selected size
				const formData = new FormData()
				formData.append('action', 'bricks_get_image_metadata')
				formData.append('imageId', currentValue.id)
				formData.append('imageSize', size)

				if ((window.bricksAdminData || window.bricksData)?.nonce) {
					formData.append('nonce', (window.bricksAdminData || window.bricksData).nonce)
				}

				const response = await fetch(window.bricksGutenbergData.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})

				let newUrl = currentValue.url

				if (response.ok) {
					const result = await response.json()
					if (result.success && result.data?.src?.[0]) {
						newUrl = result.data.src[0]
					}
				}

				// Create updated image object with new size and URL
				const imageObject = {
					...currentValue,
					size: size,
					url: newUrl
				}

				const newAttributes = {}
				newAttributes[property.id] = imageObject
				props.setAttributes(newAttributes)
			} catch (error) {
				console.error('Image size change error:', error)
			}
		}

		const onChangeExternalUrl = function (url) {
			try {
				if (url) {
					// Create external image object
					const imageObject = {
						url: url,
						external: url,
						filename: url.split('/').pop() || ''
					}

					const newAttributes = {}
					newAttributes[property.id] = imageObject
					props.setAttributes(newAttributes)
				} else {
					onRemoveImage()
				}
			} catch (error) {
				console.error('External URL change error:', error)
			}
		}

		// Build the control elements
		const controlElements = []

		// Main image selection/display
		if (hasImage) {
			// Show current image with remove button
			controlElements.push(
				createElement(
					'div',
					{
						key: 'image-preview',
						style: {
							marginBottom: '8px',
							border: '1px solid #ddd',
							borderRadius: '2px',
							padding: '8px',
							position: 'relative'
						}
					},
					[
						createElement('img', {
							key: 'img',
							src: currentValue.url,
							alt: currentValue.filename || '',
							style: {
								maxWidth: '100%',
								height: 'auto',
								display: 'block'
							}
						}),
						createElement(
							'div',
							{
								key: 'actions',
								style: {
									marginTop: '8px',
									display: 'flex',
									gap: '8px'
								}
							},
							[
								// Replace image button
								createElement(
									MediaUploadCheck,
									{
										key: 'upload-check'
									},
									createElement(MediaUpload, {
										key: 'upload',
										onSelect: onSelectImage,
										allowedTypes: ['image'],
										value: currentValue.id,
										render: function (obj) {
											return createElement(
												Button,
												{
													onClick: obj.open,
													variant: 'secondary',
													size: 'small'
												},
												window.bricksData.i18n.replaceImage
											)
										}
									})
								),
								// Remove image button
								createElement(
									Button,
									{
										key: 'remove',
										onClick: onRemoveImage,
										variant: 'secondary',
										size: 'small',
										isDestructive: true
									},
									window.bricksData.i18n.remove
								)
							]
						)
					]
				)
			)

			// Image size selector (only for WordPress media, not external)
			if ((currentValue.id || usesDynamicData) && !currentValue.external) {
				controlElements.push(
					createElement(SelectControl, {
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
						key: 'size-select',
						label: window.bricksData.i18n.imageSize,
						value: currentValue.size || 'full',
						options: usesDynamicData ? getDefaultImageSizesOptions() : imageSizes,
						help: hasUnregisteredImageSize
							? window.bricksData.i18n.imageSizeUnregisteredInfo
							: undefined,
						onChange: onChangeSize
					})
				)
			}
		} else {
			// Show upload button when no image
			controlElements.push(
				createElement(
					MediaUploadCheck,
					{
						key: 'upload-check-empty'
					},
					createElement(MediaUpload, {
						key: 'upload-empty',
						onSelect: onSelectImage,
						allowedTypes: ['image'],
						render: function (obj) {
							return createElement(
								Button,
								{
									onClick: obj.open,
									variant: 'secondary',
									style: {
										width: '100%',
										marginBottom: '8px'
									}
								},
								window.bricksData.i18n.selectImage
							)
						}
					})
				)
			)
		}

		// External URL input (only shown when no media library image is selected)
		if (!currentValue.id) {
			controlElements.push(
				createElement(TextControl, {
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
					key: 'external-url',
					label: window.bricksData.i18n.externalUrl,
					value:
						currentValue.external && currentValue.external !== true ? currentValue.external : '',
					placeholder: 'https://example.com/image.jpg',
					onChange: onChangeExternalUrl
				})
			)
		}

		// Wrap everything in BaseControl for proper styling
		return createElement(
			BaseControl,
			{
				key: property.id,
				label: property.label,
				help: property.help || '',
				__nextHasNoMarginBottom: true
			},
			controlElements
		)
	} catch (error) {
		console.error('Image control creation error:', error)
		const { createElement } = window.wp.element
		return createElement(
			'div',
			{
				style: { padding: '8px', border: '1px solid red', color: 'red' }
			},
			window.bricksData.i18n.errorCouldNotLoadImage
		)
	}
}

// Make function available globally
window.createBricksImageControl = createBricksImageControl
