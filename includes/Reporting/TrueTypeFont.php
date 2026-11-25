<?php
namespace TBSHComplianceAuditLogger\Reporting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal TrueType reader and subsetter for embedding a Unicode font in PDF reports.
 *
 * Reads the character map, glyph widths and metrics, and writes a subset that keeps
 * every glyph number (so the PDF can use CIDToGIDMap /Identity) but empties the
 * outlines of glyphs the document does not use. No external libraries.
 */
class TrueTypeFont {

	/**
	 * Raw font data.
	 *
	 * @var string
	 */
	private $data;

	/**
	 * Table directory: tag => [offset, length].
	 *
	 * @var array
	 */
	private $tables = array();

	/**
	 * Unicode code point => glyph ID.
	 *
	 * @var array
	 */
	private $cmap = array();

	/**
	 * Advance width per glyph (font units).
	 *
	 * @var int[]
	 */
	private $advances = array();

	/**
	 * Metrics: units_per_em, bbox, ascent, descent, cap_height, num_glyphs, loca_long.
	 *
	 * @var array
	 */
	public $metrics = array();

	/**
	 * Font name without spaces (for /BaseFont).
	 *
	 * @var string
	 */
	public $name = 'DejaVuSans';

	/**
	 * Load and parse a font file.
	 *
	 * @param string $path TrueType file.
	 * @return self|null Null when the file is missing or not a usable TrueType font.
	 */
	public static function load( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$font       = new self();
		$font->data = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return $font->parse() ? $font : null;
	}

	/**
	 * Glyph ID for a code point (0 when the font has no glyph).
	 *
	 * @param int $code_point Unicode code point.
	 * @return int
	 */
	public function glyph( $code_point ) {
		return $this->cmap[ $code_point ] ?? 0;
	}

	/**
	 * Advance width of a glyph in 1/1000 em.
	 *
	 * @param int $gid Glyph ID.
	 * @return float
	 */
	public function width( $gid ) {
		$advance = $this->advances[ $gid ] ?? end( $this->advances );
		return $advance * 1000 / $this->metrics['units_per_em'];
	}

	/**
	 * Parse the tables the PDF writer needs.
	 *
	 * @return bool
	 */
	private function parse() {
		if ( strlen( $this->data ) < 12 || ! in_array( substr( $this->data, 0, 4 ), array( "\x00\x01\x00\x00", 'true' ), true ) ) {
			return false; // TrueType outlines only (no CFF, no collections).
		}
		$count = $this->u16( 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$entry                       = 12 + 16 * $i;
			$this->tables[ substr( $this->data, $entry, 4 ) ] = array( $this->u32( $entry + 8 ), $this->u32( $entry + 12 ) );
		}
		foreach ( array( 'head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap' ) as $required ) {
			if ( ! isset( $this->tables[ $required ] ) ) {
				return false;
			}
		}

		$head = $this->tables['head'][0];
		$upem = $this->u16( $head + 18 );
		if ( ! $upem ) {
			return false;
		}
		$scale = 1000 / $upem;
		$hhea  = $this->tables['hhea'][0];
		$os2   = $this->tables['OS/2'][0] ?? null;

		$this->metrics = array(
			'units_per_em' => $upem,
			'bbox'         => array(
				(int) round( $this->s16( $head + 36 ) * $scale ),
				(int) round( $this->s16( $head + 38 ) * $scale ),
				(int) round( $this->s16( $head + 40 ) * $scale ),
				(int) round( $this->s16( $head + 42 ) * $scale ),
			),
			'loca_long'    => 1 === $this->s16( $head + 50 ),
			'ascent'       => (int) round( $this->s16( $hhea + 4 ) * $scale ),
			'descent'      => (int) round( $this->s16( $hhea + 6 ) * $scale ),
			'num_glyphs'   => $this->u16( $this->tables['maxp'][0] + 4 ),
		);
		$cap                         = ( $os2 && $this->u16( $os2 ) >= 2 ) ? $this->s16( $os2 + 88 ) : 0;
		$this->metrics['cap_height'] = $cap ? (int) round( $cap * $scale ) : $this->metrics['ascent'];

		// Advance widths: the first numberOfHMetrics glyphs each have one, the rest reuse the last.
		$metrics = $this->u16( $hhea + 34 );
		$hmtx    = $this->tables['hmtx'][0];
		for ( $g = 0; $g < $metrics; $g++ ) {
			$this->advances[ $g ] = $this->u16( $hmtx + 4 * $g );
		}

		return $this->parse_cmap();
	}

