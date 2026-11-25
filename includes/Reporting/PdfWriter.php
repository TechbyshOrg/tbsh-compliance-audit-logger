<?php
namespace TBSHComplianceAuditLogger\Reporting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal, dependency-free PDF writer for compliance reports.
 *
 * Renders the same report data as the HTML/CSV renderers (meta + text, key/value and table
 * sections) onto A4 pages using the standard Helvetica fonts, with text wrapping, repeated table
 * headers, PASS/WARN/FAIL colouring and "Page X of Y" footers.
 *
 * The standard PDF fonts cover Western European text (Windows-1252). Characters outside it are
 * replaced, and the report then says so and points to the HTML version, which supports every script.
 */
class PdfWriter {

	const PAGE_W   = 595.28;
	const PAGE_H   = 841.89;
	const MARGIN   = 42;
	const FOOTER_H = 28;

	/**
	 * Helvetica glyph widths (1/1000 em) for ASCII 32–126; other characters use 556.
	 */
	const WIDTHS = array(
		278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
	);

	/**
	 * Finished pages (content streams).
	 *
	 * @var string[]
	 */
	private $pages = array();

	/**
	 * Current page content.
	 *
	 * @var string
	 */
	private $out = '';

	/**
	 * Current vertical position (from the bottom, PDF coordinates).
	 *
	 * @var float
	 */
	private $y = 0;

	/**
	 * Whether a character could not be represented.
	 *
	 * @var bool
	 */
	private $lossy = false;

	/**
	 * Embedded Unicode font, or null for the standard Helvetica fonts.
	 *
	 * @var TrueTypeFont|null
	 */
	private $font = null;

	/**
	 * Bytes per encoded character: 1 (Windows-1252) or 2 (glyph IDs, Identity-H).
	 *
	 * @var int
	 */
	private $unit = 1;

	/**
	 * Glyphs used with the embedded font: glyph ID => code point (for the ToUnicode map).
	 *
	 * @var array
	 */
	private $used = array();

	/**
	 * Encoded space.
	 *
	 * @var string
	 */
	private $space = ' ';

	/**
	 * Constructor.
	 *
	 * @param TrueTypeFont|null $font Unicode font to embed, or null for Helvetica.
	 */
	public function __construct( $font = null ) {
		if ( $font instanceof TrueTypeFont ) {
			$this->font = $font;
			$this->unit = 2;
		}
		$this->space = $this->encode( ' ' );
	}

	/**
	 * Render report data as a PDF document.
	 *
	 * Text that fits the standard fonts gives the same small file as before. When
	 * characters would be lost (for example Greek, Cyrillic, Polish or Vietnamese),
	 * the report is rendered again with an embedded subset of a Unicode font.
	 *
	 * @param array $data Report data (meta, sections).
	 * @return string PDF bytes.
	 */
	public static function render( array $data ) {
		$writer = new self();
		$pdf    = $writer->document( $data );
		if ( $writer->lossy ) {
			$font = self::unicode_font();
			if ( $font ) {
				$pdf = ( new self( $font ) )->document( $data );
			}
		}
		return $pdf;
	}

	/**
	 * The Unicode font for reports (DejaVu Sans, bundled), loaded once per request.
	 *
	 * @return TrueTypeFont|null
	 */
	private static function unicode_font() {
		static $font = false;
		if ( false === $font ) {
			/**
			 * Path of the TrueType font embedded in PDF reports that need characters
			 * outside Western European languages (for example a CJK TrueType font).
			 *
			 * @param string $path Font file (.ttf with TrueType outlines).
			 */
			$path = (string) apply_filters( 'tbsh_cal_pdf_font', TBSH_CAL_PATH . 'assets/fonts/DejaVuSans.ttf' );
			$font = TrueTypeFont::load( $path );
		}
		return $font;
	}

