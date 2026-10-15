<?php

use MediaWiki\MediaWikiServices;

/**
 * RandomSelection -- randomly displays one of the given options.
 *
 * Tag syntax:
 *   <choose [group="name"] [uncached]><option>A</option><option>B</option></choose>
 *   Optional attribute: <option weight="3"> == 3x weight given
 *
 * Parser function syntax:
 *   {{#choose:[uncached]|[group=name]|option 1|...|option N}}
 *   Optional weight: {{#choose:3=option 1|option 2}}
 *
 * @file
 * @ingroup Extensions
 * @author Ross McClure <https://www.mediawiki.org/wiki/User:Algorithm>
 * @link https://www.mediawiki.org/wiki/Extension:RandomSelection Documentation
 */
class RandomSelection {

	/**
	 * Cache of normalized roll floats in [0, 1) keyed by group identifier.
	 *
	 * @var array<string, float>
	 */
	private static $groupCache = [];

	/**
	 * Reserved group names that bypass caching and force a fresh random roll.
	 *
	 * @var string[]
	 */
	private static $uncachedGroups = [ 'chaos', 'random', 'rand', 'none', 'c', 'r' ];

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
	 * Callback for register() which processes <choose> tag hooks.
	 *
	 * @param string $input User-supplied input inside the tag
	 * @param array $argv User-supplied attributes to the tag (e.g. group, uncached, before, after)
	 * @param MediaWiki\Parser\Parser $parser
	 * @return string
	 */
	public static function render( $input, $argv, $parser ) {
		# Invalidate page cache if uncached is requested
		$uncached = isset( $argv['uncached'] )
			&& !in_array( strtolower( trim( $argv['uncached'] ) ), [ '0', 'false', 'no' ], true );
		self::handleCacheExpiry( $uncached, $parser );

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

		# Retrieve cached or newly generated roll float
		$groupId = isset( $argv['group'] ) ? trim( $argv['group'] ) : null;
		$r = self::getRoll( $groupId );

		$selectedContent = '';
		for ( $i = 0; $i < count( $weights ); $i++ ) {
			if ( $weights[$i] >= $r ) {
				$selectedContent = $out[2][$i];
				break;
			}
		}
		if ( $selectedContent === '' && count( $out[2] ) > 0 ) {
			$selectedContent = end( $out[2] );
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
	 * Callback for the {{#choose:}} magic word parser function.
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
		self::handleCacheExpiry( $uncached, $parser );

		if ( $r <= 0 ) {
			return '';
		}

		// 4. Determine the roll float (reuse cached float if group exists)
		$roll = self::getRoll( $groupId );

		// 5. Select option based on the normalized roll
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

	/**
	 * Get an existing roll float for the given group, or roll and cache a new one.
	 *
	 * @param string|null $groupId The group identifier, or null if ungrouped
	 * @return float A normalized float in the half-open interval [0, 1)
	 */
	private static function getRoll( $groupId ) {
		$isUncached = ( $groupId === null || in_array( strtolower( $groupId ), self::$uncachedGroups, true ) );

		if ( !$isUncached && isset( self::$groupCache[$groupId] ) ) {
			return self::$groupCache[$groupId];
		}

		// Random float in [0, 1)
		$roll = mt_rand() / ( mt_getrandmax() + 1 );

		if ( !$isUncached ) {
			self::$groupCache[$groupId] = $roll;
		}

		return $roll;
	}

	/**
	 * Invalidate parser output cache if uncached selection is requested and allowed.
	 *
	 * @param bool $uncached
	 * @param Parser $parser
	 */
	private static function handleCacheExpiry( $uncached, $parser ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( $uncached && $config->get( 'RandomSelectionAllowUncached' ) ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}
	}

}