	/**
	 * Read the Unicode character map (format 12 preferred, else format 4).
	 *
	 * @return bool
	 */
	private function parse_cmap() {
		$cmap    = $this->tables['cmap'][0];
		$count   = $this->u16( $cmap + 2 );
		$best    = null;
		$quality = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$platform = $this->u16( $cmap + 4 + 8 * $i );
			$encoding = $this->u16( $cmap + 6 + 8 * $i );
			$offset   = $cmap + $this->u32( $cmap + 8 + 8 * $i );
			$format   = $this->u16( $offset );
			$q        = ( 12 === $format && ( 3 === $platform && 10 === $encoding || 0 === $platform ) ) ? 2 : ( ( 4 === $format && ( 3 === $platform && 1 === $encoding || 0 === $platform ) ) ? 1 : 0 );
			if ( $q > $quality ) {
				$quality = $q;
				$best    = $offset;
			}
		}
		if ( null === $best ) {
			return false;
		}

		if ( 12 === $this->u16( $best ) ) {
			$groups = $this->u32( $best + 12 );
			for ( $i = 0; $i < $groups; $i++ ) {
				$g     = $best + 16 + 12 * $i;
				$start = $this->u32( $g );
				$end   = min( $this->u32( $g + 4 ), $start + 0xFFFF );
				$gid   = $this->u32( $g + 8 );
				for ( $c = $start; $c <= $end; $c++ ) {
					$this->cmap[ $c ] = $gid + ( $c - $start );
				}
			}
			return true;
		}

