<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only research for designing Premium and Gold, split by gender
 * (owner, 2026-10-24: "a fully data backed design of premium and gold.
 * Separately for men and women based on which features they would want").
 *
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/plan-research.php > ~/plan-research.txt
 *
 * Prints aggregates only: no names, emails, phone numbers or message text.
 * Each section runs on its own, so one failure does not stop the rest.
 */
global $wpdb;
$p   = $wpdb->prefix;
$out = function ( $s = '' ) { echo $s, "\n"; };
$pct = function ( $a, $b ) { return $b ? round( 100 * $a / $b, 1 ) . '%' : '-'; };
$med = function ( array $v ) { if ( ! $v ) { return '-'; } sort( $v ); return $v[ (int) floor( ( count( $v ) - 1 ) / 2 ) ]; };
$has = function ( $t ) use ( $wpdb ) { return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); };

// Gender of every member (F / M), admins and closed accounts excluded.
$G = array();
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$p}bp_xprofile_data WHERE field_id = %d", \CAShaadi\Core\Config::FIELD_GENDER ) ) as $r ) {
	$G[ (int) $r->user_id ] = 'Female' === $r->value ? 'F' : ( 'Male' === $r->value ? 'M' : '' );
}
$skip = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
if ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) ) { $skip = array_merge( $skip, \CAShaadi\Modules\Settings\Closed::ids() ); }
foreach ( $skip as $id ) { unset( $G[ (int) $id ] ); }
$g = function ( $uid ) use ( $G ) { return $G[ (int) $uid ] ?? ''; };
$prem = array();
foreach ( array_keys( $G ) as $uid ) { $prem[ $uid ] = \CAShaadi\Core\Membership::is_premium( $uid ) ? 1 : 0; }
$active30 = $has( $p . 'csm_active_days' ) ? array_flip( array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT user_id FROM {$p}csm_active_days WHERE day >= CURDATE() - INTERVAL 30 DAY" ) ) ) : array();

$section = function ( $title, $fn ) use ( $out ) {
	$out( '' );
	$out( '=== ' . $title . ' ===' );
	try { $fn(); } catch ( \Throwable $e ) { $out( 'ERROR: ' . $e->getMessage() . ' line ' . $e->getLine() ); }
};

$out( 'CAShaadi plan research, ' . wp_date( 'j M Y H:i' ) . ' IST. Aggregates only.' );

/* 1. Base: who is here, who pays. */
$section( '1. MEMBERS AND PAYING (by gender)', function () use ( $G, $prem, $active30, $out, $pct ) {
	foreach ( array( 'M', 'F' ) as $x ) {
		$all = array_keys( array_filter( $G, function ( $v ) use ( $x ) { return $v === $x; } ) );
		$act = array_filter( $all, function ( $u ) use ( $active30 ) { return isset( $active30[ $u ] ); } );
		$pr  = array_filter( $all, function ( $u ) use ( $prem ) { return ! empty( $prem[ $u ] ); } );
		$apr = array_filter( $act, function ( $u ) use ( $prem ) { return ! empty( $prem[ $u ] ); } );
		$out( sprintf( '%s: members %d | active 30d %d (%s) | Premium now %d (%s of members, %s of active)', $x, count( $all ), count( $act ), $pct( count( $act ), count( $all ) ), count( $pr ), $pct( count( $pr ), count( $all ) ), $pct( count( $apr ), count( $act ) ) ) );
	}
} );

$section( '2. PURCHASES AND THE PATH TO THEM', function () use ( $wpdb, $p, $g, $out, $med ) {
	$rows = $wpdb->get_results( "SELECT actor_id, metadata, created_at FROM {$p}csm_event_log WHERE event_type = 'purchase'" );
	$by = array();
	foreach ( $rows as $r ) {
		$m = json_decode( (string) $r->metadata, true ) ?: array();
		$x = $g( $r->actor_id ) ?: ( 'Female' === ( $m['gender'] ?? '' ) ? 'F' : ( 'Male' === ( $m['gender'] ?? '' ) ? 'M' : '?' ) );
		$by[ $x ]['n']      = ( $by[ $x ]['n'] ?? 0 ) + 1;
		$by[ $x ]['amt'][]  = (float) ( $m['total'] ?? 0 );
		$by[ $x ]['days'][] = (int) ( $m['days_since_signup'] ?? 0 );
		$by[ $x ]['pv'][]   = (int) ( $m['pricing_views'] ?? 0 );
		$src = (string) ( $m['pricing_src'] ?? '' );
		$src = '' === $src ? 'unknown' : preg_replace( '/\?.*$/', '', $src );
		$by[ $x ]['src'][ $src ] = ( $by[ $x ]['src'][ $src ] ?? 0 ) + 1;
		$ch = (string) ( $m['channel'] ?? '' ) ?: 'unknown';
		$by[ $x ]['ch'][ $ch ] = ( $by[ $x ]['ch'][ $ch ] ?? 0 ) + 1;
	}
	$out( 'Purchases logged since the event existed (v1.73, early Oct): ' . count( $rows ) );
	foreach ( $by as $x => $d ) {
		arsort( $d['src'] ); arsort( $d['ch'] );
		$out( sprintf( '%s: %d purchases | median paid Rs %s | median days signup->purchase %s | median pricing views before %s', $x, $d['n'], $med( $d['amt'] ), $med( $d['days'] ), $med( $d['pv'] ) ) );
		$out( '   last pricing page source: ' . implode( ', ', array_map( function ( $k, $v ) { return "$k $v"; }, array_keys( array_slice( $d['src'], 0, 6, true ) ), array_slice( $d['src'], 0, 6, true ) ) ) );
		$out( '   channel: ' . implode( ', ', array_map( function ( $k, $v ) { return "$k $v"; }, array_keys( array_slice( $d['ch'], 0, 5, true ) ), array_slice( $d['ch'], 0, 5, true ) ) ) );
	}
	// Pricing page views -> purchase, by source.
	$pv = $wpdb->get_results( "SELECT actor_id, metadata FROM {$p}csm_event_log WHERE event_type = 'pricing_view'" );
	$bought = array_flip( array_map( 'intval', wp_list_pluck( $rows, 'actor_id' ) ) );
	$src = array();
	foreach ( $pv as $r ) {
		$m = json_decode( (string) $r->metadata, true ) ?: array();
		if ( ! empty( $m['premium'] ) ) { continue; }
		$x = $g( $r->actor_id ) ?: '?';
		$s = preg_replace( '/\?.*$/', '', (string) ( $m['src'] ?? '' ) ) ?: 'unknown';
		$src[ $x ][ $s ]['u'][ (int) $r->actor_id ] = 1;
	}
	$out( 'Free members who opened pricing, by where they came from (people -> bought):' );
	foreach ( $src as $x => $ss ) {
		uasort( $ss, function ( $a, $b ) { return count( $b['u'] ) <=> count( $a['u'] ); } );
		foreach ( array_slice( $ss, 0, 8, true ) as $s => $d ) {
			$b = count( array_intersect_key( $d['u'], $bought ) );
			$out( sprintf( '   %s  %-45s %4d people, %3d bought', $x, substr( $s, 0, 45 ), count( $d['u'] ), $b ) );
		}
	}
} );

/* 3. Appetite for more profiles: do people use up their week? */
$section( '3. WEEKLY PROFILES: USED UP OR NOT (last 4 complete weeks)', function () use ( $wpdb, $p, $g, $prem, $out, $pct, $med ) {
	$rows = $wpdb->get_results( "SELECT viewer_id, week_assigned w, COUNT(*) served, SUM(status IN ('liked','passed')) decided, SUM(status='liked') liked FROM {$p}csm_tray WHERE week_assigned <> '' AND assigned_at >= CURDATE() - INTERVAL 35 DAY GROUP BY viewer_id, week_assigned" );
	$b = array();
	foreach ( $rows as $r ) {
		$x = $g( $r->viewer_id ); if ( '' === $x ) { continue; }
		$k = $x . ( ! empty( $prem[ (int) $r->viewer_id ] ) ? ' premium' : ' free' );
		$b[ $k ]['weeks'] = ( $b[ $k ]['weeks'] ?? 0 ) + 1;
		$b[ $k ]['all']   = ( $b[ $k ]['all'] ?? 0 ) + ( (int) $r->decided >= (int) $r->served ? 1 : 0 );
		$b[ $k ]['served'][] = (int) $r->served;
		$b[ $k ]['lr'][]  = (int) $r->served ? (int) $r->liked / (int) $r->served : 0;
	}
	ksort( $b );
	foreach ( $b as $k => $d ) {
		$out( sprintf( '%-10s member-weeks %4d | decided every profile they got: %s | median profiles/week %s | median like share %s', $k, $d['weeks'], $pct( $d['all'], $d['weeks'] ), $med( $d['served'] ), round( 100 * $med( $d['lr'] ) ) . '%' ) );
	}
	$pe = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT actor_id) FROM {$p}csm_event_log WHERE event_type = 'pool_exhausted' AND created_at >= NOW() - INTERVAL 30 DAY" );
	$out( 'Members who ran out of NEW people to be shown (pool exhausted), last 30d: ' . $pe );
} );

