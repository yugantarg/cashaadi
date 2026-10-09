-- Platform health check (read-only). Run from the WordPress root:
--   wp db query < wp-content/plugins/cashaadi-ui/tools/health.sql
-- Days are IST. Tables in UTC (users, bp_friends, Better Messages) are shifted +5:30.

SELECT '1. LAST 14 DAYS' AS section;
SELECT d.day,
 (SELECT COUNT(*) FROM wp_users u WHERE DATE(DATE_ADD(u.user_registered, INTERVAL 330 MINUTE)) = d.day) AS new_users,
 (SELECT COUNT(*) FROM wp_csm_active_days a WHERE a.day = d.day) AS active,
 (SELECT COUNT(*) FROM wp_csm_seen s WHERE DATE(s.first_seen_at) = d.day) AS profiles_shown,
 (SELECT COUNT(*) FROM wp_csm_seen s WHERE s.action = 'liked' AND DATE(s.acted_at) = d.day) AS likes,
 (SELECT COUNT(*) FROM wp_csm_seen s WHERE s.action = 'passed' AND DATE(s.acted_at) = d.day) AS passes,
 (SELECT COUNT(*) FROM wp_bp_friends f WHERE DATE(DATE_ADD(f.date_created, INTERVAL 330 MINUTE)) = d.day) AS requests,
 (SELECT COUNT(*) FROM wp_csm_event_log e WHERE e.event_type = 'match_made' AND DATE(e.created_at) = d.day) AS matches,
 (SELECT COUNT(*) FROM wp_bm_message_messages m WHERE DATE(DATE_ADD(m.date_sent, INTERVAL 330 MINUTE)) = d.day) AS messages,
 (SELECT COUNT(DISTINCT m.sender_id) FROM wp_bm_message_messages m WHERE DATE(DATE_ADD(m.date_sent, INTERVAL 330 MINUTE)) = d.day) AS senders
FROM (SELECT DISTINCT day FROM wp_csm_active_days WHERE day >= CURDATE() - INTERVAL 14 DAY) d
ORDER BY d.day;

SELECT '2. REQUESTS' AS section;
SELECT SUM(is_confirmed = 1) AS accepted_all_time,
       SUM(is_confirmed = 0) AS pending_now,
       SUM(is_confirmed = 0 AND date_created < UTC_TIMESTAMP() - INTERVAL 7 DAY) AS pending_over_7_days,
       SUM(date_created > UTC_TIMESTAMP() - INTERVAL 30 DAY) AS sent_last_30d,
       SUM(is_confirmed = 1 AND date_created > UTC_TIMESTAMP() - INTERVAL 30 DAY) AS of_which_accepted
FROM wp_bp_friends;

SELECT '3. DO MATCHES TALK? (matches in last 30 days)' AS section;
SELECT COUNT(*) AS matches,
       SUM(EXISTS (SELECT 1 FROM wp_bm_message_recipients r1
                   JOIN wp_bm_message_recipients r2 ON r2.thread_id = r1.thread_id
                   JOIN wp_bm_message_messages m ON m.thread_id = r1.thread_id
                   WHERE r1.user_id = e.actor_id AND r2.user_id = e.target_id)) AS with_any_message
FROM wp_csm_event_log e
WHERE e.event_type = 'match_made' AND e.created_at > NOW() - INTERVAL 30 DAY;

SELECT '4. DO CONVERSATIONS GET REPLIES? (threads started in last 30 days)' AS section;
SELECT COUNT(*) AS threads, SUM(senders >= 2) AS got_a_reply, SUM(msgs >= 10) AS ten_plus_messages
FROM (SELECT thread_id, COUNT(DISTINCT sender_id) senders, COUNT(*) msgs, MIN(date_sent) started
      FROM wp_bm_message_messages GROUP BY thread_id) t
WHERE t.started > UTC_TIMESTAMP() - INTERVAL 30 DAY;

SELECT '5. LIKES RECEIVED BY GENDER (last 14 days)' AS section;
SELECT g.value AS gender, COUNT(*) AS likes_received, COUNT(DISTINCT s.profile_id) AS members_liked
FROM wp_csm_seen s JOIN wp_bp_xprofile_data g ON g.user_id = s.profile_id AND g.field_id = 299
WHERE s.action = 'liked' AND s.acted_at > NOW() - INTERVAL 14 DAY
GROUP BY g.value;

SELECT '6. ACTIVE MEMBERS TODAY WHO WERE SHOWN NO PROFILES' AS section;
SELECT COUNT(*) AS active_today,
       SUM(NOT EXISTS (SELECT 1 FROM wp_csm_seen s WHERE s.viewer_id = a.user_id AND s.last_seen_at >= CURDATE() - INTERVAL 7 DAY)) AS shown_nothing_this_week
FROM wp_csm_active_days a WHERE a.day = CURDATE();

SELECT '7. EMAILS (last 7 days)' AS section;
SELECT status, COUNT(*) AS n, SUM(opened_at IS NOT NULL) AS opened
FROM wp_csm_email_queue WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY status;
SELECT option_value AS last_mail_error FROM wp_options WHERE option_name = 'csm_mail_error_last';

SELECT '8. SIGN-UP CODES (last 3 days)' AS section;
SELECT step, COUNT(DISTINCT pkey) AS people FROM wp_csm_funnel
WHERE created_at > UTC_TIMESTAMP() - INTERVAL 3 DAY GROUP BY step ORDER BY people DESC;
