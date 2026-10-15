<?php

use MediaWiki\MediaWikiServices;

/**
 * RandomSelection -- randomly displays one of the given options.
 * Usage: <choose><option>A</option><option>B</option></choose>
 * Optional parameter: <option weight="3"> == 3x weight given
 * Optional parameter: <option group="1"> == randomization group 1
 *
 * @file
 * @ingroup Extensions
 * @author Ross McClure <https://www.mediawiki.org/wiki/User:Algorithm>
 * @link https://www.mediawiki.org/wiki/Extension:RandomSelection Documentation
 */
class RandomSelection {
	/**
	 * Storage for per-page randomization cache groups.
	 */
	private static $groupCache = [];
	
	/**
	 * Register the <choose> tag and {{#choose:option 1|...|option N}} function
	 * with the Parser.
	 *
	 * @param MediaWiki\Parser\Parser &$parser
	 */
	public static function register( &$parser ) {
		$parser->setHook( 'choose', [ __CLASS__, 'render' ] );
		$parser->setFunctionHook( 'choose', [ __CLASS__, 'renderParserFunction' ],
			Parser::SFH_OBJECT_ARGS );
	}

	/**
	 * Register the magic word ID.
	 *
	 * @param array &$variableIds
	 */
	public static function variableIds( &$variableIds ) {
		$variableIds[] = 'choose';
	}

	/**
	 * Callback for register() which actually does all the processing.
	 *
	 * @param string $input User-supplied input
	 * @param array $argv User-supplied arguments to the tag, e.g. <choose uncached>...</choose>
	 * @param MediaWiki\Parser\Parser $parser
	 * @return string
	 */
	public static function render( $input, $argv, $parser ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		# Prevent caching if specified so by the user
		if ( isset( $argv['uncached'] ) && $config->get( 'RandomSelectionAllowUncached' ) ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		# Parse the options and calculate total weight
		$len = preg_match_all(
			"/<option(?:(?:\\s[^>]*?)?\\sweight=[\"']?([^\\s>]+))?"
				. "(?:\\s[^>]*)?>([\\s\\S]*?)<\\/option>/",
			$input,
			$out
		);

		# Find any references to a surrounding template
		preg_match_all(
			"/<choicetemplate(?:(?:\\s[^>]*?)?\\sweight=[\"']?([^\\s>]+))?"
				. "(?:\\s[^>]*)?>([\\s\\S]*?)<\\/choicetemplate>/",
			$input,
			$outTemplate
		);

		// weights is cumulative in ascending order.
		// So if we have 2 options equally weighted, by the end
		// of all this, the weights should be 0.5 and 1.0
		$weights = [];
		$normalizingFactor = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$curWeight = 1;
			if ( strlen( $out[1][$i] ) > 0 ) {
				$curWeight = abs( floatval( $out[1][$i] ) );
			}
			$normalizingFactor += $curWeight;
			$weights[$i] = $normalizingFactor;
		}

		if ( $normalizingFactor === 0 ) {
			// I guess we have 0 choices
			return '';
		}

		foreach ( $weights as $index => &$weight ) {
			$weight = $weight / $normalizingFactor;
		}

		// Ok, so now we have an array of ascending weights
		// that are cumulative. e.g. [0.25, 0.5, 0.75, 1].
		// We get a random float, and pick the item with the
		// smallest weight that is greater than our float.
		$r = mt_rand() / mt_getrandmax();

		$selectedContent = '';
		for ( $i = 0; $i < count( $weights ); $i++ ) {
			if ( $weights[$i] >= $r ) {
				$selectedContent = $out[2][$i];
				break;
			}
		}

		# Surround by template if applicable
		if ( isset( $outTemplate[2][0] ) ) {
			$selectedContent = '{{' . $outTemplate[2][0] . '|' . $selectedContent . '}}';
		}

		# Parse tags and return
		if ( isset( $argv['before'] ) ) {
			$selectedContent = $argv['before'] . $selectedContent;
		}
		if ( isset( $argv['after'] ) ) {
			$selectedContent .= $argv['after'];
		}

		return $parser->recursiveTagParse( $selectedContent );
	}

