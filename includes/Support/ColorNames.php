<?php
/**
 * Derives swatch colours from attribute term names.
 *
 * A shop with a hundred colour terms should not have to pick a hundred hex
 * values by hand, so a term without an explicit swatch colour falls back to a
 * guess taken from its name. German and English names are both recognised, as
 * are two tone names such as "Rosa/Weiss" and multicoloured ones such as "Bunt".
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Colour name lookup.
 */
class ColorNames {

	/** Gradient used for terms that mean "many colours". */
	public const RAINBOW = 'linear-gradient(135deg,#e53935 0 16%,#fb8c00 16% 33%,#fdd835 33% 50%,#43a047 50% 66%,#1e88e5 66% 83%,#8e24aa 83% 100%)';

	/** @var array<string,string>|null */
	private static $map = null;

	/**
	 * Normalised colour name to hex map.
	 *
	 * Keys are normalised: lowercase, umlauts folded, no punctuation.
	 *
	 * @return array<string,string>
	 */
	public static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$map = array(
			// Neutrals.
			'weiss'        => '#ffffff',
			'white'        => '#ffffff',
			'schwarz'      => '#111111',
			'black'        => '#111111',
			'grau'         => '#9aa0a6',
			'grey'         => '#9aa0a6',
			'gray'         => '#9aa0a6',
			'hellgrau'     => '#d5d9dd',
			'lightgrey'    => '#d5d9dd',
			'lightgray'    => '#d5d9dd',
			'dunkelgrau'   => '#4a4f55',
			'darkgrey'     => '#4a4f55',
			'darkgray'     => '#4a4f55',
			'anthrazit'    => '#383e42',
			'anthracite'   => '#383e42',
			'graphit'      => '#41424c',
			'graphite'     => '#41424c',
			'onyx'         => '#0f0f10',
			'schiefer'     => '#5a6470',
			'slate'        => '#5a6470',

			// Reds and pinks.
			'rot'          => '#d0021b',
			'red'          => '#d0021b',
			'hellrot'      => '#ff6b6b',
			'dunkelrot'    => '#8b0000',
			'darkred'      => '#8b0000',
			'bordeaux'     => '#6d071a',
			'burgundy'     => '#6d071a',
			'weinrot'      => '#6d071a',
			'wine'         => '#6d071a',
			'rosa'         => '#ff8fb1',
			'pink'         => '#ff69b4',
			'rose'         => '#e8b4b8',
			'altrosa'      => '#c48793',
			'magenta'      => '#d6006e',
			'fuchsia'      => '#d6006e',
			'koralle'      => '#ff7f50',
			'coral'        => '#ff7f50',
			'lachs'        => '#fa8072',
			'salmon'       => '#fa8072',

			// Oranges, yellows, browns.
			'orange'       => '#ff8c00',
			'apricot'      => '#fbceb1',
			'aprikose'     => '#fbceb1',
			'pfirsich'     => '#ffdab9',
			'peach'        => '#ffdab9',
			'gelb'         => '#ffd400',
			'yellow'       => '#ffd400',
			'senf'         => '#e1ad01',
			'mustard'      => '#e1ad01',
			'ocker'        => '#cc7722',
			'ochre'        => '#cc7722',
			'braun'        => '#8b5a2b',
			'brown'        => '#8b5a2b',
			'hellbraun'    => '#b98a58',
			'dunkelbraun'  => '#5a3a21',
			'camel'        => '#c19a6b',
			'cognac'       => '#9a463d',
			'taupe'        => '#6b5d52',
			'beige'        => '#e8dcc6',
			'sand'         => '#e0cda9',
			'creme'        => '#fdf6e3',
			'cream'        => '#fdf6e3',
			'ecru'         => '#f0e6d2',
			'nude'         => '#e3bc9a',
			'natur'        => '#e5d3b3',
			'natural'      => '#e5d3b3',
			'nature'       => '#e5d3b3',
			'milky'        => '#f7f3ec',
			'milch'        => '#f7f3ec',
			'vanille'      => '#f3e5ab',
			'vanilla'      => '#f3e5ab',
			'khaki'        => '#b5a642',
			'oliv'         => '#7a8b3c',
			'olive'        => '#7a8b3c',

			// Greens.
			'grun'         => '#2e9e4f',
			'green'        => '#2e9e4f',
			'hellgrun'     => '#8bc34a',
			'lightgreen'   => '#8bc34a',
			'dunkelgrun'   => '#14532d',
			'darkgreen'    => '#14532d',
			'mint'         => '#9fe2bf',
			'salbei'       => '#9caf88',
			'sage'         => '#9caf88',
			'organic'      => '#8a9a5b',
			'bio'          => '#8a9a5b',
			'limette'      => '#bfff00',
			'lime'         => '#bfff00',

			// Blues and purples.
			'blau'         => '#1f6feb',
			'blue'         => '#1f6feb',
			'hellblau'     => '#87ceeb',
			'lightblue'    => '#87ceeb',
			'dunkelblau'   => '#12306b',
			'darkblue'     => '#12306b',
			'navy'         => '#001f3f',
			'marine'       => '#002147',
			'jeans'        => '#4a6d8c',
			'denim'        => '#4a6d8c',
			'petrol'       => '#005f6a',
			'turkis'       => '#30c5b3',
			'turquoise'    => '#30c5b3',
			'cyan'         => '#00bcd4',
			'aqua'         => '#7fdbff',
			'lila'         => '#7b2fbf',
			'violett'      => '#7b2fbf',
			'violet'       => '#7b2fbf',
			'purple'       => '#7b2fbf',
			'flieder'      => '#b57edc',
			'lavendel'     => '#b57edc',
			'lavender'     => '#b57edc',
			'beere'        => '#7b2d43',
			'berry'        => '#7b2d43',

			// Metals and finishes.
			'gold'         => '#d4af37',
			'golden'       => '#d4af37',
			'silber'       => '#c0c0c0',
			'silver'       => '#c0c0c0',
			'bronze'       => '#cd7f32',
			'kupfer'       => '#b87333',
			'copper'       => '#b87333',
			'chrom'        => '#dbe2e9',
			'chrome'       => '#dbe2e9',
			'titan'        => '#878681',
			'titanium'     => '#878681',
			'perlmutt'     => '#f2eee6',
			'transparent'  => '#eef1f4',
			'klar'         => '#eef1f4',
			'clear'        => '#eef1f4',
			'standard'     => '#cfd4da',
			'default'      => '#cfd4da',
		);

