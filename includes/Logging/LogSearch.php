<?php
namespace TBSHComplianceAuditLogger\Logging;

use TBSHComplianceAuditLogger\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text search over the audit log.
 *
 * A substring search (LIKE '%term%') has to read every entry, which takes
 * seconds on logs with millions of entries. When the full-text index exists
 * and the search consists of ordinary words, the index answers instead: it
 * matches whole words and word beginnings ("login" finds "login" and
 * "logins"). Callers fall back to the substring search when the index finds
 * nothing, so matches inside words are still found.
 */
class LogSearch {

	/**
	 * Columns searched (must equal the FULLTEXT index `search_text`, see Schema).
	 */
	const COLUMNS = array( 'event_title', 'event_message', 'username', 'object_type' );

	/**
	 * Words the full-text index ignores (InnoDB's default stopword list).
	 */
	const STOPWORDS = array( 'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www' );

	/**
	 * Transient caching whether the index exists.
	 */
	const INDEX_CACHE = 'tbsh_cal_fulltext_index';

	/**
	 * WHERE clause for a search.
	 *
	 * @param string $search Search text (already sanitised).
	 * @param bool   $exact  Force the substring search.
	 * @return array{0:string,1:array,2:string} SQL fragment, its arguments, and 'fulltext' or 'like'.
	 */
	public static function clause( $search, $exact = false ) {
		global $wpdb;
		$search = trim( (string) $search );

		$boolean = $exact ? '' : self::boolean_query( $search );
		if ( '' !== $boolean && self::index_available() ) {
			return array( 'MATCH(' . implode( ',', self::COLUMNS ) . ') AGAINST (%s IN BOOLEAN MODE)', array( $boolean ), 'fulltext' );
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$sql  = '(' . implode( ' OR ', array_map( function ( $c ) {
			return $c . ' LIKE %s';
		}, self::COLUMNS ) ) . ')';
		return array( $sql, array_fill( 0, count( self::COLUMNS ), $like ), 'like' );
	}

	/**
	 * Choose the faster search for these filters.
	 *
	 * The index costs time for every matching entry; the substring search costs
	 * time for every entry it reads until a page (and the capped count) is full.
	 * A word found in many of the newest entries is therefore faster with the
	 * substring search, a rarer one with the index. The newest 5,000 entries are
	 * sampled (a few milliseconds) to tell which.
	 *
	 * @param string $search Search text.
	 * @param array  $where  Other WHERE conditions (placeholders).
	 * @param array  $args   Their arguments.
	 * @return array{0:string,1:array,2:string} As clause().
	 */
	public static function choose( $search, array $where, array $args ) {
		$indexed = self::clause( $search );
		if ( 'fulltext' !== $indexed[2] ) {
			return $indexed;
		}
		global $wpdb;
		$table = Schema::table( 'logs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$range = $wpdb->get_row( $wpdb->prepare( 'SELECT MIN(id) AS lo, MAX(id) AS hi FROM %i', $table ) );
		$rows  = $range ? max( 1, (int) $range->hi - (int) $range->lo + 1 ) : 1;
		$exact = self::clause( $search, true );

		$sample = min( 5000, $rows );
		$probe  = implode( ' AND ', array_merge( $where, array( $exact[0], 'id > %d' ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $probe holds only placeholders.
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $probe", array_merge( array( $table ), $args, $exact[1], array( (int) $range->hi - $sample ) ) ) );

		// Break-even measured on MySQL 8: index cost ~7 µs per match, substring
		// search ~3.6 µs per entry read for a 10,000-entry count.
		return $hits / $sample > sqrt( 5000 / $rows ) ? $exact : $indexed;
	}

	/**
	 * Boolean-mode query requiring every word as a word or word beginning.
	 *
	 * @param string $search Search text.
	 * @return string Query, or '' when the index cannot answer this search exactly.
	 */
	public static function boolean_query( $search ) {
		$words = preg_split( '/\s+/u', trim( (string) $search ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words || count( $words ) > 8 ) {
			return '';
		}
		$parts = array();
		foreach ( $words as $word ) {
			// Only plain words: punctuation, symbols and very short or common words are
			// split or ignored by the index, so those searches use the substring search.
			if ( ! preg_match( '/^[\p{L}\p{N}]{3,}$/u', $word ) || in_array( strtolower( $word ), self::STOPWORDS, true ) ) {
				return '';
			}
			$parts[] = '+' . $word . '*';
		}
		return implode( ' ', $parts );
	}

	/**
	 * Whether the log table has the full-text index (cached for a day).
	 *
	 * @return bool
	 */
	public static function index_available() {
		$cached = get_transient( self::INDEX_CACHE );
		if ( 'yes' === $cached || 'no' === $cached ) {
			return 'yes' === $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', Schema::table( 'logs' ), 'search_text' ) );
		set_transient( self::INDEX_CACHE, $found ? 'yes' : 'no', DAY_IN_SECONDS );
		return $found;
	}
}
