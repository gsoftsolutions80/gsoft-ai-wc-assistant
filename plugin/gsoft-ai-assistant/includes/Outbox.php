<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Durable local queue (kind = event|identify) so shop requests never wait on the platform and
 * nothing is lost while it is down. Flushed by Action Scheduler.
 */
class Outbox {

	public const DB_VERSION = '1';

	private const GROUP        = 'gsoft-ai-assistant';
	private const MAX_ATTEMPTS = 8;
	private const BATCH        = 100;
	private const LOCK         = 'gsai_outbox_lock';
	private const RETENTION    = 7 * DAY_IN_SECONDS;

	private ApiClient $api;
	private Settings $settings;

	public function __construct( ApiClient $api, Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gsai_outbox';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(16) NOT NULL,
  payload longtext NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  available_at int(10) unsigned NOT NULL,
  created_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY kind_available (kind,available_at)
) {$charset};"
		);

		update_option( 'gsai_db_version', self::DB_VERSION, false );
	}

	public function register(): void {
		add_action( 'gsai_flush_outbox', array( $this, 'flush' ) );
		add_action( 'gsai_flush_outbox_cron', array( $this, 'flush' ) );
		add_action( 'admin_init', array( $this, 'ensure_schedules' ) );
	}

	public function enqueue( string $kind, array $payload ): void {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return;
		}

		global $wpdb;
		$now = time();
		$wpdb->insert(
			self::table(),
			array(
				'kind'         => $kind,
				'payload'      => $json,
				'attempts'     => 0,
				'available_at' => $now,
				'created_at'   => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);

		$this->schedule_flush( 10 );
	}

	public function ensure_schedules(): void {
		if ( ! $this->settings->is_configured() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'gsai_flush_outbox_cron', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, 5 * MINUTE_IN_SECONDS, 'gsai_flush_outbox_cron', array(), self::GROUP );
		}
	}

	public function flush(): void {
		if ( ! $this->settings->is_configured() || get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		try {
			$this->flush_identifies();
			for ( $i = 0; $i < 5; $i++ ) {
				if ( 0 === $this->flush_events() ) {
					break;
				}
			}
			$this->prune();
		} finally {
			delete_transient( self::LOCK );
		}

		$next = $this->next_available_at();
		if ( null !== $next ) {
			$this->schedule_flush( max( 60, $next - time() ) );
		}
	}

	private function flush_identifies(): void {
		foreach ( $this->due( 'identify', 50 ) as $row ) {
			$payload = json_decode( $row->payload, true );
			if ( ! is_array( $payload ) ) {
				$this->settle( array( $row ), 'drop' );
				continue;
			}
			$this->settle( array( $row ), $this->classify( $this->api->post( 'identify', $payload ) ) );
		}
	}

	/** @return int number of rows removed from the queue. */
	private function flush_events(): int {
		$rows   = $this->due( 'event', self::BATCH );
		$events = array();
		$valid  = array();

		foreach ( $rows as $row ) {
			$event = json_decode( $row->payload, true );
			if ( is_array( $event ) ) {
				$events[] = $event;
				$valid[]  = $row;
			} else {
				$this->settle( array( $row ), 'drop' );
			}
		}

		if ( ! $valid ) {
			return 0;
		}

		$result = $this->classify( $this->api->post( 'events', array( 'events' => $events ) ) );

		// One malformed event must not poison the whole batch: retry them one by one.
		if ( 'drop' === $result && count( $valid ) > 1 ) {
			foreach ( $valid as $i => $row ) {
				$this->settle( array( $row ), $this->classify( $this->api->post( 'events', array( 'events' => array( $events[ $i ] ) ) ) ) );
			}
			return count( $valid );
		}

		$this->settle( $valid, $result );
		return 'retry' === $result ? 0 : count( $valid );
	}

	/** @param array|\WP_Error $response */
	private function classify( $response ): string {
		if ( ApiClient::is_success( $response ) ) {
			return 'ok';
		}
		if ( is_wp_error( $response ) ) {
			return 'retry';
		}
		$code = $response['code'];
		return ( 429 === $code || 408 === $code || 401 === $code || $code >= 500 ) ? 'retry' : 'drop';
	}

	private function settle( array $rows, string $result ): void {
		global $wpdb;
		$table = self::table();

		if ( 'retry' !== $result ) {
			$ids = implode( ',', array_map( static fn( $r ) => (int) $r->id, $rows ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are cast to int.
			return;
		}

		foreach ( $rows as $row ) {
			$attempts = (int) $row->attempts + 1;
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
				continue;
			}
			$wpdb->update(
				$table,
				array(
					'attempts'     => $attempts,
					'available_at' => time() + min( HOUR_IN_SECONDS, 30 * ( 2 ** $attempts ) ),
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%d' ),
				array( '%d' )
			);
		}
	}

	/** @return object[] */
	private function due( string $kind, int $limit ): array {
		global $wpdb;
		$table = self::table();

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, payload, attempts FROM {$table} WHERE kind = %s AND available_at <= %d ORDER BY id ASC LIMIT %d", $kind, time(), $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function next_available_at(): ?int {
		global $wpdb;
		$table = self::table();
		$value = $wpdb->get_var( "SELECT MIN(available_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $value ? null : (int) $value;
	}

	private function prune(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", time() - self::RETENTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function schedule_flush( int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		if ( as_has_scheduled_action( 'gsai_flush_outbox', array(), self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + $delay, 'gsai_flush_outbox', array(), self::GROUP );
	}
}