	/**
	 * Callback for the {{#choose:}} magic word magic (see register() in this file)
	 *
	 * @param MediaWiki\Parser\Parser &$parser
	 * @param MediaWiki\Parser\PPFrame $frame
	 * @param array $args User-supplied arguments
	 * @return string
	 */
	public static function renderParserFunction( &$parser, $frame, $args ) {
		$options = [];
		$r = 0;
		$groupId = null;
		$uncached = false;
		$uncachedGroups = [ 'chaos', 'random', 'rand', 'none', 'c', 'r' ];

		// 1. Process the first argument (string)
		$arg = array_shift( $args );
		$parts = explode( '=', $arg, 2 );
		if ( count( $parts ) == 2 ) {
			$key = strtolower( trim( $parts[0] ) );
			$val = trim( $parts[1] );
			if ( $key === 'group' ) {
				$groupId = $val;
			} elseif ( $key === 'uncached' ) {
				$uncached = !in_array( strtolower( $val ), [ '0', 'false', 'no' ], true );
			} else {
				$options[] = [ intval( $key ), $parts[1] ];
				$r += intval( $key );
			}
		} elseif ( count( $parts ) == 1 ) {
			$val = trim( $parts[0] );
			if ( strtolower( $val ) === 'uncached' ) {
				$uncached = true;
			} else {
				$options[] = [ 1, $parts[0] ];
				$r += 1;
			}
		}

		// 2. Process the remaining arguments (PPNode objects)
		foreach ( $args as $arg ) {
			$bits = $arg->splitArg();
			$nameNode = $bits['name'];
			$index = $bits['index'];
			$valueNode = $bits['value'];

			if ( $index === '' ) {
				// Named parameter: key=value
				$name = strtolower( trim( $frame->expand( $nameNode ) ) );
				if ( $name === 'group' ) {
					$groupId = trim( $frame->expand( $valueNode ) );
					continue;
				}
				if ( $name === 'uncached' ) {
					$val = trim( $frame->expand( $valueNode ) );
					$uncached = !in_array( strtolower( $val ), [ '0', 'false', 'no' ], true );
					continue;
				}
				$options[] = [ intval( $name ), $valueNode ];
				$r += intval( $name );
			} else {
				// Positional parameter
				$val = trim( $frame->expand( $valueNode ) );
				if ( strtolower( $val ) === 'uncached' ) {
					$uncached = true;
					continue;
				}
				$options[] = [ 1, $valueNode ];
				$r += 1;
			}
		}

		// 3. MediaWiki page cache expiry check
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( $uncached && $config->get( 'RandomSelectionAllowUncached' ) ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		if ( $r <= 0 ) {
			return '';
		}

		// 4. Determine the roll float (reuse cached float if group exists)
		$isGroupUncached = ( $groupId === null || in_array( strtolower( $groupId ), $uncachedGroups, true ) );
		if ( !$isGroupUncached && isset( self::$groupCache[$groupId] ) ) {
			$roll = self::$groupCache[$groupId];
		} else {
			// Float in [0, 1)
			$roll = mt_rand() / ( mt_getrandmax() + 1 );
			if ( !$isGroupUncached ) {
				self::$groupCache[$groupId] = $roll;
			}
		}

		// 5. Select the option based on the normalized roll
		$target = $roll * $r;
		$current = 0;
		$output = null;
		foreach ( $options as $opt ) {
			$current += $opt[0];
			if ( $current > $target ) {
				$output = $opt[1];
				break;
			}
		}
		if ( $output === null ) {
			$output = end( $options )[1];
		}

		return trim( $frame->expand( $output ) );
	}

}
