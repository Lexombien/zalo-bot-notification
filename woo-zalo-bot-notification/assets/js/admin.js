jQuery(document).ready(function ($) {

    // Auto check webhook status
    function checkWebhookStatus() {
        var botToken = $('#bot_token').val();
        if (!botToken) {
            $('#webhook-badge').text('⚠️ Missing Token').css('background', '#f0ad4e').css('color', '#fff');
            return;
        }

        $.ajax({
            url: wzbAdmin.ajaxUrl, type: 'POST',
            data: { action: 'wzb_check_webhook_info', bot_token: botToken, nonce: wzbAdmin.nonce },
            success: function (res) {
                if (res.success && res.data.data && res.data.data.url) {
                    $('#webhook-badge').text('✅ Đã kết nối').css('background', '#28a745').css('color', '#fff');
                } else {
                    $('#webhook-badge').text('❌ Chưa kết nối').css('background', '#dc3545').css('color', '#fff');
                }
            },
            error: function () {
                $('#webhook-badge').hide();
            }
        });
    }

    // Run validation on load
    if ($('#webhook-badge').length) {
        checkWebhookStatus();
    }

    // Copy Webhook URL
    $('#copy-webhook-url').on('click', function () {
        var copyText = document.getElementById("webhook_url_input");
        copyText.select();
        copyText.setSelectionRange(0, 99999); /* For mobile devices */
        document.execCommand("copy");

        var $btn = $(this);
        var originalText = $btn.text();
        $btn.text('✅ Copied!');
        setTimeout(function () {
            $btn.text(originalText);
        }, 2000);
    });

    // Check Chat ID
    $('#find-chat-id').on('click', function () {
        var $btn = $(this);
        var botToken = $('#bot_token').val();

        if (!botToken) {
            alert('Vui lòng nhập Bot Token trước');
            $('#bot_token').focus();
            return;
        }

        $btn.prop('disabled', true).text('⏳ Đang tìm...');

        $.ajax({
            url: wzbAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wzb_get_chat_id',
                bot_token: botToken,
                nonce: wzbAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    var newId = response.data.chat_id;
                    var currentVal = $('#chat_id').val();

                    if (currentVal.indexOf(newId) !== -1) {
                        alert('✅ Tìm thấy ID: ' + newId + '\nID này đã có trong danh sách rồi!');
                    } else if (currentVal.trim() !== '') {
                        if (confirm('✅ Tìm thấy ID mới: ' + newId + '\n\nBạn có muốn THÊM người này vào danh sách nhận tin không?')) {
                            $('#chat_id').val(currentVal + ', ' + newId);
                            alert('Đã thêm thành công! Hãy bấm LƯU CÀI ĐẶT.');
                        }
                    } else {
                        $('#chat_id').val(newId);
                        alert('✅ ' + response.data.message);
                    }
                } else {
                    alert('⚠️ ' + response.data.message);
                }
            },
            error: function () {
                alert('Lỗi kết nối server');
            },
            complete: function () {
                $btn.prop('disabled', false).text('🔎 Tìm Chat ID (Auto)');
            }
        });
    });

    // Regenerate Secret Token
    $('#regenerate-secret').on('click', function () {
        var $btn = $(this);

        if (!confirm('Bạn có chắc chắn muốn tạo Token bí mật mới không? Nếu token cũ đã được dùng trong webhook, bạn cần cập nhật lại webhook.')) {
            return;
        }

        $btn.prop('disabled', true).text('⏳ Đang tạo...');

        $.ajax({
            url: wzbAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wzb_regenerate_secret',
                nonce: wzbAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#secret_token').val(response.data.secret_token);
                } else {
                    alert('Lỗi: ' + response.data.message);
                }
            },
            error: function () {
                alert('Lỗi kết nối server');
            },
            complete: function () {
                $btn.prop('disabled', false).text('🔄 Tạo mới');
            }
        });
    });

    // Test Connection
    $('#test-connection').on('click', function () {
        var $btn = $(this);
        var $status = $('#connection-status');
        var botToken = $('#bot_token').val();
        var chatId = $('#chat_id').val();

        if (!botToken || !chatId) {
            alert('Vui lòng nhập Bot Token và Chat ID');
            return;
        }

        $btn.prop('disabled', true).text('⏳ Đang lưu & kiểm tra...');
        $status.text('').removeClass('success error');

        $.ajax({
            url: wzbAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wzb_test_connection',
                nonce: wzbAdmin.nonce,
                bot_token: botToken,
                chat_id: chatId,
                save_first: true // Flag to tell PHP to save settings
            },
            success: function (response) {
                if (response.success) {
                    $status.text(response.data.message).addClass('success').css('color', 'green');
                } else {
                    $status.text(response.data.message).addClass('error').css('color', 'red');
                }
            },
            error: function () {
                $status.text('Lỗi kết nối server').addClass('error').css('color', 'red');
            },
            complete: function () {
                $btn.prop('disabled', false).text('🧪 Test kết nối');
            }
        });
    });

    // Setup Webhook
    $('#setup-webhook').on('click', function () {
        var $btn = $(this);
        var $status = $('#connection-status');
        var botToken = $('#bot_token').val();

        if (!confirm('Bạn có chắc chắn muốn Lưu cài đặt & Thiết lập Webhook này cho Bot không?')) {
            return;
        }

        $btn.prop('disabled', true).text('⏳ Đang lưu & thiết lập...');

        $.ajax({
            url: wzbAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wzb_setup_webhook',
                nonce: wzbAdmin.nonce,
                bot_token: botToken,
                save_first: true // Flag to tell PHP to save settings
            },
            success: function (response) {
                if (response.success) {
                    $status.text(response.data.message).addClass('success').css('color', 'green');
                    // Reload page after success to show updated UI/Status
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    $status.text(response.data.message).addClass('error').css('color', 'red');
                }
            },
            error: function () {
                $status.text('Lỗi kết nối server').addClass('error').css('color', 'red');
            },
            complete: function () {
                $btn.prop('disabled', false).text('🔗 Thiết lập Webhook');
            }
        });
    });

    // Check Webhook Info
    $('#check-webhook-info').on('click', function () {
        var $btn = $(this);
        var botToken = $('#bot_token').val();

        if (!botToken) { alert('Nhập Bot Token'); return; }

        $btn.prop('disabled', true).text('⏳ Checking...');
        $.ajax({
            url: wzbAdmin.ajaxUrl, type: 'POST',
            data: { action: 'wzb_check_webhook_info', bot_token: botToken, nonce: wzbAdmin.nonce },
            success: function (res) {
                if (res.success) {
                    alert(JSON.stringify(res.data.data, null, 2));
                } else { alert('Error: ' + res.data.message); }
            },
            complete: function () { $btn.prop('disabled', false).text('ℹ️ Check Info'); }
        });
    });

    // Delete Webhook
    $('#delete-webhook').on('click', function () {
        if (!confirm('Bạn có chắc muốn xóa Webhook? Điều này sẽ giúp bạn dùng được tính năng Tìm Chat ID nhưng Bot sẽ dừng nhận thông báo cho đến khi bạn Set Webhook lại.')) return;

        var $btn = $(this);
        var botToken = $('#bot_token').val();

        if (!botToken) { alert('Nhập Bot Token'); return; }

        $btn.prop('disabled', true).text('⏳ Deleting...');
        $.ajax({
            url: wzbAdmin.ajaxUrl, type: 'POST',
            data: { action: 'wzb_delete_webhook', bot_token: botToken, nonce: wzbAdmin.nonce },
            success: function (res) {
                if (res.success) {
                    alert('✅ ' + res.data.message);
                    // Reload to update status badge
                    setTimeout(function () { location.reload(); }, 1000);
                } else { alert('Error: ' + res.data.message); }
            },
            complete: function () { $btn.prop('disabled', false).text('🗑️ Xóa Webhook'); }
        });
    });

    // Insert Template Var
    $('.wzb-var-item code').on('click', function () {
        var textToInsert = $(this).text();
        var textarea = document.getElementById("message_template");

        // Insert text at cursor position
        if (document.selection) {
            textarea.focus();
            var sel = document.selection.createRange();
            sel.text = textToInsert;
        } else if (textarea.selectionStart || textarea.selectionStart == '0') {
            var startPos = textarea.selectionStart;
            var endPos = textarea.selectionEnd;
            textarea.value = textarea.value.substring(0, startPos) +
                textToInsert +
                textarea.value.substring(endPos, textarea.value.length);
            textarea.focus();
            textarea.selectionStart = startPos + textToInsert.length;
            textarea.selectionEnd = startPos + textToInsert.length;
        } else {
            textarea.value += textToInsert;
            textarea.focus();
        }
    });

    // Add Custom Field
    $('#add-custom-field').on('click', function () {
        var html = '<div class="wzb-custom-field">' +
            '<input type="text" name="wzb_settings[custom_fields][]" value="" placeholder="Nhập Meta Key">' +
            '<code class="wzb-token-preview" style="background:#fff; border:1px solid #ddd; padding:2px 5px; margin:0 5px;" title="Copy tag này">{key}</code>' +
            '<button type="button" class="button remove-custom-field">❌</button>' +
            '</div>';
        $('#custom-fields-container').append(html);
    });

    // Live update token preview
    $(document).on('input', '.wzb-custom-field input', function () {
        var val = $(this).val();
        if (!val) val = 'key';
        $(this).siblings('.wzb-token-preview').text('{' + val + '}');
    });

    // Lookup Order Meta
    $('#lookup-order-meta').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('⏳ Đang tải...');

        $.ajax({
            url: wzbAdmin.ajaxUrl,
            type: 'POST',
            data: { action: 'wzb_get_sample_order_meta', nonce: wzbAdmin.nonce },
            success: function (res) {
                if (res.success) {
                    showMetaModal(res.data);
                } else {
                    alert(res.data.message);
                }
            },
            complete: function () {
                $btn.prop('disabled', false).text('🔍 Tra cứu Meta (Mới nhất)');
            }
        });
    });

    function showMetaModal(data) {
        // Remove existing modal
        $('#wzb-meta-modal').remove();

        var rows = '';
        data.meta.forEach(function (item) {
            rows += `<tr class="wzb-meta-row">
                <td><button type="button" class="button button-small wzb-add-meta-btn" data-key="${item.key}">➕ Dùng</button></td>
                <td class="wzb-meta-key" style="font-weight:bold; color:#0073aa;">${item.key}</td>
                <td style="word-break:break-all; font-size:12px;">${item.value}</td>
            </tr>`;
        });

        var modalHtml = `
        <div id="wzb-meta-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:99999; display:flex; justify-content:center; align-items:center;">
            <div style="background:#fff; width:80%; max-width:800px; max-height:90vh; overflow:hidden; border-radius:8px; display:flex; flex-direction:column; box-shadow:0 5px 15px rgba(0,0,0,0.3);">
                <div style="padding:15px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center; background:#f0f0f1;">
                    <h3 style="margin:0;">📋 Meta Data - Đơn hàng #${data.order_number}</h3>
                    <button type="button" class="button" onclick="jQuery('#wzb-meta-modal').remove()">❌ Đóng</button>
                </div>
                <div style="padding:10px; border-bottom:1px solid #eee;">
                    <input type="text" id="wzb-meta-search" placeholder="🔍 Lọc theo tên Key hoặc Giá trị..." style="width:100%; padding:8px;">
                </div>
                <div style="overflow-y:auto; padding:0;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:70px;">Action</th>
                                <th style="width:250px;">Meta Key</th>
                                <th>Value (Giá trị mẫu)</th>
                            </tr>
                        </thead>
                        <tbody id="wzb-meta-tbody">
                            ${rows}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>`;

        $('body').append(modalHtml);

        // Filter logic
        $('#wzb-meta-search').on('keyup', function () {
            var value = $(this).val().toLowerCase();
            $("#wzb-meta-tbody tr").filter(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });

        // Add button logic
        $('.wzb-add-meta-btn').on('click', function () {
            var key = $(this).data('key');
            var html = '<div class="wzb-custom-field">' +
                '<input type="text" name="wzb_settings[custom_fields][]" value="' + key + '" placeholder="Nhập Meta Key">' +
                '<code class="wzb-token-preview" style="background:#fff; border:1px solid #ddd; padding:2px 5px; margin:0 5px;" title="Copy tag này">{' + key + '}</code>' +
                '<button type="button" class="button remove-custom-field">❌</button>' +
                '</div>';
            $('#custom-fields-container').append(html);
            // Highlight effect
            var $newField = $('#custom-fields-container .wzb-custom-field:last-child');
            $newField.css('background', '#e6ffee');
            setTimeout(function () { $newField.css('background', 'transparent'); }, 1000);
        });
    }

    // Remove Custom Field
    $(document).on('click', '.remove-custom-field', function () {
        $(this).parent().remove();
    });

});
