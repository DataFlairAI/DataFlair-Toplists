import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl, RangeControl, Spinner, TextareaControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';
import metadata from './block.json';

registerBlockType(metadata.name, {
	edit: ({ attributes, setAttributes }) => {
		const { 
			toplistId, title, limit,
			ribbonBgColor, ribbonTextColor, ribbonText,
			rankBgColor, rankTextColor, rankBorderRadius,
			brandLinkColor,
			bonusLabelStyle, bonusTextStyle,
			featureCheckBg, featureCheckColor, featureTextColor,
			ctaBgColor, ctaHoverBgColor, ctaTextColor, ctaBorderRadius, ctaShadow,
			metricLabelStyle, metricValueStyle,
			rgBorderColor, rgTextColor,
			prosCons
		} = attributes;
		const blockProps = useBlockProps();
		const [toplists, setToplists] = useState([]);
		const [loading, setLoading] = useState(true);
		const [casinos, setCasinos] = useState([]);
		const [loadingCasinos, setLoadingCasinos] = useState(false);

		useEffect(() => {
			apiFetch({ path: '/dataflair/v1/toplists' })
				.then((data) => {
					setToplists([
						{ value: '', label: __('-- Select a toplist --', 'dataflair-toplists') },
						...data
					]);
					setLoading(false);
				})
				.catch(() => {
					setToplists([
						{ value: '', label: __('-- Select a toplist --', 'dataflair-toplists') }
					]);
					setLoading(false);
				});
		}, []);

		useEffect(() => {
			if (toplistId) {
				setLoadingCasinos(true);
				apiFetch({ path: `/dataflair/v1/toplists/${toplistId}/casinos` })
					.then((data) => {
						setCasinos(data || []);
						setLoadingCasinos(false);
					})
					.catch(() => {
						setCasinos([]);
						setLoadingCasinos(false);
					});
			} else {
				setCasinos([]);
			}
		}, [toplistId]);

		const updateProsCons = (casinoKey, field, value) => {
			const currentProsCons = prosCons || {};
			const casinoData = currentProsCons[casinoKey] || { pros: [], cons: [] };
			casinoData[field] = value;
			setAttributes({
				prosCons: {
					...currentProsCons,
					[casinoKey]: casinoData
				}
			});
		};

		const updateProsConsArray = (casinoKey, field, index, value) => {
			const currentProsCons = prosCons || {};
			const casinoData = currentProsCons[casinoKey] || { pros: [], cons: [] };
			const newArray = [...(casinoData[field] || [])];
			newArray[index] = value;
			casinoData[field] = newArray;
			setAttributes({
				prosCons: {
					...currentProsCons,
					[casinoKey]: casinoData
				}
			});
		};

		const addProsConsItem = (casinoKey, field) => {
			const currentProsCons = prosCons || {};
			const casinoData = currentProsCons[casinoKey] || { pros: [], cons: [] };
			const newArray = [...(casinoData[field] || []), ''];
			casinoData[field] = newArray;
			setAttributes({
				prosCons: {
					...currentProsCons,
					[casinoKey]: casinoData
				}
			});
		};

		const removeProsConsItem = (casinoKey, field, index) => {
			const currentProsCons = prosCons || {};
			const casinoData = currentProsCons[casinoKey] || { pros: [], cons: [] };
			const newArray = [...(casinoData[field] || [])];
			newArray.splice(index, 1);
			casinoData[field] = newArray;
			setAttributes({
				prosCons: {
					...currentProsCons,
					[casinoKey]: casinoData
				}
			});
		};

		return (
			<>
				<InspectorControls>
					<PanelBody title={__('Toplist Settings', 'dataflair-toplists')} initialOpen={true}>
						{loading ? (
							<Spinner />
						) : (
							<SelectControl
								label={__('Select Toplist', 'dataflair-toplists')}
								value={toplistId}
								options={toplists}
								onChange={(value) => setAttributes({ toplistId: value })}
								help={__('Choose a toplist to display', 'dataflair-toplists')}
							/>
						)}
						<TextControl
							label={__('Custom Title', 'dataflair-toplists')}
							value={title}
							onChange={(value) => setAttributes({ title: value })}
							help={__('Optional: Override the default toplist title', 'dataflair-toplists')}
						/>
						<RangeControl
							label={__('Limit Results', 'dataflair-toplists')}
							value={limit}
							onChange={(value) => setAttributes({ limit: value || 0 })}
							min={0}
							max={50}
							help={__('Number of casinos to display (0 = all)', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					<PanelBody title={__('Ribbon / Highlight Bar', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Background Color', 'dataflair-toplists')}
							value={ribbonBgColor}
							onChange={(value) => setAttributes({ ribbonBgColor: value || 'brand-600' })}
							help={__('Tailwind class: bg-[color] (e.g., bg-blue-600, bg-[#ff0000])', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Text Color', 'dataflair-toplists')}
							value={ribbonTextColor}
							onChange={(value) => setAttributes({ ribbonTextColor: value || 'white' })}
							help={__('Tailwind class: text-[color]', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Ribbon Text', 'dataflair-toplists')}
							value={ribbonText}
							onChange={(value) => setAttributes({ ribbonText: value || 'Our Top Choice' })}
							help={__('Text to display on the ribbon (e.g., "Our Top Choice", "Editor\'s Pick")', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					<PanelBody title={__('Rank Badge', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Background Color', 'dataflair-toplists')}
							value={rankBgColor}
							onChange={(value) => setAttributes({ rankBgColor: value || 'gray-100' })}
							help={__('Tailwind class: bg-[color]', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Text Color', 'dataflair-toplists')}
							value={rankTextColor}
							onChange={(value) => setAttributes({ rankTextColor: value || 'gray-900' })}
							help={__('Tailwind class: text-[color]', 'dataflair-toplists')}
						/>
						<SelectControl
							label={__('Border Radius', 'dataflair-toplists')}
							value={rankBorderRadius}
							options={[
								{ value: 'rounded-none', label: __('Square', 'dataflair-toplists') },
								{ value: 'rounded', label: __('Standard', 'dataflair-toplists') },
								{ value: 'rounded-full', label: __('Pill', 'dataflair-toplists') },
							]}
							onChange={(value) => setAttributes({ rankBorderRadius: value })}
						/>
					</PanelBody>
					
					<PanelBody title={__('Brand Links', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Link Color', 'dataflair-toplists')}
							value={brandLinkColor}
							onChange={(value) => setAttributes({ brandLinkColor: value || 'brand-600' })}
							help={__('Color for review links and "More Information" link. Tailwind class: text-[color]', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					<PanelBody title={__('Welcome Bonus', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Label Style', 'dataflair-toplists')}
							value={bonusLabelStyle}
							onChange={(value) => setAttributes({ bonusLabelStyle: value || 'text-gray-600' })}
							help={__('Tailwind classes for "Welcome bonus:" label', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Bonus Text Style', 'dataflair-toplists')}
							value={bonusTextStyle}
							onChange={(value) => setAttributes({ bonusTextStyle: value || 'text-gray-900 text-lg leading-6 font-semibold' })}
							help={__('Tailwind classes for the bonus offer text', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					<PanelBody title={__('Feature Bullets', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Check Icon Background', 'dataflair-toplists')}
							value={featureCheckBg}
							onChange={(value) => setAttributes({ featureCheckBg: value || 'green-100' })}
							help={__('Tailwind class: bg-[color]', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Check Icon Color', 'dataflair-toplists')}
							value={featureCheckColor}
							onChange={(value) => setAttributes({ featureCheckColor: value || 'green-600' })}
							help={__('Tailwind class: text-[color]', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Feature Text Color', 'dataflair-toplists')}
							value={featureTextColor}
							onChange={(value) => setAttributes({ featureTextColor: value || 'gray-600' })}
							help={__('Tailwind class: text-[color]', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					<PanelBody title={__('CTA Button', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Background Color', 'dataflair-toplists')}
							value={ctaBgColor}
							onChange={(value) => setAttributes({ ctaBgColor: value || 'brand-600' })}
							help={__('Tailwind class: bg-[color]', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Hover Background Color', 'dataflair-toplists')}
							value={ctaHoverBgColor}
							onChange={(value) => setAttributes({ ctaHoverBgColor: value || 'brand-700' })}
							help={__('Tailwind class: hover:bg-[color]', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Text Color', 'dataflair-toplists')}
							value={ctaTextColor}
							onChange={(value) => setAttributes({ ctaTextColor: value || 'white' })}
							help={__('Tailwind class: text-[color]', 'dataflair-toplists')}
						/>
						<SelectControl
							label={__('Border Radius', 'dataflair-toplists')}
							value={ctaBorderRadius}
							options={[
								{ value: 'rounded-none', label: __('Square', 'dataflair-toplists') },
								{ value: 'rounded', label: __('Standard', 'dataflair-toplists') },
								{ value: 'rounded-full', label: __('Pill', 'dataflair-toplists') },
							]}
							onChange={(value) => setAttributes({ ctaBorderRadius: value })}
						/>
						<TextControl
							label={__('Shadow', 'dataflair-toplists')}
							value={ctaShadow}
							onChange={(value) => setAttributes({ ctaShadow: value || 'shadow-md' })}
							help={__('Tailwind shadow class (e.g., shadow-md, shadow-lg, shadow-none)', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					<PanelBody title={__('Metrics', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Label Style', 'dataflair-toplists')}
							value={metricLabelStyle}
							onChange={(value) => setAttributes({ metricLabelStyle: value || 'text-gray-600' })}
							help={__('Tailwind classes for metric labels (e.g., "Bonus Wagering", "Min Deposit")', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Value Style', 'dataflair-toplists')}
							value={metricValueStyle}
							onChange={(value) => setAttributes({ metricValueStyle: value || 'text-gray-900 font-semibold' })}
							help={__('Tailwind classes for metric values', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					<PanelBody title={__('Responsible Gambling', 'dataflair-toplists')} initialOpen={false}>
						<TextControl
							label={__('Border Top Color', 'dataflair-toplists')}
							value={rgBorderColor}
							onChange={(value) => setAttributes({ rgBorderColor: value || 'gray-300' })}
							help={__('Tailwind class: border-[color]', 'dataflair-toplists')}
						/>
						<TextControl
							label={__('Text Color', 'dataflair-toplists')}
							value={rgTextColor}
							onChange={(value) => setAttributes({ rgTextColor: value || 'gray-600' })}
							help={__('Tailwind class: text-[color] (e.g., text-gray-500, text-gray-600)', 'dataflair-toplists')}
						/>
					</PanelBody>
					
					{toplistId && (
						<PanelBody title={__('Pros & Cons', 'dataflair-toplists')} initialOpen={false}>
							{loadingCasinos ? (
								<Spinner />
							) : casinos.length > 0 ? (
								casinos.map((casino) => {
									const casinoKey = `casino-${casino.position}-${casino.brandSlug}`;
									const hasBlockOverride = prosCons && prosCons[casinoKey];
									const casinoProsCons = hasBlockOverride ? prosCons[casinoKey] : null;
									
									// Use block-level overrides if they exist, otherwise show API defaults (but don't save them)
									const displayPros = hasBlockOverride && casinoProsCons?.pros ? casinoProsCons.pros : (casino.pros || []);
									const displayCons = hasBlockOverride && casinoProsCons?.cons ? casinoProsCons.cons : (casino.cons || []);
									
									// Check if we're using API defaults (no block-level override exists)
									const usingApiDefaults = !hasBlockOverride;
									
									return (
										<div key={casinoKey} style={{ marginBottom: '20px', padding: '10px', border: '1px solid #ddd', borderRadius: '4px' }}>
											<strong>{casino.brandName} (Position {casino.position})</strong>
											{usingApiDefaults && (casino.pros?.length > 0 || casino.cons?.length > 0) && (
												<p style={{ fontSize: '12px', color: '#666', fontStyle: 'italic', marginTop: '5px' }}>
													{__('Currently showing API defaults. Edit below to override.', 'dataflair-toplists')}
												</p>
											)}
											{!usingApiDefaults && (
												<p style={{ fontSize: '12px', color: '#0073aa', fontStyle: 'italic', marginTop: '5px' }}>
													{__('Using custom overrides (API defaults are replaced).', 'dataflair-toplists')}
												</p>
											)}
											<div style={{ marginTop: '10px' }}>
												<strong>{__('Pros:', 'dataflair-toplists')}</strong>
												{displayPros.length === 0 && (
													<p style={{ fontSize: '12px', color: '#999', fontStyle: 'italic', marginTop: '5px' }}>
														{__('No pros from API. Add custom pros below.', 'dataflair-toplists')}
													</p>
												)}
												{displayPros.map((pro, index) => {
													// Check if this is from API defaults (not in block-level override)
													const isApiDefault = usingApiDefaults && casino.pros?.includes(pro);
													return (
														<div key={`pro-${index}`} style={{ display: 'flex', gap: '5px', marginTop: '5px', alignItems: 'center' }}>
															<TextControl
																value={pro}
																onChange={(value) => {
																	// When user edits, initialize block-level override if needed
																	if (usingApiDefaults) {
																		// Initialize with API defaults, then update
																		const currentProsCons = prosCons || {};
																		const newPros = [...(casino.pros || [])];
																		newPros[index] = value;
																		setAttributes({
																			prosCons: {
																				...currentProsCons,
																				[casinoKey]: {
																					pros: newPros,
																					cons: casinoProsCons?.cons || []
																				}
																			}
																		});
																	} else {
																		updateProsConsArray(casinoKey, 'pros', index, value);
																	}
																}}
																style={{ flex: 1 }}
																placeholder={isApiDefault ? __('API default (edit to override)', 'dataflair-toplists') : ''}
															/>
															{!isApiDefault && (
																<Button isDestructive onClick={() => removeProsConsItem(casinoKey, 'pros', index)}>
																	{__('Remove', 'dataflair-toplists')}
																</Button>
															)}
														</div>
													);
												})}
												<Button isSecondary onClick={() => {
													// Initialize with API defaults if needed
													if (usingApiDefaults) {
														const currentProsCons = prosCons || {};
														setAttributes({
															prosCons: {
																...currentProsCons,
																[casinoKey]: {
																	pros: [...(casino.pros || []), ''],
																	cons: casinoProsCons?.cons || []
																}
															}
														});
													} else {
														addProsConsItem(casinoKey, 'pros');
													}
												}} style={{ marginTop: '5px' }}>
													{__('+ Add Pro', 'dataflair-toplists')}
												</Button>
											</div>
											<div style={{ marginTop: '15px' }}>
												<strong>{__('Cons:', 'dataflair-toplists')}</strong>
												{displayCons.length === 0 && (
													<p style={{ fontSize: '12px', color: '#999', fontStyle: 'italic', marginTop: '5px' }}>
														{__('No cons from API. Add custom cons below.', 'dataflair-toplists')}
													</p>
												)}
												{displayCons.map((con, index) => {
													// Check if this is from API defaults (not in block-level override)
													const isApiDefault = usingApiDefaults && casino.cons?.includes(con);
													return (
														<div key={`con-${index}`} style={{ display: 'flex', gap: '5px', marginTop: '5px', alignItems: 'center' }}>
															<TextControl
																value={con}
																onChange={(value) => {
																	// When user edits, initialize block-level override if needed
																	if (usingApiDefaults) {
																		// Initialize with API defaults, then update
																		const currentProsCons = prosCons || {};
																		const newCons = [...(casino.cons || [])];
																		newCons[index] = value;
																		setAttributes({
																			prosCons: {
																				...currentProsCons,
																				[casinoKey]: {
																					pros: casinoProsCons?.pros || [],
																					cons: newCons
																				}
																			}
																		});
																	} else {
																		updateProsConsArray(casinoKey, 'cons', index, value);
																	}
																}}
																style={{ flex: 1 }}
																placeholder={isApiDefault ? __('API default (edit to override)', 'dataflair-toplists') : ''}
															/>
															{!isApiDefault && (
																<Button isDestructive onClick={() => removeProsConsItem(casinoKey, 'cons', index)}>
																	{__('Remove', 'dataflair-toplists')}
																</Button>
															)}
														</div>
													);
												})}
												<Button isSecondary onClick={() => {
													// Initialize with API defaults if needed
													if (usingApiDefaults) {
														const currentProsCons = prosCons || {};
														setAttributes({
															prosCons: {
																...currentProsCons,
																[casinoKey]: {
																	pros: casinoProsCons?.pros || [],
																	cons: [...(casino.cons || []), '']
																}
															}
														});
													} else {
														addProsConsItem(casinoKey, 'cons');
													}
												}} style={{ marginTop: '5px' }}>
													{__('+ Add Con', 'dataflair-toplists')}
												</Button>
											</div>
										</div>
									);
								})
							) : (
								<p>{__('No casinos found. Please sync the toplist first.', 'dataflair-toplists')}</p>
							)}
						</PanelBody>
					)}
				</InspectorControls>
				<div {...blockProps}>
					{toplistId ? (
						<ServerSideRender
							block="dataflair-toplists/toplist"
							attributes={attributes}
						/>
					) : (
						<div className="dataflair-block-placeholder" style={{ padding: '20px', textAlign: 'center', background: '#f0f0f1', border: '1px dashed #ccc' }}>
							<p>{__('Please select a toplist from the block settings.', 'dataflair-toplists')}</p>
						</div>
					)}
				</div>
			</>
		);
	},
	save: () => {
		// Server-side rendered, so return null
		return null;
	},
});