	/**
	 * Build the document.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private function document( array $data ) {
		$meta = $data['meta'];
		$this->new_page();

		// Title block.
		$this->paragraph( (string) $meta['title'], 18, true, 6 );
		$rows = array(
			array( __( 'Organisation', 'tbsh-compliance-audit-logger' ), (string) $meta['organisation'] ),
			array( __( 'Website', 'tbsh-compliance-audit-logger' ), (string) ( $meta['site'] ?? '' ) ),
			array( __( 'Reporting period', 'tbsh-compliance-audit-logger' ), $meta['period'][0] . ' – ' . $meta['period'][1] ),
			array( __( 'Generated', 'tbsh-compliance-audit-logger' ), $meta['generated_at'] . ( ! empty( $meta['generated_by'] ) ? ' · ' . $meta['generated_by'] : '' ) ),
		);
		$this->key_values( $rows, 9 );
		$this->rule( 1.5, array( 0.145, 0.388, 0.922 ) );
		$this->y -= 10;

		foreach ( $data['sections'] as $section ) {
			$this->heading( (string) $section['title'] );
			if ( 'text' === $section['kind'] ) {
				$this->paragraph( (string) $section['text'], 9, false, 4 );
			} elseif ( 'kv' === $section['kind'] ) {
				$pairs = array();
				foreach ( $section['data'] as $k => $v ) {
					$pairs[] = array( ucwords( str_replace( '_', ' ', (string) $k ) ), self::scalar( $v ) );
				}
				$this->key_values( $pairs, 8.5 );
			} elseif ( empty( $section['rows'] ) ) {
				$this->paragraph( __( 'No records.', 'tbsh-compliance-audit-logger' ), 9, false, 4, array( 0.28, 0.33, 0.41 ) );
			} else {
				$columns = array_map( function ( $c ) {
					return ucwords( str_replace( '_', ' ', (string) $c ) );
				}, $section['columns'] );
				$rows    = array_map( function ( $row ) {
					return array_map( array( __CLASS__, 'scalar' ), array_values( (array) $row ) );
				}, $section['rows'] );
				$this->table( $columns, $rows );
			}
			$this->y -= 6;
		}

		$note = sprintf(
			/* translators: 1: chain head hash, 2: plugin name/version */
			__( 'Integrity: this file is recorded with a SHA-256 content hash and an HMAC-SHA256 signature computed with a secret key held by the issuing website. Use “Verify” in Compliance Reports to confirm the file is unchanged. At generation time the head of the tamper-evident audit-log chain was %1$s. Generated by %2$s.', 'tbsh-compliance-audit-logger' ),
			$meta['chain_head'] ? $meta['chain_head'] : '—',
			$meta['plugin'] ?? ''
		);
		$this->y -= 6;
		$this->paragraph( $note, 7.5, false, 4, array( 0.28, 0.33, 0.41 ) );
		if ( $this->lossy ) {
			$this->paragraph( __( 'Some characters cannot be shown in PDF and were replaced. Download the HTML version for the complete text.', 'tbsh-compliance-audit-logger' ), 7.5, true, 4, array( 0.71, 0.33, 0.04 ) );
		}

		$this->pages[] = $this->out;
		return $this->assemble( (string) $meta['title'], (string) $meta['organisation'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Layout
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Start a page.
	 */
	private function new_page() {
		if ( '' !== $this->out ) {
			$this->pages[] = $this->out;
		}
		$this->out = '';
		$this->y   = self::PAGE_H - self::MARGIN;
	}

	/**
	 * Start a new page when less than $height remains.
	 *
	 * @param float $height Needed height.
	 * @return bool Whether a page was added.
	 */
	private function ensure( $height ) {
		if ( $this->y - $height < self::MARGIN + self::FOOTER_H ) {
			$this->new_page();
			return true;
		}
		return false;
	}

	/**
	 * Usable width.
	 *
	 * @return float
	 */
	private function width() {
		return self::PAGE_W - 2 * self::MARGIN;
	}

	/**
	 * Section heading with a rule below it.
	 *
	 * @param string $text Heading.
	 */
	private function heading( $text ) {
		$this->ensure( 40 );
		$this->y -= 8;
		$this->paragraph( $text, 12, true, 2 );
		$this->rule( 0.5, array( 0.886, 0.91, 0.941 ) );
		$this->y -= 6;
	}

	/**
	 * Wrapped paragraph.
	 *
	 * @param string $text   Text.
	 * @param float  $size   Font size.
	 * @param bool   $bold   Bold.
	 * @param float  $after  Space after.
	 * @param array  $color  RGB 0–1.
	 */
	private function paragraph( $text, $size, $bold = false, $after = 4, $color = array( 0.059, 0.09, 0.165 ) ) {
		$lines = $this->wrap( $text, $this->width(), $size, $bold );
		$lh    = $size * 1.35;
		foreach ( $lines as $line ) {
			$this->ensure( $lh );
			$this->y -= $lh;
			$this->text( self::MARGIN, $this->y + $size * 0.3, $line, $size, $bold, $color );
		}
		$this->y -= $after;
	}

	/**
	 * Horizontal rule across the content width.
	 *
	 * @param float $thickness Line width.
	 * @param array $color     RGB.
	 */
	private function rule( $thickness, array $color ) {
		$this->out .= sprintf( "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $color[0], $color[1], $color[2], $thickness, self::MARGIN, $this->y, self::PAGE_W - self::MARGIN, $this->y );
	}

	/**
	 * Two-column key/value list.
	 *
	 * @param array $pairs Pairs of strings.
	 * @param float $size  Font size.
	 */
	private function key_values( array $pairs, $size ) {
		$this->table( null, $pairs, $size, array( 0.3, 0.7 ) );
	}

	/**
	 * Table with wrapped cells; the header row repeats on each new page.
	 *
	 * @param array|null $columns Header labels, or null for a key/value list (first column bold).
	 * @param array      $rows    Rows of strings.
	 * @param float      $size    Font size.
	 * @param array|null $ratios  Column width ratios, or null to size by content.
	 */
	private function table( $columns, array $rows, $size = 8, $ratios = null ) {
		$count = $columns ? count( $columns ) : 2;
		foreach ( $rows as $i => $row ) {
			$rows[ $i ] = array_slice( array_pad( $row, $count, '' ), 0, $count );
		}
		$widths = $this->column_widths( $columns, $rows, $size, $ratios );
		$pad    = 3;
		$lh     = $size * 1.3;

		$draw_header = function () use ( $columns, $widths, $size, $pad, $lh ) {
			if ( ! $columns ) {
				return;
			}
			$cells  = $this->wrap_row( $columns, $widths, $size, true, $pad );
			$height = $this->row_height( $cells, $lh, $pad );
			$this->out .= sprintf( "0.973 0.980 0.988 rg %.2f %.2f %.2f %.2f re f\n", self::MARGIN, $this->y - $height, $this->width(), $height );
			$this->draw_row( $cells, $widths, $size, true, $pad, $lh, $height );
		};

		$this->ensure( 3 * $lh );
		$draw_header();
		foreach ( $rows as $row ) {
			$cells  = $this->wrap_row( $row, $widths, $size, ! $columns, $pad );
			$height = $this->row_height( $cells, $lh, $pad );
			if ( $this->ensure( $height ) ) {
				$draw_header();
			}
			$this->draw_row( $cells, $widths, $size, ! $columns, $pad, $lh, $height, $row );
		}
	}

	/**
	 * Column widths from ratios or content.
	 *
	 * @param array|null $columns Header.
	 * @param array      $rows    Rows.
	 * @param float      $size    Font size.
	 * @param array|null $ratios  Ratios.
	 * @return float[]
	 */
	private function column_widths( $columns, array $rows, $size, $ratios ) {
		$total = $this->width();
		if ( $ratios ) {
			return array_map( function ( $r ) use ( $total ) {
				return $total * $r;
			}, $ratios );
		}
		$count   = count( $columns );
		$desired = array();
		for ( $c = 0; $c < $count; $c++ ) {
			$max = $this->measure( (string) $columns[ $c ], $size, true );
			foreach ( array_slice( $rows, 0, 60 ) as $row ) {
				$max = max( $max, $this->measure( (string) $row[ $c ], $size ) );
			}
			$desired[ $c ] = min( max( $max + 8, 36 ), 260 );
		}
		$sum = array_sum( $desired );
		return array_map( function ( $d ) use ( $sum, $total ) {
			return $d / $sum * $total;
		}, $desired );
	}

	/**
	 * Wrap every cell of a row.
	 *
	 * @param array   $row    Cells.
	 * @param float[] $widths Widths.
	 * @param float   $size   Font size.
	 * @param bool    $bold   Bold (applies to the first cell only for key/value rows).
	 * @param float   $pad    Padding.
	 * @return array Lines per cell.
	 */
	private function wrap_row( array $row, array $widths, $size, $bold, $pad ) {
		$cells = array();
		foreach ( array_values( $row ) as $i => $cell ) {
			$cells[] = $this->wrap( (string) $cell, $widths[ $i ] - 2 * $pad, $size, $bold || self::status( $cell ) );
		}
		return $cells;
	}

	/**
	 * Row height.
	 *
	 * @param array $cells Wrapped cells.
	 * @param float $lh    Line height.
	 * @param float $pad   Padding.
	 * @return float
	 */
	private function row_height( array $cells, $lh, $pad ) {
		$lines = 1;
		foreach ( $cells as $cell ) {
			$lines = max( $lines, count( $cell ) );
		}
		return $lines * $lh + 2 * $pad;
	}

	/**
	 * Draw a row at the current position and move below it.
	 *
	 * @param array      $cells  Wrapped cells.
	 * @param float[]    $widths Widths.
	 * @param float      $size   Font size.
	 * @param bool       $bold   Header row or key/value list.
	 * @param float      $pad    Padding.
	 * @param float      $lh     Line height.
	 * @param float      $height Row height.
	 * @param array|null $raw    Raw cell values (status colouring).
	 */
	private function draw_row( array $cells, array $widths, $size, $bold, $pad, $lh, $height, $raw = null ) {
		$x = self::MARGIN;
		foreach ( $cells as $i => $lines ) {
			$status = $raw ? self::status( $raw[ $i ] ?? '' ) : '';
			$color  = array( 0.059, 0.09, 0.165 );
			if ( 'PASS' === $status ) {
				$color = array( 0.016, 0.471, 0.341 );
			} elseif ( 'WARN' === $status ) {
				$color = array( 0.706, 0.325, 0.035 );
			} elseif ( 'FAIL' === $status ) {
				$color = array( 0.725, 0.11, 0.11 );
			}
			$cell_bold = $bold && ( null === $raw || 0 === $i ) || '' !== $status;
			$ty        = $this->y - $pad;
			foreach ( $lines as $line ) {
				$ty -= $lh;
				$this->text( $x + $pad, $ty + $size * 0.3, $line, $size, $cell_bold, $color );
			}
			$x += $widths[ $i ];
		}
		$this->y -= $height;
		$this->out .= sprintf( "0.886 0.910 0.941 RG 0.5 w %.2f %.2f m %.2f %.2f l S\n", self::MARGIN, $this->y, self::PAGE_W - self::MARGIN, $this->y );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Text
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Draw one line of text.
	 *
	 * @param float  $x     X.
	 * @param float  $y     Baseline Y.
	 * @param string $text  Encoded text (Windows-1252).
	 * @param float  $size  Size.
	 * @param bool   $bold  Bold.
	 * @param array  $color RGB.
	 */
	private function text( $x, $y, $text, $size, $bold, array $color ) {
		if ( $bold && $this->font ) {
			// One embedded font: bold is drawn as filled and outlined text.
			$this->out .= sprintf( "q %.3f %.3f %.3f rg %.3f %.3f %.3f RG %.2f w BT 2 Tr /F1 %.2f Tf %.2f %.2f Td %s Tj ET Q\n", $color[0], $color[1], $color[2], $color[0], $color[1], $color[2], $size * 0.035, $size, $x, $y, $this->pdf_text( $text ) );
			return;
		}
		$this->out .= sprintf( "BT %.3f %.3f %.3f rg /%s %.2f Tf %.2f %.2f Td %s Tj ET\n", $color[0], $color[1], $color[2], $bold ? 'F2' : 'F1', $size, $x, $y, $this->pdf_text( $text ) );
	}

	/**
	 * Wrap text to a width; returns Windows-1252 encoded lines.
	 *
	 * @param string $text  UTF-8 text.
	 * @param float  $width Width in points.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Bold.
	 * @return string[]
	 */
	private function wrap( $text, $width, $size, $bold = false ) {
		$lines = array();
		foreach ( preg_split( "/\r\n|\n|\r/", str_replace( "\t", '    ', (string) $text ) ) as $para ) {
			$line = '';
			foreach ( preg_split( '/ +/', $para ) as $raw ) {
				$word      = $this->encode( $raw );
				$candidate = '' === $line ? $word : $line . $this->space . $word;
				if ( $this->measure_encoded( $candidate, $size, $bold ) <= $width ) {
					$line = $candidate;
					continue;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
				}
				// Break words longer than the cell (hashes, URLs).
				while ( $this->measure_encoded( $word, $size, $bold ) > $width && $this->length( $word ) > 1 ) {
					$cut = $this->length( $word );
					while ( $cut > 1 && $this->measure_encoded( $this->part( $word, 0, $cut ), $size, $bold ) > $width ) {
						--$cut;
					}
					$lines[] = $this->part( $word, 0, $cut );
					$word    = $this->part( $word, $cut );
				}
				$line = $word;
			}
			$lines[] = $line;
		}
		return $lines ? $lines : array( '' );
	}

	/**
	 * Number of characters in encoded text.
	 *
	 * @param string $text Encoded text.
	 * @return int
	 */
	private function length( $text ) {
		return intdiv( strlen( $text ), $this->unit );
	}

	/**
	 * Characters of encoded text.
	 *
	 * @param string   $text   Encoded text.
	 * @param int      $start  First character.
	 * @param int|null $length Number of characters, or null for the rest.
	 * @return string
	 */
	private function part( $text, $start, $length = null ) {
		return null === $length ? (string) substr( $text, $start * $this->unit ) : (string) substr( $text, $start * $this->unit, $length * $this->unit );
	}

	/**
	 * Width of UTF-8 text.
	 *
	 * @param string $text Text.
	 * @param float  $size Size.
	 * @param bool   $bold Bold.
	 * @return float
	 */
	private function measure( $text, $size, $bold = false ) {
		return $this->measure_encoded( $this->encode( $text ), $size, $bold );
	}

	/**
	 * Width of encoded text. Bold is measured 8% wider (Helvetica-Bold is slightly wider).
	 *
	 * @param string $text Encoded text.
	 * @param float  $size Size.
	 * @param bool   $bold Bold.
	 * @return float
	 */
	private function measure_encoded( $text, $size, $bold = false ) {
		$units = 0;
		if ( $this->font ) {
			foreach ( '' === $text ? array() : unpack( 'n*', $text ) as $gid ) {
				$units += $this->font->width( $gid );
			}
			return $units * $size / 1000 * ( $bold ? 1.04 : 1 );
		}
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$code   = ord( $text[ $i ] );
			$units += ( $code >= 32 && $code <= 126 ) ? self::WIDTHS[ $code - 32 ] : 556;
		}
		return $units * $size / 1000 * ( $bold ? 1.08 : 1 );
	}

	/**
	 * UTF-8 to Windows-1252 (the encoding of the standard fonts); unsupported characters become "?".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function encode( $text ) {
		$text = str_replace( "\t", '    ', (string) $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		if ( $this->font ) {
			return $this->encode_glyphs( $text );
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
			if ( mb_convert_encoding( $converted, 'UTF-8', 'Windows-1252' ) !== $text ) {
				$this->lossy = true;
			}
			return $converted;
		}
		$ascii = preg_replace( '/[^\x20-\x7E\n]/u', '?', $text );
		if ( $ascii !== $text ) {
			$this->lossy = true;
		}
		return $ascii;
	}

	/**
	 * UTF-8 to two-byte glyph IDs of the embedded font.
	 *
	 * Characters the font lacks, and scripts that need shaping or right-to-left
	 * layout (which this writer does not do), become "?" and mark the PDF lossy.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	private function encode_glyphs( $text ) {
		if ( class_exists( '\Normalizer' ) ) {
			$normal = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			$text   = false === $normal ? $text : $normal;
		}
		$out = '';
		foreach ( (array) preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$cp  = self::code_point( "\n" === $char || "\r" === $char ? ' ' : $char );
			$gid = self::needs_shaping( $cp ) ? 0 : $this->font->glyph( $cp );
			if ( ! $gid ) {
				$this->lossy = true;
				$cp          = 63; // "?"
				$gid         = $this->font->glyph( 63 );
			}
			$this->used[ $gid ] = $cp;
			$out               .= pack( 'n', $gid );
		}
		return $out;
	}

	/**
	 * Code point of one UTF-8 character.
	 *
	 * @param string $char Character.
	 * @return int
	 */
	private static function code_point( $char ) {
		$b = array_values( (array) unpack( 'C*', $char ) );
		switch ( count( $b ) ) {
			case 1:
				return $b[0];
			case 2:
				return ( ( $b[0] & 0x1F ) << 6 ) | ( $b[1] & 0x3F );
			case 3:
				return ( ( $b[0] & 0x0F ) << 12 ) | ( ( $b[1] & 0x3F ) << 6 ) | ( $b[2] & 0x3F );
			case 4:
				return ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
		}
		return 63;
	}

	/**
	 * Whether a code point belongs to a script that needs shaping or right-to-left
	 * layout (Hebrew, Arabic, Syriac, Indic, Thai, Lao, Tibetan, Myanmar, Khmer…).
	 *
	 * @param int $cp Code point.
	 * @return bool
	 */
	private static function needs_shaping( $cp ) {
		$ranges = array( array( 0x0590, 0x08FF ), array( 0x0900, 0x0DFF ), array( 0x0E00, 0x0FFF ), array( 0x1000, 0x109F ), array( 0x1780, 0x18AF ), array( 0xA8E0, 0xA8FF ), array( 0xFB1D, 0xFDFF ), array( 0xFE70, 0xFEFF ) );
		foreach ( $ranges as $r ) {
			if ( $cp >= $r[0] && $cp <= $r[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Encoded text as a PDF string operand.
	 *
	 * @param string $text Encoded text.
	 * @return string
	 */
	private function pdf_text( $text ) {
		return $this->font ? '<' . strtoupper( bin2hex( $text ) ) . '>' : '(' . self::escape( $text ) . ')';
	}

	/**
	 * UTF-8 as a UTF-16BE hex string with byte-order mark (for document information).
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	private static function utf16_hex( $text ) {
		$out = 'FEFF';
		foreach ( (array) preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$cp = self::code_point( $char );
			if ( $cp > 0xFFFF ) {
				$cp  -= 0x10000;
				$out .= sprintf( '%04X%04X', 0xD800 | ( $cp >> 10 ), 0xDC00 | ( $cp & 0x3FF ) );
			} else {
				$out .= sprintf( '%04X', $cp );
			}
		}
		return '<' . $out . '>';
	}

	/**
	 * Escape a PDF literal string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function escape( $text ) {
		return strtr( $text, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => '' ) );
	}

	/**
	 * Cell value as text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function scalar( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'tbsh-compliance-audit-logger' ) : __( 'No', 'tbsh-compliance-audit-logger' );
		}
		return (string) $value;
	}

	/**
	 * PASS, WARN or FAIL when the cell is a status value (same rule as the HTML report).
	 *
	 * @param mixed $cell Cell.
	 * @return string
	 */
	private static function status( $cell ) {
		$letters = preg_replace( '/[^A-Z]/', '', (string) $cell );
		return in_array( $letters, array( 'PASS', 'WARN', 'FAIL' ), true ) && strlen( trim( (string) $cell ) ) <= 6 ? $letters : '';
	}

	/*
	 * ---------------------------------------------------------------------
	 * File structure
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Assemble objects, footers and the cross-reference table.
	 *
	 * @param string $title        Title.
	 * @param string $organisation Organisation.
	 * @return string
	 */
	private function assemble( $title, $organisation ) {
		$total   = count( $this->pages );
		$objects = array();
		$add     = function ( $body ) use ( &$objects ) {
			$objects[] = $body;
			return count( $objects );
		};

		$catalog = $add( '' ); // Filled in below.
		$tree    = $add( '' );
		$label   = $this->encode( $title . ( '' !== $organisation ? ' — ' . $organisation : '' ) );
		// Footers are encoded before the font is written, so every glyph is in the subset.
		$page_labels = array();
		foreach ( array_keys( $this->pages ) as $n ) {
			/* translators: 1: page number, 2: total pages */
			$page_labels[ $n ] = $this->encode( sprintf( __( 'Page %1$d of %2$d', 'tbsh-compliance-audit-logger' ), $n + 1, $total ) );
		}
		$this->encode( '...' );
		if ( $this->font ) {
			$regular = $this->embed_font( $add );
			$bold    = $regular;
		} else {
			$regular = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
			$bold    = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' );
		}

		$kids = array();
		foreach ( $this->pages as $n => $content ) {
			$page_label = $page_labels[ $n ];
			$footer     = sprintf( "0.886 0.910 0.941 RG 0.5 w %.2f %.2f m %.2f %.2f l S\n", self::MARGIN, self::MARGIN + 14, self::PAGE_W - self::MARGIN, self::MARGIN + 14 );
			$footer    .= sprintf( "BT 0.28 0.33 0.41 rg /F1 7.5 Tf %.2f %.2f Td %s Tj ET\n", self::MARGIN, self::MARGIN, $this->pdf_text( $this->fit( $label, $this->width() - 80, 7.5 ) ) );
			$footer    .= sprintf( "BT 0.28 0.33 0.41 rg /F1 7.5 Tf %.2f %.2f Td %s Tj ET\n", self::PAGE_W - self::MARGIN - $this->measure_encoded( $page_label, 7.5 ), self::MARGIN, $this->pdf_text( $page_label ) );
			$stream     = $content . $footer;
			$filter     = '';
			if ( function_exists( 'gzcompress' ) ) {
				$stream = gzcompress( $stream, 6 );
				$filter = ' /Filter /FlateDecode';
			}
			$contents = $add( sprintf( "<< /Length %d%s >>\nstream\n%s\nendstream", strlen( $stream ), $filter, $stream ) );
			$kids[]   = $add( sprintf( '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>', $tree, self::PAGE_W, self::PAGE_H, $regular, $bold, $contents ) );
		}

		$objects[ $catalog - 1 ] = sprintf( '<< /Type /Catalog /Pages %d 0 R >>', $tree );
		$objects[ $tree - 1 ]    = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', array_map( function ( $k ) {
			return $k . ' 0 R';
		}, $kids ) ), $total );
		$info_title = $this->font ? self::utf16_hex( $title ) : '(' . self::escape( $this->encode( $title ) ) . ')';
		$info       = $add( sprintf( '<< /Title %s /Producer (%s) /CreationDate (D:%s) >>', $info_title, self::escape( 'TBSH Compliance Audit Logger' ), gmdate( 'YmdHis' ) . 'Z' ) );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $body ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n" . '0 ' . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= sprintf( "trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", count( $objects ) + 1, $catalog, $info, $xref );
		return $pdf;
	}

	/**
	 * Write the embedded font objects (Type0 / CIDFontType2, Identity-H) and return the font object.
	 *
	 * @param callable $add Adds an object and returns its number.
	 * @return int
	 */
	private function embed_font( callable $add ) {
		$font = $this->font;
		$m    = $font->metrics;
		ksort( $this->used );
		$tag  = substr( strtoupper( preg_replace( '/[^a-z]/i', '', base64_encode( md5( implode( ',', array_keys( $this->used ) ), true ) ) ) ) . 'AAAAAA', 0, 6 );
		$name = $tag . '+' . $font->name;

		$file   = $font->subset( array_keys( $this->used ) );
		$length = strlen( $file );
		$filter = '';
		if ( function_exists( 'gzcompress' ) ) {
			$file   = gzcompress( $file, 6 );
			$filter = ' /Filter /FlateDecode';
		}
		$file_obj = $add( sprintf( "<< /Length %d /Length1 %d%s >>\nstream\n%s\nendstream", strlen( $file ), $length, $filter, $file ) );

		$descriptor = $add( sprintf(
			'<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
			$name,
			$m['bbox'][0],
			$m['bbox'][1],
			$m['bbox'][2],
			$m['bbox'][3],
			$m['ascent'],
			$m['descent'],
			$m['cap_height'],
			$file_obj
		) );

		$widths = '';
		foreach ( array_keys( $this->used ) as $gid ) {
			$widths .= sprintf( '%d [%d] ', $gid, round( $font->width( $gid ) ) );
		}
		$cid = $add( sprintf( '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 /W [%s] /CIDToGIDMap /Identity >>', $name, $descriptor, trim( $widths ) ) );

		// ToUnicode: lets readers copy, search and read the text aloud.
		$map = '';
		foreach ( array_chunk( $this->used, 100, true ) as $chunk ) {
			$map .= count( $chunk ) . " beginbfchar\n";
			foreach ( $chunk as $gid => $cp ) {
				// utf16_hex() returns "<FEFF…>"; the CMap takes the code units without the byte-order mark.
				$map .= sprintf( "<%04X> <%s>\n", $gid, substr( self::utf16_hex( self::utf8( $cp ) ), 5, -1 ) );
			}
			$map .= "endbfchar\n";
		}
		$cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $map . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
		$to_unicode = $add( sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $cmap ), $cmap ) );

		return $add( sprintf( '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $name, $cid, $to_unicode ) );
	}

	/**
	 * UTF-8 for a code point.
	 *
	 * @param int $cp Code point.
	 * @return string
	 */
	private static function utf8( $cp ) {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	/**
	 * Shorten encoded text to a width with an ellipsis.
	 *
	 * @param string $text  Encoded text.
	 * @param float  $width Width.
	 * @param float  $size  Size.
	 * @return string
	 */
	private function fit( $text, $width, $size ) {
		if ( $this->measure_encoded( $text, $size ) <= $width ) {
			return $text;
		}
		$dots = $this->encode( '...' );
		while ( $this->length( $text ) > 1 && $this->measure_encoded( $text . $dots, $size ) > $width ) {
			$text = $this->part( $text, 0, $this->length( $text ) - 1 );
		}
		return $text . $dots;
	}
}