/* 4. What people choose: like rate by attribute match -> which filters matter. */
$section( '4. WHAT MAKES A LIKE (impressions with a decision, by viewer gender)', function () use ( $wpdb, $p, $g, $out, $pct ) {
	$t = $p . 'csm_impressions';
	$rows = $wpdb->get_results( "SELECT i.viewer_id, i.viewer_snap v, i.profile_snap pr, s.action FROM {$t} i JOIN {$p}csm_seen s ON s.viewer_id = i.viewer_id AND s.profile_id = i.profile_id WHERE s.action IN ('liked','passed') AND i.id IN ( SELECT MAX(id) FROM {$t} GROUP BY viewer_id, profile_id )" );
	$out( 'Decided impressions analysed: ' . count( $rows ) );
	$st = array();
	$add = function ( $x, $dim, $val, $liked ) use ( &$st ) {
		$st[ $x ][ $dim ][ $val ]['n'] = ( $st[ $x ][ $dim ][ $val ]['n'] ?? 0 ) + 1;
		$st[ $x ][ $dim ][ $val ]['l'] = ( $st[ $x ][ $dim ][ $val ]['l'] ?? 0 ) + ( $liked ? 1 : 0 );
	};
	$same = function ( $a, $b ) { $a = strtolower( trim( (string) $a ) ); $b = strtolower( trim( (string) $b ) ); return ( '' === $a || '' === $b ) ? 'unknown' : ( $a === $b ? 'same' : 'different' ); };
	foreach ( $rows as $r ) {
		$x = $g( $r->viewer_id ); if ( '' === $x ) { continue; }
		$v = json_decode( (string) $r->v, true ) ?: array(); $q = json_decode( (string) $r->pr, true ) ?: array();
		$l = 'liked' === $r->action;
		$add( $x, 'ALL', 'all', $l );
		foreach ( array( 'city' => 'city', 'tongue' => 'mother tongue', 'comm' => 'community', 'rel' => 'religion', 'diet' => 'diet', 'qual' => 'qualification' ) as $k => $label ) {
			$add( $x, $label, $same( $v[ $k ] ?? '', $q[ $k ] ?? '' ), $l );
		}
		if ( isset( $v['age'], $q['age'] ) && $v['age'] && $q['age'] ) {
			$d = (int) $q['age'] - (int) $v['age'];
			$add( $x, 'their age minus mine', $d <= -4 ? '<= -4' : ( $d <= -1 ? '-3..-1' : ( 0 === $d ? '0' : ( $d <= 3 ? '+1..+3' : ( $d <= 6 ? '+4..+6' : '>= +7' ) ) ) ), $l );
		}
		if ( ! empty( $v['ht'] ) && ! empty( $q['ht'] ) ) {
			$d = (float) $q['ht'] - (float) $v['ht'];
			$add( $x, 'their height minus mine (cm)', $d < -10 ? '< -10' : ( $d < 0 ? '-10..0' : ( $d < 10 ? '0..+10' : ( $d < 20 ? '+10..+20' : '>= +20' ) ) ), $l );
		}
		$add( $x, 'their photo', ( (int) ( $q['photos'] ?? 0 ) ) > 0 ? ( ! empty( $q['priv'] ) ? 'blurred' : 'visible' ) : 'none', $l );
		$add( $x, 'their verification', 'approved' === ( $q['ver'] ?? '' ) ? 'verified' : 'not verified', $l );
		$add( $x, 'their qualification', '' !== (string) ( $q['qual'] ?? '' ) ? (string) $q['qual'] : 'unknown', $l );
		$add( $x, 'their income', '' !== (string) ( $q['inc'] ?? '' ) ? (string) $q['inc'] : 'unknown', $l );
		$add( $x, 'profile made by', '' !== (string) ( $q['for'] ?? '' ) ? (string) $q['for'] : 'unknown', $l );
		$add( $x, 'their details left', null === ( $q['left'] ?? null ) ? 'unknown' : ( (int) $q['left'] === 0 ? 'complete' : 'incomplete' ), $l );
	}
	foreach ( array( 'M' => 'MEN deciding on women', 'F' => 'WOMEN deciding on men' ) as $x => $lab ) {
		if ( empty( $st[ $x ] ) ) { continue; }
		$out( '-- ' . $lab . ' --' );
		foreach ( $st[ $x ] as $dim => $vals ) {
			uasort( $vals, function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
			$parts = array();
			foreach ( array_slice( $vals, 0, 8, true ) as $val => $d ) {
				if ( $d['n'] < 30 ) { continue; }
				$parts[] = $val . ' ' . $pct( $d['l'], $d['n'] ) . ' (n=' . $d['n'] . ')';
			}
			if ( $parts ) { $out( sprintf( '   %-28s %s', $dim, implode( ' | ', $parts ) ) ); }
		}
	}
} );

/* 5. Requests: who is flooded, who is waiting. */
$section( '5. REQUESTS (last 30 days, by gender of the person RECEIVING)', function () use ( $wpdb, $p, $g, $out, $pct, $med ) {
	$rows = $wpdb->get_results( "SELECT initiator_user_id a, friend_user_id b, is_confirmed c FROM {$p}bp_friends WHERE date_created >= UTC_TIMESTAMP() - INTERVAL 30 DAY" );
	$rej  = $has_rej = $wpdb->get_var( "SHOW TABLES LIKE '{$p}csm_rejections'" ) ? array_flip( array_map( function ( $r ) { return $r->rejecter_id . '-' . $r->rejected_id; }, $wpdb->get_results( "SELECT rejecter_id, rejected_id FROM {$p}csm_rejections" ) ) ) : array();
	$b = array(); $per = array();
	foreach ( $rows as $r ) {
		$x = $g( $r->b ); if ( '' === $x ) { continue; }
		$b[ $x ]['n'] = ( $b[ $x ]['n'] ?? 0 ) + 1;
		$b[ $x ]['acc'] = ( $b[ $x ]['acc'] ?? 0 ) + ( (int) $r->c ? 1 : 0 );
		$per[ $x ][ (int) $r->b ] = ( $per[ $x ][ (int) $r->b ] ?? 0 ) + 1;
	}
	$out( 'Declined (all time, logged): ' . count( $rej ) );
	foreach ( $b as $x => $d ) {
		$v = array_values( $per[ $x ] ); rsort( $v );
		$top = array_slice( $v, 0, max( 1, (int) ceil( count( $v ) / 10 ) ) );
		$out( sprintf( '%s received %d | accepted %s | receivers %d | median per receiver %s | top 10%% of receivers get %s of all requests', $x, $d['n'], $pct( $d['acc'], $d['n'] ), count( $v ), $med( $v ), $pct( array_sum( $top ), array_sum( $v ) ) ) );
	}
	$s = array();
	foreach ( $rows as $r ) {
		$x = $g( $r->a ); if ( '' === $x ) { continue; }
		$s[ $x ][ (int) $r->a ]['n'] = ( $s[ $x ][ (int) $r->a ]['n'] ?? 0 ) + 1;
		$s[ $x ][ (int) $r->a ]['a'] = ( $s[ $x ][ (int) $r->a ]['a'] ?? 0 ) + ( (int) $r->c ? 1 : 0 );
	}
	foreach ( $s as $x => $senders ) {
		$zero = count( array_filter( $senders, function ( $d ) { return 0 === $d['a']; } ) );
		$out( sprintf( '%s senders %d | sent 5+ requests with 0 accepted: %d | sent any and got 0 accepted: %s', $x, count( $senders ), count( array_filter( $senders, function ( $d ) { return $d['n'] >= 5 && 0 === $d['a']; } ) ), $pct( $zero, count( $senders ) ) ) );
	}
} );

/* 6. Who viewed me: is there enough to show? */
$section( '6. PROFILE VIEWS RECEIVED (last 30 days)', function () use ( $wpdb, $p, $g, $out, $pct, $med, $active30 ) {
	$rows = $wpdb->get_results( "SELECT viewed_id u, COUNT(*) n FROM {$p}csm_profile_views WHERE first_at >= NOW() - INTERVAL 30 DAY GROUP BY viewed_id" );
	$b = array();
	foreach ( $rows as $r ) { $x = $g( $r->u ); if ( '' !== $x ) { $b[ $x ][] = (int) $r->n; } }
	foreach ( $b as $x => $v ) {
		$act = count( array_filter( array_keys( $active30 ), function ( $u ) use ( $g, $x ) { return $g( $u ) === $x; } ) );
		$out( sprintf( '%s members viewed by 1+ people: %d (%s of active) | median viewers %s | 10+ viewers: %d', $x, count( $v ), $pct( count( $v ), $act ), $med( $v ), count( array_filter( $v, function ( $n ) { return $n >= 10; } ) ) ) );
	}
} );

/* 7. Contact intent: do matched people try to swap numbers? (counts only) */
$section( '7. CONTACT SHARING IN MESSAGES (counts only, last 60 days)', function () use ( $wpdb, $p, $g, $out, $pct ) {
	$mm = $p . 'bm_message_messages';
	$rows = $wpdb->get_results( "SELECT thread_id, sender_id, message FROM {$mm} WHERE date_sent >= UTC_TIMESTAMP() - INTERVAL 60 DAY ORDER BY id" );
	$threads = array(); $first = array(); $by = array(); $pos = array();
	foreach ( $rows as $r ) {
		$t = (int) $r->thread_id;
		$threads[ $t ] = ( $threads[ $t ] ?? 0 ) + 1;
		$txt = wp_strip_all_tags( (string) $r->message );
		$hit = preg_match( '/(?:\+?91[\s-]?)?[6-9]\d{4}[\s-]?\d{5}\b/', $txt ) || preg_match( '/whats\s?app|insta(gram)?\b|@gmail|@yahoo|telegram|call me|my number/i', $txt );
		if ( $hit && ! isset( $first[ $t ] ) ) {
			$first[ $t ] = 1;
			$x = $g( $r->sender_id ) ?: '?';
			$by[ $x ] = ( $by[ $x ] ?? 0 ) + 1;
			$pos[] = $threads[ $t ];
		}
	}
	sort( $pos );
	$out( sprintf( 'Conversations %d | where someone shared or asked for a number/WhatsApp/Instagram/email: %d (%s)', count( $threads ), count( $first ), $pct( count( $first ), count( $threads ) ) ) );
	foreach ( $by as $x => $n ) { $out( "   first raised by $x: $n" ); }
	if ( $pos ) { $out( '   median message number when it first comes up: ' . $pos[ (int) floor( ( count( $pos ) - 1 ) / 2 ) ] ); }
} );

/* 8. Photos: demand to see them. */
$section( '8. PHOTOS', function () use ( $wpdb, $p, $g, $G, $out, $pct ) {
	$priv = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'csm_photo_private' AND meta_value = '1'" ) ) );
	$ph   = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'csm_photos' AND meta_value NOT IN ('', 'a:0:{}')" ) ) );
	foreach ( array( 'M', 'F' ) as $x ) {
		$all = array_keys( array_filter( $G, function ( $v ) use ( $x ) { return $v === $x; } ) );
		$out( sprintf( '%s with photo %s | photo blurred %s', $x, $pct( count( array_intersect_key( array_flip( $all ), $ph ) ), count( $all ) ), $pct( count( array_intersect_key( array_flip( $all ), $priv ) ), count( $all ) ) ) );
	}
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}csm_photo_requests'" ) ) {
		foreach ( $wpdb->get_results( "SELECT requester_id r, status FROM {$p}csm_photo_requests WHERE created_at >= NOW() - INTERVAL 60 DAY" ) as $r ) {
			$k = ( $g( $r->r ) ?: '?' ) . ' ' . $r->status; $c[ $k ] = ( $c[ $k ] ?? 0 ) + 1;
		}
		$out( 'Photo requests last 60d (by requester gender, status): ' . ( empty( $c ) ? 'none' : implode( ', ', array_map( function ( $k, $v ) { return "$k $v"; }, array_keys( $c ), $c ) ) ) );
	}
} );

