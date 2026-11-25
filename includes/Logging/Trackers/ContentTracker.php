<?php
namespace TBSHComplianceAuditLogger\Logging\Trackers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content changes not covered by status transitions:
 * edits to live content (log_content_events), comment moderation (log_comment_events)
 * and category/tag/custom term changes (log_taxonomy_events).
 *
 * Comment author names and emails are never logged; comments are referenced by ID.
 */
class ContentTracker extends Tracker {

	/**
	 * Statuses in which an edit changes what visitors (or private readers) see.
	 */
	const LIVE_STATUSES = array( 'publish', 'private', 'future' );

	/**
	 * Taxonomies handled elsewhere or internal to core.
	 */
	const EXCLUDED_TAXONOMIES = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' );

	/**
	 * Comment types that are not public discussion (block editor notes, WooCommerce order notes and webhook logs).
	 */
	const EXCLUDED_COMMENT_TYPES = array( 'note', 'order_note', 'webhook_delivery' );

	/**
	 * Posts already logged in this request.
	 *
	 * @var array
	 */
	private static $seen = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'track_post_edited' ), 10, 4 );

		add_action( 'transition_comment_status', array( __CLASS__, 'track_comment_status' ), 10, 3 );
		add_action( 'edit_comment', array( __CLASS__, 'track_comment_edited' ) );
		add_action( 'deleted_comment', array( __CLASS__, 'track_comment_deleted' ), 10, 2 );

		add_action( 'created_term', array( __CLASS__, 'track_term_created' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'track_term_updated' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'track_term_deleted' ), 10, 4 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Edits to live content
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Fields compared on update, with labels.
	 *
	 * @return array
	 */
	private static function post_fields() {
		return array(
			'post_title'     => __( 'title', 'tbsh-compliance-audit-logger' ),
			'post_content'   => __( 'content', 'tbsh-compliance-audit-logger' ),
			'post_excerpt'   => __( 'excerpt', 'tbsh-compliance-audit-logger' ),
			'post_name'      => __( 'slug', 'tbsh-compliance-audit-logger' ),
			'post_author'    => __( 'author', 'tbsh-compliance-audit-logger' ),
			'post_password'  => __( 'password', 'tbsh-compliance-audit-logger' ),
			'post_parent'    => __( 'parent', 'tbsh-compliance-audit-logger' ),
			'menu_order'     => __( 'order', 'tbsh-compliance-audit-logger' ),
			'comment_status' => __( 'comment status', 'tbsh-compliance-audit-logger' ),
		);
	}

	/**
	 * Published/private/scheduled content edited (status changes are logged by EventTracker).
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before the update.
	 */
	public static function track_post_edited( $post_id, $post, $update, $post_before = null ) {
		if ( ! $update || ! $post instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
			return;
		}
		if ( $post->post_status !== $post_before->post_status || ! in_array( $post->post_status, self::LIVE_STATUSES, true ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, get_post_types( array( 'public' => true ), 'names' ), true ) || ! self::enabled( 'log_content_events' ) ) {
			return;
		}

		$changed = array();
		foreach ( self::post_fields() as $field => $label ) {
			if ( (string) $post->$field !== (string) $post_before->$field ) {
				$changed[ $field ] = $label;
			}
		}
		if ( ! $changed || isset( self::$seen[ (int) $post_id ] ) ) {
			return;
		}
		self::$seen[ (int) $post_id ] = true;

		$metadata = array( 'fields' => array_keys( $changed ) );
		if ( isset( $changed['post_author'] ) ) {
			$metadata['author'] = array(
				'from' => (int) $post_before->post_author,
				'to'   => (int) $post->post_author,
			);
		}
		if ( isset( $changed['post_name'] ) ) {
			$metadata['slug'] = array(
				'from' => $post_before->post_name,
				'to'   => $post->post_name,
			);
		}

		self::write( self::entry(
			'post_updated',
			'Change Management',
			'info',
			__( 'Content edited', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: post title, 2: post type, 3: post ID, 4: comma-separated changed fields */
			sprintf( __( '"%1$s" (%2$s ID %3$d) was edited: %4$s.', 'tbsh-compliance-audit-logger' ), self::display( $post->post_title, 120 ), $post->post_type, (int) $post_id, implode( ', ', $changed ) ),
			array(
				'object_type' => $post->post_type,
				'object_id'   => (int) $post_id,
				'metadata'    => $metadata,
			)
		) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Comment moderation (logged-in users only; visitor submissions are not logged)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether a comment change should be logged.
	 *
	 * @param \WP_Comment|null $comment Comment.
	 * @return bool
	 */
	private static function comment_tracked( $comment ) {
		return $comment instanceof \WP_Comment
			&& ! in_array( $comment->comment_type, self::EXCLUDED_COMMENT_TYPES, true )
			&& get_current_user_id() > 0
			&& self::enabled( 'log_comment_events' );
	}

	/**
	 * Post title a comment belongs to.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return string
	 */
	private static function comment_post_title( $comment ) {
		$title = get_the_title( (int) $comment->comment_post_ID );
		return '' !== $title ? self::display( $title, 120 ) : '#' . (int) $comment->comment_post_ID;
	}

	/**
	 * Comment approved, unapproved, spammed or trashed.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 */
	public static function track_comment_status( $new_status, $old_status, $comment ) {
		// Permanent deletion is logged by track_comment_deleted().
		if ( 'delete' === $new_status || $new_status === $old_status || ! self::comment_tracked( $comment ) ) {
			return;
		}
		$new_status = sanitize_key( $new_status );
		$old_status = sanitize_key( $old_status );

		self::batch(
			'comment_status:' . $new_status,
			self::entry(
				'comment_status_changed',
				'Change Management',
				'info',
				__( 'Comment moderated', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: comment ID, 2: post title, 3: old status, 4: new status */
				sprintf( __( 'Comment #%1$d on "%2$s" changed from %3$s to %4$s.', 'tbsh-compliance-audit-logger' ), (int) $comment->comment_ID, self::comment_post_title( $comment ), $old_status, $new_status ),
				array(
					'object_type' => 'comment',
					'object_id'   => (int) $comment->comment_ID,
					'metadata'    => array(
						'post_id' => (int) $comment->comment_post_ID,
						'from'    => $old_status,
						'to'      => $new_status,
					),
				)
			),
			function ( $entries ) use ( $new_status ) {
				return self::entry(
					'comment_status_changed',
					'Change Management',
					'info',
					__( 'Comments moderated', 'tbsh-compliance-audit-logger' ),
					/* translators: 1: number of comments, 2: new status */
					sprintf( _n( '%1$d comment was changed to %2$s.', '%1$d comments were changed to %2$s.', count( $entries ), 'tbsh-compliance-audit-logger' ), count( $entries ), $new_status ),
					array(
						'object_type' => 'comment',
						'metadata'    => array(
							'count'       => count( $entries ),
							'to'          => $new_status,
							'comment_ids' => self::batch_ids( $entries ),
						),
					)
				);
			}
		);
	}

	/**
	 * Comment edited.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function track_comment_edited( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( ! self::comment_tracked( $comment ) ) {
			return;
		}

		self::batch(
			'comment_edited',
			self::entry(
				'comment_edited',
				'Change Management',
				'info',
				__( 'Comment edited', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: comment ID, 2: post title */
				sprintf( __( 'Comment #%1$d on "%2$s" was edited.', 'tbsh-compliance-audit-logger' ), (int) $comment_id, self::comment_post_title( $comment ) ),
				array(
					'object_type' => 'comment',
					'object_id'   => (int) $comment_id,
					'metadata'    => array( 'post_id' => (int) $comment->comment_post_ID ),
				)
			),
			function ( $entries ) {
				return self::entry(
					'comment_edited',
					'Change Management',
					'info',
					__( 'Comments edited', 'tbsh-compliance-audit-logger' ),
					/* translators: %d: number of comments */
					sprintf( _n( '%d comment was edited.', '%d comments were edited.', count( $entries ), 'tbsh-compliance-audit-logger' ), count( $entries ) ),
					array(
						'object_type' => 'comment',
						'metadata'    => array(
							'count'       => count( $entries ),
							'comment_ids' => self::batch_ids( $entries ),
						),
					)
				);
			}
		);
	}

	/**
	 * Comment permanently deleted.
	 *
	 * @param int         $comment_id Comment ID.
	 * @param \WP_Comment $comment    Comment.
	 */
	public static function track_comment_deleted( $comment_id, $comment = null ) {
		if ( ! self::comment_tracked( $comment ) ) {
			return;
		}

		self::batch(
			'comment_deleted',
			self::entry(
				'comment_deleted',
				'Change Management',
				'warning',
				__( 'Comment deleted', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: comment ID, 2: post title */
				sprintf( __( 'Comment #%1$d on "%2$s" was permanently deleted.', 'tbsh-compliance-audit-logger' ), (int) $comment_id, self::comment_post_title( $comment ) ),
				array(
					'object_type' => 'comment',
					'object_id'   => (int) $comment_id,
					'metadata'    => array( 'post_id' => (int) $comment->comment_post_ID ),
				)
			),
			function ( $entries ) {
				return self::entry(
					'comment_deleted',
					'Change Management',
					'warning',
					__( 'Comments deleted', 'tbsh-compliance-audit-logger' ),
					/* translators: %d: number of comments */
					sprintf( _n( '%d comment was permanently deleted.', '%d comments were permanently deleted.', count( $entries ), 'tbsh-compliance-audit-logger' ), count( $entries ) ),
					array(
						'object_type' => 'comment',
						'metadata'    => array(
							'count'       => count( $entries ),
							'comment_ids' => self::batch_ids( $entries ),
						),
					)
				);
			}
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Categories, tags and custom taxonomies
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether a taxonomy's term changes are logged.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function taxonomy_tracked( $taxonomy ) {
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || ! $object->show_ui || in_array( $taxonomy, self::EXCLUDED_TAXONOMIES, true ) ) {
			return false;
		}

		/**
		 * Filter whether term changes in a taxonomy are logged.
		 *
		 * @param bool   $tracked  Whether to log.
		 * @param string $taxonomy Taxonomy name.
		 */
		return (bool) apply_filters( 'tbsh_cal_track_taxonomy', true, $taxonomy ) && self::enabled( 'log_taxonomy_events' );
	}

	/**
	 * Term created.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function track_term_created( $term_id, $tt_id, $taxonomy ) {
		self::term_event( 'term_created', 'info', $term_id, $taxonomy );
	}

	/**
	 * Term updated.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function track_term_updated( $term_id, $tt_id, $taxonomy ) {
		self::term_event( 'term_updated', 'info', $term_id, $taxonomy );
	}

	/**
	 * Term deleted.
	 *
	 * @param int      $term_id      Term ID.
	 * @param int      $tt_id        Term taxonomy ID.
	 * @param string   $taxonomy     Taxonomy.
	 * @param \WP_Term $deleted_term Deleted term.
	 */
	public static function track_term_deleted( $term_id, $tt_id, $taxonomy, $deleted_term = null ) {
		self::term_event( 'term_deleted', 'warning', $term_id, $taxonomy, $deleted_term );
	}

	/**
	 * Log (batched) a term event.
	 *
	 * @param string        $type     Event type.
	 * @param string        $severity Severity.
	 * @param int           $term_id  Term ID.
	 * @param string        $taxonomy Taxonomy.
	 * @param \WP_Term|null $term     Term when already deleted.
	 */
	private static function term_event( $type, $severity, $term_id, $taxonomy, $term = null ) {
		if ( ! self::taxonomy_tracked( $taxonomy ) ) {
			return;
		}
		if ( ! $term instanceof \WP_Term ) {
			$term = get_term( (int) $term_id, $taxonomy );
		}
		$name   = $term instanceof \WP_Term ? self::display( $term->name, 120 ) : '#' . (int) $term_id;
		$labels = get_taxonomy( $taxonomy )->labels;

		switch ( $type ) {
			case 'term_created':
				$title = __( 'Term created', 'tbsh-compliance-audit-logger' );
				/* translators: 1: taxonomy singular name (e.g. Category), 2: term name */
				$format = __( '%1$s "%2$s" was created.', 'tbsh-compliance-audit-logger' );
				break;
			case 'term_updated':
				$title = __( 'Term updated', 'tbsh-compliance-audit-logger' );
				/* translators: 1: taxonomy singular name (e.g. Category), 2: term name */
				$format = __( '%1$s "%2$s" was updated.', 'tbsh-compliance-audit-logger' );
				break;
			default:
				$title = __( 'Term deleted', 'tbsh-compliance-audit-logger' );
				/* translators: 1: taxonomy singular name (e.g. Category), 2: term name */
				$format = __( '%1$s "%2$s" was deleted.', 'tbsh-compliance-audit-logger' );
				break;
		}

		self::batch(
			$type . ':' . $taxonomy,
			self::entry(
				$type,
				'Change Management',
				$severity,
				$title,
				sprintf( $format, $labels->singular_name, $name ),
				array(
					'object_type' => $taxonomy,
					'object_id'   => (int) $term_id,
					'metadata'    => array( 'name' => $name ),
				)
			),
			function ( $entries ) use ( $type, $severity, $title, $labels, $taxonomy ) {
				return self::entry(
					$type,
					'Change Management',
					$severity,
					$title,
					self::term_summary( $type, count( $entries ), $labels->name ),
					array(
						'object_type' => $taxonomy,
						'metadata'    => array(
							'count'    => count( $entries ),
							'term_ids' => self::batch_ids( $entries ),
						),
					)
				);
			}
		);
	}

	/**
	 * Summary message for a batch of term events.
	 *
	 * @param string $type  Event type.
	 * @param int    $count Number of terms.
	 * @param string $label Taxonomy plural name.
	 * @return string
	 */
	private static function term_summary( $type, $count, $label ) {
		switch ( $type ) {
			case 'term_created':
				/* translators: 1: number of terms, 2: taxonomy plural name (e.g. Categories) */
				return sprintf( _n( '%1$d term in %2$s was created.', '%1$d terms in %2$s were created.', $count, 'tbsh-compliance-audit-logger' ), $count, $label );
			case 'term_updated':
				/* translators: 1: number of terms, 2: taxonomy plural name (e.g. Categories) */
				return sprintf( _n( '%1$d term in %2$s was updated.', '%1$d terms in %2$s were updated.', $count, 'tbsh-compliance-audit-logger' ), $count, $label );
			default:
				/* translators: 1: number of terms, 2: taxonomy plural name (e.g. Categories) */
				return sprintf( _n( '%1$d term in %2$s was deleted.', '%1$d terms in %2$s were deleted.', $count, 'tbsh-compliance-audit-logger' ), $count, $label );
		}
	}
}
