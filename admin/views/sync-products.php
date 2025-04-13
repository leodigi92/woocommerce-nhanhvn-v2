<?php
if (!defined('ABSPATH'))
  exit;
?>

<div class="wrap nhanhvn-wrap">
  <h1>Đồng bộ sản phẩm</h1>

  <div class="nhanhvn-sync-options">
    <div class="nhanhvn-form-group">
      <label>
        <input type="checkbox" name="sync_images" value="1" checked>
        Đồng bộ hình ảnh
      </label>
    </div>

    <div class="nhanhvn-form-group">
      <label>
        <input type="checkbox" name="sync_categories" value="1" checked>
        Đồng bộ danh mục
      </label>
    </div>

    <div class="nhanhvn-form-group">
      <label>
        <input type="checkbox" name="sync_inventory" value="1" checked>
        Đồng bộ tồn kho
      </label>
    </div>

    <button type="button" class="button button-primary" id="start-sync">
      Bắt đầu đồng bộ
    </button>
  </div>

  <div class="nhanhvn-sync-progress" style="display: none;">
    <div class="progress-bar">
      <div class="progress-bar-fill"></div>
    </div>
    <div class="progress-status">
      Đang đồng bộ: <span class="current-item">0</span>/<span class="total-items">0</span>
    </div>
  </div>

  <div class="nhanhvn-sync-log">
    <h3>Nhật ký đồng bộ</h3>
    <div class="log-content"></div>
  </div>
</div>