/* 9. Who pays: profile traits of paying vs free. */
$section( '9. WHO PAYS (traits of Premium vs free members)', function () use ( $wpdb, $p, $G, $prem, $out, $pct ) {
	$f = array( 'age' => \CAShaadi\Core\Config::FIELD_DOB, 'city' => \CAShaadi\Core\Config::FIELD_CITY, 'qual' => \CAShaadi\Core\Config::FIELD_QUALIFICATION );
	$vals = array();
	foreach ( $f as $k => $fid ) {
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$p}bp_xprofile_data WHERE field_id = %d", $fid ) ) as $r ) { $vals[ $k ][ (int) $r->user_id ] = (string) $r->value; }
	}
	foreach ( array( 'M', 'F' ) as $x ) {
		$b = array();
		foreach ( $G as $u => $gx ) {
			if ( $gx !== $x ) { continue; }
			$t = ! empty( $prem[ $u ] ) ? 'paid' : 'free';
			$dob = $vals['age'][ $u ] ?? ''; $age = $dob ? (int) floor( ( time() - strtotime( $dob ) ) / ( 365.25 * DAY_IN_SECONDS ) ) : 0;
			$ab  = $age < 18 ? 'unknown' : ( $age < 26 ? '<26' : ( $age < 29 ? '26-28' : ( $age < 32 ? '29-31' : ( $age < 35 ? '32-34' : '35+' ) ) ) );
			$b['age'][ $ab ][ $t ] = ( $b['age'][ $ab ][ $t ] ?? 0 ) + 1;
			$q = strtolower( trim( wp_strip_all_tags( $vals['qual'][ $u ] ?? '' ) ) ) ?: 'unknown';
			$b['qualification'][ $q ][ $t ] = ( $b['qualification'][ $q ][ $t ] ?? 0 ) + 1;
			$c = ucwords( strtolower( trim( $vals['city'][ $u ] ?? '' ) ) ) ?: 'Unknown';
			$b['city'][ $c ][ $t ] = ( $b['city'][ $c ][ $t ] ?? 0 ) + 1;
			$v = 'approved' === get_user_meta( $u, 'csm_av_status', true ) ? 'verified' : 'not verified';
			$b['verified'][ $v ][ $t ] = ( $b['verified'][ $v ][ $t ] ?? 0 ) + 1;
		}
		$out( "-- $x (share of each group that is Premium) --" );
		foreach ( $b as $dim => $groups ) {
			uasort( $groups, function ( $a, $c ) { return array_sum( $c ) <=> array_sum( $a ); } );
			$parts = array();
			foreach ( array_slice( $groups, 0, 'city' === $dim ? 8 : 7, true ) as $k => $d ) {
				$n = array_sum( $d ); if ( $n < 15 ) { continue; }
				$parts[] = $k . ' ' . $pct( $d['paid'] ?? 0, $n ) . ' of ' . $n;
			}
			$out( sprintf( '   %-14s %s', $dim, implode( ' | ', $parts ) ) );
		}
	}
} );

