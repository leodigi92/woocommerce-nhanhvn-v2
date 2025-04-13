<?php
if (!defined('ABSPATH'))
  exit;
?>

<div class="wrap nhanhvn-wrap">
  <h1>Quản lý đơn hàng Nhanh.vn</h1>

  <div class="nhanhvn-order-filters">
    <select name="order_status">
      <option value="">Tất cả trạng thái</option>
      <option value="pending">Chờ xử lý</option>
      <option value="processing">Đang xử lý</option>
      <option value="completed">Hoàn thành</option>
      <option value="cancelled">Đã hủy</option>
    </select>

    <input type="date" name="date_from" placeholder="Từ ngày">
    <input type="date" name="date_to" placeholder="Đến ngày">

    <button type="button" class="button" id="filter-orders">Lọc</button>
  </div>

  <table class="wp-list-table widefat fixed striped">
    <thead>
      <tr>
        <th>Mã đơn hàng</th>
        <th>Ngày tạo</th>
        <th>Khách hàng</th>
        <th>Tổng tiền</th>
        <th>Trạng thái</th>
        <th>Thao tác</th>
      </tr>
    </thead>
    <tbody id="orders-list">
      <!-- Dữ liệu đơn hàng sẽ được load bằng AJAX -->
    </tbody>
  </table>

  <div class="nhanhvn-pagination">
    <!-- Phân trang sẽ được thêm bằng JavaScript -->
  </div>
</div>
