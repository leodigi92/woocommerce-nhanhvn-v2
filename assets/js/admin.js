jQuery(document).ready(function($) {
    $('#sync-products-btn').on('click', function() {
        $.ajax({
            url: nhanhvn_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'nhanhvn_sync_products',
                nonce: nhanhvn_vars.nonce
            },
            success: function(response) {
                alert(response.data);
            }
        });
    });
// Copy Redirect URL
    $('.copy-redirect-url').on('click', function() {
        var targetId = $(this).data('target');
        var input = document.getElementById(targetId);
        if (input) {
            input.select();
            document.execCommand('copy');
            alert('Đã sao chép Redirect URL vào clipboard!');
        } else {
            alert('Không tìm thấy input để sao chép!');
        }
    });
    // Copy Webhook URL
    $('.copy-webhook-url').on('click', function() {
        var targetId = $(this).data('target');
        var input = document.getElementById(targetId);
        if (input) {
            input.select();
            document.execCommand('copy');
            alert('Đã sao chép URL vào clipboard!');
        } else {
            alert('Không tìm thấy input để sao chép!');
        }
    });

    // Generate new Webhook Token
    $('.generate-webhook-token').on('click', function() {
        if (confirm('Bạn có chắc muốn tạo token mới? Token cũ sẽ không còn hoạt động.')) {
            $.post(ajaxurl, {
                action: 'generate_webhook_token',
                nonce: nhanhvn_admin.nonce
            }, function(response) {
                if (response.success) {
                    $('#nhanhvn_webhook_token').val(response.data.token);
                    alert('Token mới đã được tạo!');
                } else {
                    alert('Lỗi khi tạo token: ' + (response.data || 'Không rõ lỗi'));
                }
            });
        }
    });

    // Kiểm tra kết nối API
    $('#check-connection').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'check_api_connection',
                nonce: nhanhvn_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                } else {
                    alert('Lỗi: ' + response.data);
                }
            }
        });
    });

    // Đồng bộ sản phẩm (button #start-sync)
    $('#start-sync').on('click', function() {
        if (!confirm(nhanhvn_admin.messages.confirm_sync)) {
            return;
        }

        var $progress = $('.nhanhvn-sync-progress');
        var $log = $('.nhanhvn-sync-log .log-content');
        $progress.show();
        $log.empty();

        function syncProducts(page = 1) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sync_nhanh_products',
                    nonce: nhanhvn_admin.nonce,
                    page: page,
                    sync_images: $('[name="sync_images"]').is(':checked'),
                    sync_categories: $('[name="sync_categories"]').is(':checked'),
                    sync_inventory: $('[name="sync_inventory"]').is(':checked')
                },
                success: function(response) {
                    if (response.success) {
                        updateProgress(response.data);
                        if (response.data.more) {
                            syncProducts(page + 1);
                        } else {
                            completeSync();
                        }
                    } else {
                        handleError(response.data);
                    }
                },
                error: function() {
                    handleError('Không thể kết nối đến server!');
                }
            });
        }

        function updateProgress(data) {
            $('.progress-bar-fill').css('width', data.progress + '%');
            $('.current-item').text(data.current);
            $('.total-items').text(data.total);
            $log.prepend('<p>' + data.message + '</p>');
        }

        function completeSync() {
            $progress.hide();
            alert(nhanhvn_admin.messages.sync_success);
        }

        function handleError(message) {
            $progress.hide();
            alert(nhanhvn_admin.messages.sync_error + ': ' + message);
        }

        syncProducts();
    });

    // Lọc đơn hàng
    $('#filter-orders').on('click', function() {
        loadOrders(1);
    });

    function loadOrders(page) {
        console.log('Loading orders for page ' + page);
    }

});