/* 10. Premium vs free behaviour and why people leave. */
$section( '10. ACTIVITY OF PREMIUM vs FREE (last 30 days, per active member)', function () use ( $wpdb, $p, $g, $prem, $out ) {
	$rows = $wpdb->get_results( "SELECT user_id, SUM(likes_sent) ls, SUM(likes_recv) lr, SUM(matches) m, SUM(msgs_sent) ms, SUM(views_recv) vr, SUM(active) d FROM {$p}csm_member_day WHERE day >= CURDATE() - INTERVAL 30 DAY GROUP BY user_id" );
	$b = array();
	foreach ( $rows as $r ) {
		$x = $g( $r->user_id ); if ( '' === $x || ! (int) $r->d ) { continue; }
		$k = $x . ( ! empty( $prem[ (int) $r->user_id ] ) ? ' premium' : ' free' );
		foreach ( array( 'd', 'ls', 'lr', 'm', 'ms', 'vr' ) as $f ) { $b[ $k ][ $f ] = ( $b[ $k ][ $f ] ?? 0 ) + (int) $r->$f; }
		$b[ $k ]['n'] = ( $b[ $k ]['n'] ?? 0 ) + 1;
	}
	ksort( $b );
	foreach ( $b as $k => $d ) {
		$n = max( 1, $d['n'] );
		$out( sprintf( '%-10s members %4d | active days %.1f | requests sent %.1f | requests got %.1f | matches %.2f | messages sent %.1f | views got %.1f', $k, $d['n'], $d['d'] / $n, $d['ls'] / $n, $d['lr'] / $n, $d['m'] / $n, $d['ms'] / $n, $d['vr'] / $n ) );
	}
	$del = $wpdb->get_results( "SELECT metadata FROM {$p}csm_event_log WHERE event_type = 'account_deleted'" );
	$c = array();
	foreach ( $del as $r ) {
		$m = json_decode( (string) $r->metadata, true ) ?: array();
		$x = 'Female' === ( $m['gender'] ?? '' ) ? 'F' : ( 'Male' === ( $m['gender'] ?? '' ) ? 'M' : '?' );
		$c[ $x ]['n'] = ( $c[ $x ]['n'] ?? 0 ) + 1;
		$c[ $x ]['nomatch'] = ( $c[ $x ]['nomatch'] ?? 0 ) + ( empty( $m['stage']['matches'] ) ? 1 : 0 );
		$c[ $x ]['prem'] = ( $c[ $x ]['prem'] ?? 0 ) + ( ! empty( $m['premium'] ) ? 1 : 0 );
	}
	foreach ( $c as $x => $d ) { $out( sprintf( 'Deleted accounts %s: %d | with no match ever: %d | were Premium: %d', $x, $d['n'], $d['nomatch'], $d['prem'] ) ); }
} );

$out( '' );
$out( 'Done.' );