		// Format 4: segments of 16-bit code ranges.
		$segs      = $this->u16( $best + 6 ) / 2;
		$ends      = $best + 14;
		$starts    = $ends + 2 * $segs + 2;
		$deltas    = $starts + 2 * $segs;
		$ranges    = $deltas + 2 * $segs;
		for ( $s = 0; $s < $segs; $s++ ) {
			$end    = $this->u16( $ends + 2 * $s );
			$start  = $this->u16( $starts + 2 * $s );
			$delta  = $this->s16( $deltas + 2 * $s );
			$roff   = $this->u16( $ranges + 2 * $s );
			if ( 0xFFFF === $start ) {
				continue;
			}
			for ( $c = $start; $c <= $end; $c++ ) {
				if ( 0 === $roff ) {
					$gid = ( $c + $delta ) & 0xFFFF;
				} else {
					$addr = $ranges + 2 * $s + $roff + 2 * ( $c - $start );
					$gid  = $this->u16( $addr );
					$gid  = $gid ? ( $gid + $delta ) & 0xFFFF : 0;
				}
				if ( $gid ) {
					$this->cmap[ $c ] = $gid;
				}
			}
		}
		return true;
	}

	/**
	 * Build a subset containing only the given glyphs (plus .notdef and composite parts).
	 *
	 * Glyph IDs are unchanged; unused glyphs are kept as empty outlines.
	 *
	 * @param int[] $gids Glyph IDs used by the document.
	 * @return string TrueType font data.
	 */
	public function subset( array $gids ) {
		$keep = array( 0 => true );
		$todo = array_values( array_unique( array_map( 'intval', $gids ) ) );
		while ( $todo ) {
			$gid = array_pop( $todo );
			if ( isset( $keep[ $gid ] ) && 0 !== $gid ) {
				continue;
			}
			$keep[ $gid ] = true;
			foreach ( $this->components( $gid ) as $part ) {
				if ( ! isset( $keep[ $part ] ) ) {
					$todo[] = $part;
				}
			}
		}

		$glyf = '';
		$loca = '';
		$n    = $this->metrics['num_glyphs'];
		for ( $g = 0; $g < $n; $g++ ) {
			$loca .= pack( 'N', strlen( $glyf ) );
			if ( isset( $keep[ $g ] ) ) {
				$data  = $this->glyph_data( $g );
				$glyf .= $data . str_repeat( "\0", ( 4 - strlen( $data ) % 4 ) % 4 );
			}
		}
		$loca .= pack( 'N', strlen( $glyf ) );

		$tables = array();
		foreach ( array( 'cvt ', 'fpgm', 'hhea', 'hmtx', 'maxp', 'prep', 'OS/2' ) as $tag ) {
			if ( isset( $this->tables[ $tag ] ) ) {
				$tables[ $tag ] = $this->table( $tag );
			}
		}
		$head = $this->table( 'head' );
		$head = substr_replace( $head, "\0\0\0\0", 8, 4 );    // checkSumAdjustment, recomputed by readers.
		$head = substr_replace( $head, pack( 'n', 1 ), 50, 2 ); // indexToLocFormat: long offsets.
		$tables['head'] = $head;
		$tables['loca'] = $loca;
		$tables['glyf'] = $glyf;
		ksort( $tables, SORT_STRING );

		return $this->write( $tables );
	}

	/**
	 * Glyph IDs referenced by a composite glyph.
	 *
	 * @param int $gid Glyph ID.
	 * @return int[]
	 */
	private function components( $gid ) {
		$data = $this->glyph_data( $gid );
		if ( strlen( $data ) < 10 || unpack( 'n', substr( $data, 0, 2 ) )[1] < 0x8000 ) {
			return array(); // Empty or simple glyph (numberOfContours >= 0).
		}
		$parts = array();
		$pos   = 10;
		do {
			if ( $pos + 4 > strlen( $data ) ) {
				break;
			}
			$flags   = unpack( 'n', substr( $data, $pos, 2 ) )[1];
			$parts[] = unpack( 'n', substr( $data, $pos + 2, 2 ) )[1];
			$pos    += 4 + ( $flags & 0x0001 ? 4 : 2 );
			if ( $flags & 0x0008 ) {
				$pos += 2;
			} elseif ( $flags & 0x0040 ) {
				$pos += 4;
			} elseif ( $flags & 0x0080 ) {
				$pos += 8;
			}
		} while ( $flags & 0x0020 );
		return $parts;
	}

	/**
	 * Outline data of one glyph.
	 *
	 * @param int $gid Glyph ID.
	 * @return string
	 */
	private function glyph_data( $gid ) {
		if ( $gid < 0 || $gid >= $this->metrics['num_glyphs'] ) {
			return '';
		}
		$loca = $this->tables['loca'][0];
		if ( $this->metrics['loca_long'] ) {
			$start = $this->u32( $loca + 4 * $gid );
			$end   = $this->u32( $loca + 4 * ( $gid + 1 ) );
		} else {
			$start = 2 * $this->u16( $loca + 2 * $gid );
			$end   = 2 * $this->u16( $loca + 2 * ( $gid + 1 ) );
		}
		return $end > $start ? substr( $this->data, $this->tables['glyf'][0] + $start, $end - $start ) : '';
	}

	/**
	 * Raw table bytes.
	 *
	 * @param string $tag Table tag.
	 * @return string
	 */
	private function table( $tag ) {
		list( $offset, $length ) = $this->tables[ $tag ];
		return substr( $this->data, $offset, $length );
	}

	/**
	 * Serialise tables into a TrueType file.
	 *
	 * @param array $tables Tag => data (sorted by tag).
	 * @return string
	 */
	private function write( array $tables ) {
		$count  = count( $tables );
		$power  = 1;
		$log    = 0;
		while ( $power * 2 <= $count ) {
			$power *= 2;
			++$log;
		}
		$out    = pack( 'Nnnnn', 0x00010000, $count, $power * 16, $log, $count * 16 - $power * 16 );
		$offset = 12 + 16 * $count;
		$body   = '';
		foreach ( $tables as $tag => $data ) {
			$padded = $data . str_repeat( "\0", ( 4 - strlen( $data ) % 4 ) % 4 );
			$out   .= $tag . pack( 'NNN', self::checksum( $padded ), $offset + strlen( $body ), strlen( $data ) );
			$body  .= $padded;
		}
		return $out . $body;
	}

	/**
	 * TrueType table checksum.
	 *
	 * @param string $data Data padded to 4 bytes.
	 * @return int
	 */
	private static function checksum( $data ) {
		$sum = 0;
		foreach ( unpack( 'N*', $data ) as $word ) {
			$sum = ( $sum + $word ) & 0xFFFFFFFF;
		}
		return $sum;
	}

	/**
	 * Read helpers (big-endian).
	 *
	 * @param int $pos Offset.
	 * @return int
	 */
	private function u16( $pos ) {
		return unpack( 'n', substr( $this->data, $pos, 2 ) )[1];
	}

	/**
	 * Signed 16-bit.
	 *
	 * @param int $pos Offset.
	 * @return int
	 */
	private function s16( $pos ) {
		$v = $this->u16( $pos );
		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}

	/**
	 * Unsigned 32-bit.
	 *
	 * @param int $pos Offset.
	 * @return int
	 */
	private function u32( $pos ) {
		return unpack( 'N', substr( $this->data, $pos, 4 ) )[1];
	}
}