		/**
		 * Filter the colour name dictionary used to guess swatch colours.
		 *
		 * @param array $map Normalised name => hex.
		 */
		self::$map = apply_filters( 'bsf_color_names', $map );

		return self::$map;
	}

	/**
	 * Names that mean "many colours" rather than one.
	 *
	 * @return string[]
	 */
	private static function multi_names(): array {
		return (array) apply_filters(
			'bsf_multicolour_names',
			array( 'bunt', 'mehrfarbig', 'multicolor', 'multicolour', 'multi', 'mixed', 'mix', 'rainbow', 'regenbogen', 'gemischt', 'colorful', 'colourful' )
		);
	}

	/**
	 * Fold a term name into a lookup key.
	 *
	 * @param string $value Raw name.
	 */
	public static function normalize( string $value ): string {
		$value = strtolower( $value );

		$value = strtr(
			$value,
			array(
				'ä' => 'a',
				'ö' => 'o',
				'ü' => 'u',
				'ß' => 'ss',
				'é' => 'e',
				'è' => 'e',
				'ê' => 'e',
				'á' => 'a',
				'à' => 'a',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ñ' => 'n',
				'ç' => 'c',
			)
		);

		$value = preg_replace( '/[^a-z0-9]+/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Split a term name into the parts that might each name a colour.
	 *
	 * @param string $name Raw term name.
	 *
	 * @return string[]
	 */
	private static function parts( string $name ): array {
		$split = preg_split( '#[/,+&|]|\s+und\s+|\s+and\s+|\s*-\s*|\s+#u', strtolower( $name ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $split ) || empty( $split ) ) {
			return array( $name );
		}

		// The whole name is tried first so "hellblau" wins over "hell" + "blau".
		array_unshift( $split, $name );

		return $split;
	}

	/**
	 * Guess the swatch appearance for a term name.
	 *
	 * @param string $name Term name.
	 * @param string $slug Term slug, used as a second chance.
	 *
	 * @return array{color:string,color2:string,gradient:string}
	 */
	public static function resolve( string $name, string $slug = '' ): array {
		$empty = array(
			'color'    => '',
			'color2'   => '',
			'gradient' => '',
		);

		$map   = self::map();
		$multi = self::multi_names();

		foreach ( array( $name, $slug ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$found = array();

			foreach ( self::parts( $candidate ) as $part ) {
				$key = self::normalize( $part );

				if ( '' === $key ) {
					continue;
				}

				if ( in_array( $key, $multi, true ) ) {
					return array(
						'color'    => '#e53935',
						'color2'   => '',
						'gradient' => self::RAINBOW,
					);
				}

				if ( isset( $map[ $key ] ) && ! in_array( $map[ $key ], $found, true ) ) {
					$found[] = $map[ $key ];
				}

				if ( count( $found ) >= 2 ) {
					break;
				}
			}

			if ( ! empty( $found ) ) {
				return array(
					'color'    => $found[0],
					'color2'   => $found[1] ?? '',
					'gradient' => '',
				);
			}
		}

		return $empty;
	}

	/**
	 * Build the inline style for a swatch.
	 *
	 * @param string $color    Primary colour.
	 * @param string $color2   Secondary colour.
	 * @param string $gradient Full gradient, wins over the colours.
	 */
	public static function style( string $color, string $color2 = '', string $gradient = '' ): string {
		if ( '' !== $gradient ) {
			return sprintf( 'background-image:%s;', $gradient );
		}

		if ( '' === $color ) {
			$color = '#dddddd';
		}

		if ( '' !== $color2 ) {
			return sprintf( 'background-image:linear-gradient(135deg,%s 0 50%%,%s 50%% 100%%);', $color, $color2 );
		}

		return sprintf( 'background-color:%s;', $color );
	}
}
