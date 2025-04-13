<?php
if (!defined('ABSPATH'))
  exit;
?>

<div class="wrap nhanhvn-wrap">
  <h1>Nhật ký hoạt động</h1>

  <div class="nhanhvn-log-filters">
    <select name="log_type">
      <option value="">Tất cả loại</option>
      <option value="product_sync">Đồng bộ sản phẩm</option>
      <option value="order_sync">Đồng bộ đơn hàng</option>
      <option value="inventory_sync">Đồng bộ tồn kho</option>
      <option value="webhook">Webhook</option>
    </select>

    <select name="log_status">
      <option value="">Tất cả trạng thái</option>
      <option value="success">Thành công</option>
      <option value="error">Lỗi</option>
    </select>

    <button type="button" class="button" id="filter-logs">Lọc</button>
  </div>

  <table class="wp-list-table widefat fixed striped">
    <thead>
      <tr>
        <th>Thời gian</th>
        <th>Loại</th>
        <th>Trạng thái</th>
        <th>Nội dung</th>
      </tr>
    </thead>
    <tbody id="logs-list">
      <!-- Dữ liệu log sẽ được load bằng AJAX -->
    </tbody>
  </table>

  <div class="nhanhvn-pagination">
    <!-- Phân trang sẽ được thêm bằng JavaScript -->
  </div>
</div>
