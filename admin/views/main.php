<?php
if (!defined('ABSPATH'))
  exit;
?>

<div class="wrap nhanhvn-wrap">
  <div class="nhanhvn-header">
    <h1>Nhanh.vn Integration Dashboard</h1>
    <div class="nhanhvn-header-actions">
      <button type="button" class="button button-primary" id="check-connection">
        Kiểm tra kết nối
      </button>
    </div>
  </div>

  <div class="nhanhvn-dashboard-widgets">
    <div class="nhanhvn-widget">
      <h3>Thống kê đồng bộ</h3>
      <div class="nhanhvn-widget-content">
        <?php
        global $wpdb;
        $stats = $wpdb->get_results("
                    SELECT type, COUNT(*) as count
                    FROM {$wpdb->prefix}nhanhvn_sync_log
                    GROUP BY type
                ");
        ?>
        <ul>
          <?php foreach ($stats as $stat): ?>
            <li><?php echo $stat->type; ?>: <?php echo $stat->count; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="nhanhvn-widget">
      <h3>Hoạt động gần đây</h3>
      <div class="nhanhvn-widget-content">
        <?php
        $recent_logs = $wpdb->get_results("
                    SELECT * FROM {$wpdb->prefix}nhanhvn_sync_log
                    ORDER BY created_at DESC LIMIT 5
                ");
        ?>
        <ul>
          <?php foreach ($recent_logs as $log): ?>
            <li>
              <?php echo $log->type; ?> -
              <?php echo $log->status; ?> -
              <?php echo human_time_diff(strtotime($log->created_at), current_time('timestamp')); ?> trước
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
