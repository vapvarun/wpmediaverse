import { __ } from '@wordpress/i18n';
import {
	ToggleControl,
	__experimentalNumberControl as NumberControl,
	ColorPalette,
	BaseControl,
	Flex,
	FlexItem,
} from '@wordpress/components';

export default function BoxShadowControl( {
	enabled = false,
	horizontal = 0,
	vertical = 4,
	blur = 8,
	spread = 0,
	color = 'rgba(0, 0, 0, 0.12)',
	onChange,
	onToggle,
	onChangeHorizontal,
	onChangeVertical,
	onChangeBlur,
	onChangeSpread,
	onChangeColor,
} ) {
	const handleToggle = onToggle || ( ( val ) => onChange?.( { boxShadow: val } ) );
	const handleHorizontal = onChangeHorizontal || ( ( val ) => onChange?.( { shadowHorizontal: val } ) );
	const handleVertical = onChangeVertical || ( ( val ) => onChange?.( { shadowVertical: val } ) );
	const handleBlur = onChangeBlur || ( ( val ) => onChange?.( { shadowBlur: val } ) );
	const handleSpread = onChangeSpread || ( ( val ) => onChange?.( { shadowSpread: val } ) );
	const handleColor = onChangeColor || ( ( val ) => onChange?.( { shadowColor: val } ) );

	return (
		<BaseControl className="mvs-box-shadow-control">
			<ToggleControl
				label={ __( 'Box Shadow', 'wpmediaverse' ) }
				checked={ enabled }
				onChange={ handleToggle }
				__nextHasNoMarginBottom
			/>
			{ enabled && (
				<>
					<Flex gap={ 2 }>
						<FlexItem isBlock>
							<NumberControl
								label={ __( 'X', 'wpmediaverse' ) }
								value={ horizontal }
								onChange={ ( val ) => handleHorizontal( Number( val ) ) }
								min={ -50 }
								max={ 50 }
							/>
						</FlexItem>
						<FlexItem isBlock>
							<NumberControl
								label={ __( 'Y', 'wpmediaverse' ) }
								value={ vertical }
								onChange={ ( val ) => handleVertical( Number( val ) ) }
								min={ -50 }
								max={ 50 }
							/>
						</FlexItem>
					</Flex>
					<Flex gap={ 2 }>
						<FlexItem isBlock>
							<NumberControl
								label={ __( 'Blur', 'wpmediaverse' ) }
								value={ blur }
								onChange={ ( val ) => handleBlur( Number( val ) ) }
								min={ 0 }
								max={ 100 }
							/>
						</FlexItem>
						<FlexItem isBlock>
							<NumberControl
								label={ __( 'Spread', 'wpmediaverse' ) }
								value={ spread }
								onChange={ ( val ) => handleSpread( Number( val ) ) }
								min={ -50 }
								max={ 50 }
							/>
						</FlexItem>
					</Flex>
					<BaseControl label={ __( 'Shadow Color', 'wpmediaverse' ) }>
						<ColorPalette
							value={ color }
							onChange={ handleColor }
							clearable={ false }
						/>
					</BaseControl>
				</>
			) }
		</BaseControl>
	);
}
